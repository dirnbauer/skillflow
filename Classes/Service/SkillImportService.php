<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Service;

use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use Webconsulting\Skillflow\Domain\ImportResult;
use Webconsulting\Skillflow\Domain\ParsedSkill;
use Webconsulting\Skillflow\Event\AfterSkillsSyncedEvent;
use Webconsulting\Skillflow\Support\Typed;

/**
 * Imports skills from a local folder containing skill directories,
 * each with a SKILL.md file (Anthropic skill structure). Supporting
 * files next to the SKILL.md (references/, scripts/, templates, ...)
 * are imported as attachment records (tx_skillflow_file) so the
 * runners can hand them to the model - progressive disclosure works
 * even though the original folder is gone after import.
 *
 * Existing skills are matched by identifier + source, updated in place
 * (the uid is kept stable) and only touched when their content changed.
 */
final class SkillImportService
{
    /**
     * Binary / non-text extensions that are never imported as supporting
     * files. Everything else that is valid UTF-8 and within the size limit
     * IS imported, so code examples in any text format (neon, typoscript,
     * xlf, scss, tsx, Makefile, Dockerfile, ...) survive the import and are
     * available to the runners afterwards.
     */
    private const BINARY_FILE_EXTENSIONS = [
        'png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp', 'ico', 'icns', 'svgz', 'tif', 'tiff',
        'pdf', 'zip', 'gz', 'tgz', 'bz2', 'xz', 'tar', 'rar', '7z', 'jar', 'war',
        'woff', 'woff2', 'ttf', 'otf', 'eot',
        'mp3', 'mp4', 'm4a', 'wav', 'ogg', 'webm', 'mov', 'avi', 'mkv', 'flac',
        'exe', 'dll', 'so', 'dylib', 'bin', 'class', 'phar',
        'sqlite', 'db', 'mo', 'keystore', 'p12', 'pfx',
    ];

    /**
     * Directory names skipped wholesale (VCS metadata, dependency and build
     * output) so a local skill checkout does not drag thousands of vendored
     * files into the database.
     */
    private const IGNORED_DIRECTORIES = [
        '.git', '.svn', '.hg', 'node_modules', 'vendor', '.Build',
        '.idea', '.vscode', '__pycache__', '.pytest_cache',
    ];

    private const MAX_FILE_SIZE = 524288; // 512 KB per supporting file

