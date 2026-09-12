<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Runner;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Webconsulting\Skillflow\Domain\SkillRunResult;
use Webconsulting\Skillflow\Exception\ExecutionBlockedException;
use Webconsulting\Skillflow\Support\Typed;

/**
 * Runs a skill through the local Claude Code CLI in non-interactive print
 * mode. When the "mcpConfigJson" setting is set (e.g. to the TYPO3 abilities
 * MCP server), it is written to a transient config and passed via
 * --mcp-config --strict-mcp-config, so the skill can act through the governed
 * abilities registry. The skill's "allowed-tools" rules are passed to the
 * CLI permission system, e.g. "mcp__typo3__ability_system_site-info" (one
 * ability) or "mcp__typo3" (every tool that server exposes). An explicitly
 * empty nr_llm tool declaration disables built-in and MCP tools.
 *
 * This runner is strictly local-only and is additionally protected by the
 * EnvironmentGuard (Development context + DDEV).
 */
final class ClaudeCliRunner implements SkillRunnerInterface
{
    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly PromptBuilder $promptBuilder,
    ) {}

    public function getName(): string
    {
        return 'claude-cli';
    }

    public function run(array $skill, string $content, array $files = []): SkillRunResult
    {
        $binary = $this->resolveBinary();
        $allowedTools = trim(Typed::string($skill['allowed_tools'] ?? null));
        $toolsDisabled = json_decode(Typed::string($skill['allowed_tools_json'] ?? ''), true) === [];

        $mcpConfigFile = $toolsDisabled ? '' : $this->writeMcpConfig();
        $userPrompt = $this->promptBuilder->buildUserPrompt($content);
        try {
            $command = [
                $binary,
                '-p',
                '--output-format', 'text',
                '--max-turns', '8',
                '--append-system-prompt', $this->promptBuilder->buildSystemPrompt($skill),
            ];
            if ($toolsDisabled) {
                $command[] = '--tools';
                $command[] = '';
                $command[] = '--strict-mcp-config';
            } elseif ($allowedTools !== '') {
                $command[] = '--allowedTools';
                $command[] = $allowedTools;
            }
            if ($mcpConfigFile !== '') {
                // Restrict to exactly the configured servers (the abilities
                // MCP server), ignoring any user/global .mcp.json.
                $command[] = '--mcp-config';
                $command[] = $mcpConfigFile;
                $command[] = '--strict-mcp-config';
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
            if ($mcpConfigFile !== '' && is_file($mcpConfigFile)) {
                unlink($mcpConfigFile);
            }
        }
    }

    /**
     * Materializes the "mcpConfigJson" setting into a transient Claude Code
     * MCP config file (e.g. registering the TYPO3 abilities MCP server), so a
     * skill run can call abilities as governed MCP tools. Returns '' when the
     * setting is empty.
     */
    private function writeMcpConfig(): string
    {
        try {
            $conf = Typed::stringKeyedArray($this->extensionConfiguration->get('skillflow'));
        } catch (\Throwable) {
            $conf = [];
        }
        $mcpConfigJson = trim(Typed::string($conf['mcpConfigJson'] ?? null));
        if ($mcpConfigJson === '') {
            return '';
        }

        $decoded = json_decode($mcpConfigJson, true);
        if (!is_array($decoded)) {
            throw new ExecutionBlockedException('Extension setting "mcpConfigJson" is not valid JSON.', 1760000044);
        }

        $directory = Environment::getVarPath() . '/transient/skillflow';
        GeneralUtility::mkdir_deep($directory);
        $file = $directory . '/mcp-' . bin2hex(random_bytes(8)) . '.json';
        file_put_contents($file, (string)json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        return $file;
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
