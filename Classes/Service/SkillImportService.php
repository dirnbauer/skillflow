<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Service;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use Webconsulting\Skillflow\Domain\ImportResult;
use Webconsulting\Skillflow\Domain\ParsedSkill;
use Webconsulting\Skillflow\Support\Typed;

/**
 * Imports skills from a local folder containing skill directories,
 * each with a SKILL.md file (Anthropic skill structure).
 *
 * Existing skills are matched by identifier + source, updated in place
 * (the uid is kept stable) and only touched when their content changed.
 */
final class SkillImportService
{
    public function __construct(
        private readonly SkillParser $skillParser,
        private readonly ConnectionPool $connectionPool,
        private readonly ExtensionConfiguration $extensionConfiguration,
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

        foreach ($this->findSkillFiles($realPath) as $skillFile) {
            $relativePath = ltrim(substr($skillFile, strlen($realPath)), '/');
            try {
                $parsed = $this->skillParser->parse((string)file_get_contents($skillFile), $relativePath);
                $result->{$this->upsert($parsed, $sourceType, $repositoryUid, $relativePath)}++;
            } catch (\Throwable $e) {
                $result->errors[] = $relativePath . ': ' . $e->getMessage();
            }
        }
        if ($result->created + $result->updated + $result->unchanged === 0 && $result->errors === []) {
            $result->errors[] = sprintf('No SKILL.md files found below "%s"', $absolutePath);
        }
        return $result;
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
            if ($file->isFile() && $file->getFilename() === 'SKILL.md') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);
        return $files;
    }

    /**
     * @return 'created'|'updated'|'unchanged'
     */
    private function upsert(ParsedSkill $skill, string $sourceType, int $repositoryUid, string $relativePath): string
    {
        $connection = $this->connectionPool->getConnectionForTable('tx_skillflow_skill');
        $existing = $connection->select(
            ['uid', 'content_hash'],
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
            'last_synced' => $now,
            'tstamp' => $now,
        ];

        if ($existing !== false) {
            if (Typed::string($existing['content_hash']) === $skill->contentHash()) {
                $connection->update('tx_skillflow_skill', ['last_synced' => $now], ['uid' => Typed::int($existing['uid'])]);
                return 'unchanged';
            }
            $connection->update('tx_skillflow_skill', $fields, ['uid' => Typed::int($existing['uid'])]);
            return 'updated';
        }

        $connection->insert('tx_skillflow_skill', $fields + [
            'pid' => 0,
            'identifier' => $skill->identifier,
            'source_type' => $sourceType,
            'repository' => $repositoryUid,
            'crdate' => $now,
        ], [
            'body' => Connection::PARAM_STR,
        ]);
        return 'created';
    }
}