    public function __construct(
        private readonly SkillParser $skillParser,
        private readonly ConnectionPool $connectionPool,
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function getConfiguredFolder(): string
    {
        try {
            $conf = Typed::stringKeyedArray($this->extensionConfiguration->get('skillflow'));
        } catch (\Throwable) {
            $conf = [];
        }
        $folder = trim(Typed::string($conf['skillsFolder'] ?? 'skills'), '/');
        return Environment::getProjectPath() . '/' . ($folder !== '' ? $folder : 'skills');
    }

    /**
     * pid of the SysFolder imported skills are stored in. 0 keeps them on
     * the global root level (legacy behaviour); a real SysFolder pid inside
     * a Solr site makes the skill records indexable.
     */
    private function getConfiguredStoragePid(): int
    {
        try {
            $conf = Typed::stringKeyedArray($this->extensionConfiguration->get('skillflow'));
        } catch (\Throwable) {
            $conf = [];
        }
        return Typed::int($conf['storagePid'] ?? 0);
    }

    /**
     * Guards against symlinks inside a skill folder that point outside the
     * project: both directory iterators follow symlinks, and the top-level
     * containment check cannot catch a symlink whose in-tree path stays clean.
     * Every resolved file must live within the project path.
     */
    private function isInsideProject(string $path): bool
    {
        $real = realpath($path);
        return $real !== false && str_starts_with($real, Environment::getProjectPath() . '/');
    }

    public function importFromConfiguredFolder(): ImportResult
    {
        return $this->importFromPath($this->getConfiguredFolder(), 'folder', 0);
    }

    public function importFromPath(string $absolutePath, string $sourceType, int $repositoryUid): ImportResult
    {
        $result = new ImportResult();
        $realPath = realpath($absolutePath);
        if ($realPath === false || !is_dir($realPath)) {
            $result->errors[] = sprintf('Folder "%s" does not exist', $absolutePath);
            return $result;
        }
        // Never read outside the project (covers var/transient extraction dirs as well)
        if (!str_starts_with($realPath . '/', Environment::getProjectPath() . '/')) {
            $result->errors[] = sprintf('Folder "%s" is outside the project path and was not imported', $absolutePath);
            return $result;
        }

        $syncedSkills = [];
        foreach ($this->findSkillFiles($realPath) as $skillFile) {
            $relativePath = ltrim(substr($skillFile, strlen($realPath)), '/');
            try {
                $parsed = $this->skillParser->parse((string)file_get_contents($skillFile), $relativePath);
                [$status, $skillUid] = $this->upsert($parsed, $sourceType, $repositoryUid, $relativePath);
                $result->{$status}++;
                $this->syncSupportingFiles($skillUid, dirname($skillFile), $result);
                $syncedSkills[] = [
                    'uid' => $skillUid,
                    'identifier' => $parsed->identifier,
                    'status' => $status,
                    'contentHash' => $parsed->contentHash(),
                ];
            } catch (\Throwable $e) {
                $result->errors[] = $relativePath . ': ' . $e->getMessage();
            }
        }
        if ($result->created + $result->updated + $result->unchanged === 0 && $result->errors === []) {
            $result->errors[] = sprintf('No SKILL.md files found below "%s"', $absolutePath);
        }
        $this->eventDispatcher->dispatch(new AfterSkillsSyncedEvent($sourceType, $repositoryUid, $result, $syncedSkills));
        return $result;
    }

    /**
     * Persists a single already-parsed skill through the shared upsert
     * pipeline (content_hash / last_synced / storagePid migration all kept
     * intact). Lets other importers — e.g. RuleImportService, which reads its
     * own files from outside the project path — reuse the exact same DB write
     * path without duplicating the upsert logic.
     *
     * @return 'created'|'updated'|'unchanged'
     */
    public function importParsedSkill(ParsedSkill $skill, string $sourceType, int $repositoryUid, string $relativePath): string
    {
        [$status] = $this->upsert($skill, $sourceType, $repositoryUid, $relativePath);
        return $status;
    }

    /**
     * @return string[] absolute paths of SKILL.md files (max depth 4)
     */
    private function findSkillFiles(string $directory): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS)
        );
        $iterator->setMaxDepth(4);
        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getFilename() === 'SKILL.md' && $this->isInsideProject($file->getPathname())) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);
        return $files;
    }

    /**
     * @return array{0: 'created'|'updated'|'unchanged', 1: int} status and skill uid
     */
    private function upsert(ParsedSkill $skill, string $sourceType, int $repositoryUid, string $relativePath): array
    {
        $connection = $this->connectionPool->getConnectionForTable('tx_skillflow_skill');
        $targetPid = $this->getConfiguredStoragePid();
        $existing = $connection->select(
            ['uid', 'pid', 'content_hash'],
            'tx_skillflow_skill',
            [
                'identifier' => $skill->identifier,
                'source_type' => $sourceType,
                'repository' => $repositoryUid,
                'deleted' => 0,
            ]
        )->fetchAssociative();

        $now = time();
        $fields = [
            'title' => $skill->name,
            'description' => $skill->description,
            'body' => $skill->body,
            'allowed_tools' => $skill->allowedTools,
            'metadata' => json_encode($skill->metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
            'relative_path' => $relativePath,
            'content_hash' => $skill->contentHash(),
            'pid' => $targetPid,
            'last_synced' => $now,
            'tstamp' => $now,
        ];

        if ($existing !== false) {
            $uid = Typed::int($existing['uid']);
            if (Typed::string($existing['content_hash']) === $skill->contentHash()) {
                // Content is unchanged, but still migrate the record when the
                // configured storage folder (storagePid) changed — so skills
                // imported before storagePid was set move into the Solr site
                // root and become indexable.
                $touch = ['last_synced' => $now];
                if (Typed::int($existing['pid']) !== $targetPid) {
                    $touch['pid'] = $targetPid;
                    $touch['tstamp'] = $now;
                }
                $connection->update('tx_skillflow_skill', $touch, ['uid' => $uid]);
                return ['unchanged', $uid];
            }
            $connection->update('tx_skillflow_skill', $fields, ['uid' => $uid]);
            return ['updated', $uid];
        }

        $connection->insert('tx_skillflow_skill', $fields + [
            'identifier' => $skill->identifier,
            'source_type' => $sourceType,
            'repository' => $repositoryUid,
            'crdate' => $now,
        ], [
            'body' => Connection::PARAM_STR,
        ]);
        return ['created', (int)$connection->lastInsertId()];
    }

    /**
     * Syncs the supporting files of one skill directory into
     * tx_skillflow_file: upsert by relative path (uids stay stable),
     * soft-delete attachments whose file disappeared from the source.
     */
    private function syncSupportingFiles(int $skillUid, string $skillDirectory, ImportResult $result): void
    {
        if ($skillUid <= 0) {
            return;
        }
        $connection = $this->connectionPool->getConnectionForTable('tx_skillflow_file');
        $existing = [];
        $rows = $connection->select(['uid', 'relative_path', 'content_hash'], 'tx_skillflow_file', ['skill' => $skillUid, 'deleted' => 0])->fetchAllAssociative();
        foreach ($rows as $row) {
            $existing[Typed::string($row['relative_path'])] = $row;
        }

        $now = time();
        $seenPaths = [];
        foreach ($this->collectSupportingFiles($skillDirectory, $result) as $relativePath => $content) {
            $seenPaths[$relativePath] = true;
            $hash = sha1($content);
            $row = $existing[$relativePath] ?? null;
            if ($row !== null) {
                if (Typed::string($row['content_hash']) !== $hash) {
                    $connection->update('tx_skillflow_file', [
                        'content' => $content,
                        'content_hash' => $hash,
                        'size' => strlen($content),
                        'tstamp' => $now,
                    ], ['uid' => Typed::int($row['uid'])]);
                }
            } else {
                $connection->insert('tx_skillflow_file', [
                    'pid' => 0,
                    'crdate' => $now,
                    'tstamp' => $now,
                    'skill' => $skillUid,
                    'relative_path' => $relativePath,
                    'content' => $content,
                    'content_hash' => $hash,
                    'size' => strlen($content),
                ], ['content' => Connection::PARAM_STR]);
            }
            $result->files++;
        }

        foreach ($existing as $relativePath => $row) {
            if (!isset($seenPaths[$relativePath])) {
                $connection->update('tx_skillflow_file', ['deleted' => 1, 'tstamp' => $now], ['uid' => Typed::int($row['uid'])]);
            }
        }
    }

    /**
     * @return array<string, string> relative path => content
     */
    private function collectSupportingFiles(string $skillDirectory, ImportResult $result): array
    {
        $realDirectory = realpath($skillDirectory);
        if ($realDirectory === false) {
            return [];
        }
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($realDirectory, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS)
        );
        $iterator->setMaxDepth(6);
        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getFilename() === 'SKILL.md') {
                continue;
            }
            $relativePath = ltrim(substr($file->getPathname(), strlen($realDirectory)), '/');
            if ($relativePath === '' || str_contains($relativePath, '..')) {
                continue;
            }
            // A symlink inside the folder may point outside the project; the
            // iterator follows it but its in-tree path stays clean, so re-check
            // the resolved target is still within the project.
            if (!$this->isInsideProject($file->getPathname())) {
                $result->skippedFiles++;
                continue;
            }
            // Skip VCS / dependency / build-output directories anywhere in the path.
            foreach (explode('/', $relativePath) as $segment) {
                if (in_array($segment, self::IGNORED_DIRECTORIES, true)) {
                    continue 2;
                }
            }
            $filename = $file->getFilename();
            if ($filename === '.DS_Store' || $filename === 'Thumbs.db') {
                continue;
            }
            // Import every text file; only true binaries and oversized files are skipped.
            $extension = strtolower($file->getExtension());
            if (in_array($extension, self::BINARY_FILE_EXTENSIONS, true) || $file->getSize() > self::MAX_FILE_SIZE) {
                $result->skippedFiles++;
                continue;
            }
            $content = (string)file_get_contents($file->getPathname());
            if ($content !== '' && !mb_check_encoding($content, 'UTF-8')) {
                $result->skippedFiles++;
                continue;
            }
            $files[$relativePath] = $content;
        }
        ksort($files);
        return $files;
    }
}
