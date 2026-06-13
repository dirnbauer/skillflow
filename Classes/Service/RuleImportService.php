<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Service;

use Webconsulting\Skillflow\Domain\ImportResult;

/**
 * Imports TYPO3 "agent rules" from a local rules/ directory (category
 * subdirectories, each containing rule *.md files with YAML frontmatter)
 * as skill records with source_type='rules', repository=0.
 *
 * Unlike SkillImportService::importFromPath this reads its own files: the
 * rules directory commonly lives OUTSIDE the TYPO3 project path, so the
 * project-containment check would block it. Only category-subdir rule files
 * are imported; the rules/README.md and rules/principles.md overviews and
 * any other file directly in the rules root are skipped. The shared DB write
 * path (content_hash / last_synced / storagePid migration) is reused via
 * SkillImportService::importParsedSkill — no upsert logic is duplicated here.
 */
final class RuleImportService
{
    private const SOURCE_TYPE = 'rules';

    /**
     * Files in the rules root that are documentation, not importable rules.
     */
    private const SKIPPED_ROOT_FILES = ['README.md', 'principles.md'];

    public function __construct(
        private readonly RuleParser $ruleParser,
        private readonly SkillImportService $skillImportService,
    ) {
    }

    public function importFromPath(string $absolutePath): ImportResult
    {
        $result = new ImportResult();
        $realPath = realpath($absolutePath);
        if ($realPath === false || !is_dir($realPath)) {
            $result->errors[] = sprintf('Rules folder "%s" does not exist', $absolutePath);
            return $result;
        }

        foreach ($this->findRuleFiles($realPath) as $ruleFile) {
            $relativePath = ltrim(substr($ruleFile, strlen($realPath)), '/');
            try {
                $parsed = $this->ruleParser->parse((string)file_get_contents($ruleFile), $relativePath);
                $status = $this->skillImportService->importParsedSkill($parsed, self::SOURCE_TYPE, 0, $relativePath);
                $result->{$status}++;
            } catch (\Throwable $e) {
                $result->errors[] = $relativePath . ': ' . $e->getMessage();
            }
        }

        if ($result->created + $result->updated + $result->unchanged === 0 && $result->errors === []) {
            $result->errors[] = sprintf('No rule files found in category subdirectories below "%s"', $absolutePath);
        }
        return $result;
    }

    /**
     * Collects category-subdir rule files: *.md at least one directory below
     * the rules root. Files directly in the root (README.md, principles.md,
     * anything else) are intentionally excluded.
     *
     * The realpath + containment guard mirrors SkillImportService: every
     * resolved file must stay within the (resolved) rules directory so a
     * symlink that escapes the tree cannot drag in foreign files.
     *
     * @return string[] absolute paths of rule *.md files, sorted
     */
    private function findRuleFiles(string $rulesDirectory): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($rulesDirectory, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS)
        );
        $iterator->setMaxDepth(4);
        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'md') {
                continue;
            }
            $relativePath = ltrim(substr($file->getPathname(), strlen($rulesDirectory)), '/');
            // Only category-subdir rule files: must contain a directory separator.
            if (!str_contains($relativePath, '/')) {
                continue;
            }
            if (str_contains($relativePath, '..')) {
                continue;
            }
            // Skip the overview docs even if they ever live in a subdir.
            if (in_array($file->getFilename(), self::SKIPPED_ROOT_FILES, true)) {
                continue;
            }
            // A symlink may point outside the rules tree; the iterator follows
            // it but the in-tree path stays clean, so re-check the resolved
            // target is still within the rules directory.
            if (!$this->isInsideDirectory($file->getPathname(), $rulesDirectory)) {
                continue;
            }
            $files[] = $file->getPathname();
        }
        sort($files);
        return $files;
    }

    private function isInsideDirectory(string $path, string $directory): bool
    {
        $real = realpath($path);
        return $real !== false && str_starts_with($real, rtrim($directory, '/') . '/');
    }
}
