<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Runner;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Webconsulting\Skillflow\Domain\SkillRunResult;
use Webconsulting\Skillflow\Exception\ExecutionBlockedException;
use Webconsulting\Skillflow\Support\Typed;

/**
 * Runs a skill through the local Claude Code CLI in non-interactive print
 * mode. Because the CLI runs with the project root as working directory it
 * can use MCP servers configured in the project's .mcp.json - but only tools
 * explicitly whitelisted in the skill's "allowed-tools" frontmatter are
 * permitted; everything else is denied in print mode.
 *
 * Supporting files (tx_skillflow_file) are materialized into a transient
 * skill directory before the run, so the model can progressively load
 * references/scripts exactly as the skill author intended. When attachments
 * exist, the Read tool is added to the whitelist automatically.
 *
 * This runner is strictly local-only and is additionally protected by the
 * EnvironmentGuard (Development context + DDEV).
 */
final class ClaudeCliRunner implements SkillRunnerInterface
{
    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly PromptBuilder $promptBuilder,
    ) {
    }

    public function getName(): string
    {
        return 'claude-cli';
    }

    public function run(array $skill, string $content, array $files = []): SkillRunResult
    {
        $binary = $this->resolveBinary();
        $allowedTools = trim(Typed::string($skill['allowed_tools'] ?? null));

        $skillDirectory = '';
        $userPrompt = $this->promptBuilder->buildUserPrompt($content);
        try {
            if ($files !== []) {
                $skillDirectory = $this->materializeSkill($skill, $files);
                $userPrompt .= "\n\nThe supporting files of this skill are available at: " . $skillDirectory
                    . "\nRead them when the skill instructions refer to them.";
                if (!preg_match('/(^|,)\s*Read\s*(,|$)/i', $allowedTools)) {
                    $allowedTools = $allowedTools === '' ? 'Read' : $allowedTools . ',Read';
                }
            }

            $command = [
                $binary,
                '-p',
                '--output-format', 'text',
                '--max-turns', '8',
                '--append-system-prompt', $this->promptBuilder->buildSystemPrompt($skill),
            ];
            if ($skillDirectory !== '') {
                $command[] = '--add-dir';
                $command[] = $skillDirectory;
            }
            if ($allowedTools !== '') {
                $command[] = '--allowedTools';
                $command[] = $allowedTools;
            }

            $process = new Process(
                $command,
                Environment::getProjectPath(),
                null,
                $userPrompt,
                300
            );
            $process->run();

            if (!$process->isSuccessful()) {
                throw new \RuntimeException(
                    sprintf(
                        'Claude CLI failed (exit %d): %s',
                        (int)$process->getExitCode(),
                        mb_substr(trim($process->getErrorOutput() . "\n" . $process->getOutput()), 0, 500)
                    ),
                    1760000040
                );
            }

            $output = trim($process->getOutput());
            if ($output === '') {
                throw new \RuntimeException('Claude CLI returned an empty response', 1760000041);
            }

            return new SkillRunResult('success', $output, $this->getName());
        } finally {
            if ($skillDirectory !== '' && is_dir(dirname($skillDirectory))) {
                GeneralUtility::rmdir(dirname($skillDirectory), true);
            }
        }
    }

    /**
     * Writes the skill (regenerated SKILL.md + attachments) into a
     * transient directory and returns the skill directory path.
     *
     * @param array<string, mixed> $skill
     * @param array<int, array<string, mixed>> $files
     */
    private function materializeSkill(array $skill, array $files): string
    {
        $identifier = Typed::string($skill['identifier'] ?? null) ?: 'skill';
        $runDirectory = Environment::getVarPath() . '/transient/skillflow/cli-' . bin2hex(random_bytes(8));
        $skillDirectory = $runDirectory . '/' . $identifier;
        GeneralUtility::mkdir_deep($skillDirectory);

        $frontmatter = [
            'name' => $identifier,
            'description' => Typed::string($skill['description'] ?? null),
        ];
        $allowedTools = trim(Typed::string($skill['allowed_tools'] ?? null));
        if ($allowedTools !== '') {
            $frontmatter['allowed-tools'] = $allowedTools;
        }
        file_put_contents(
            $skillDirectory . '/SKILL.md',
            "---\n" . Yaml::dump($frontmatter) . "---\n\n" . Typed::string($skill['body'] ?? null) . "\n"
        );

        foreach ($files as $file) {
            $relativePath = trim(Typed::string($file['relative_path'] ?? null), '/');
            if ($relativePath === '' || str_contains($relativePath, '..')) {
                continue;
            }
            $targetPath = $skillDirectory . '/' . $relativePath;
            GeneralUtility::mkdir_deep(dirname($targetPath));
            file_put_contents($targetPath, Typed::string($file['content'] ?? null));
        }

        return $skillDirectory;
    }

    private function resolveBinary(): string
    {
        try {
            $conf = Typed::stringKeyedArray($this->extensionConfiguration->get('skillflow'));
        } catch (\Throwable) {
            $conf = [];
        }
        $binary = trim(Typed::string($conf['claudeBinary'] ?? null)) ?: 'claude';
        if (!str_contains($binary, '/')) {
            $resolved = (new ExecutableFinder())->find($binary);
            if ($resolved === null) {
                throw new ExecutionBlockedException(
                    sprintf('Claude CLI binary "%s" not found in PATH. Install Claude Code or configure "claudeBinary".', $binary),
                    1760000042
                );
            }
            return $resolved;
        }
        if (!is_executable($binary)) {
            throw new ExecutionBlockedException(
                sprintf('Configured Claude CLI binary "%s" is not executable.', $binary),
                1760000043
            );
        }
        return $binary;
    }
}
