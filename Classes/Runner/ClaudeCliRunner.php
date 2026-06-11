<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Runner;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Core\Environment;
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

    public function run(array $skill, string $content): SkillRunResult
    {
        $binary = $this->resolveBinary();

        $command = [
            $binary,
            '-p',
            '--output-format', 'text',
            '--max-turns', '8',
            '--append-system-prompt', $this->promptBuilder->buildSystemPrompt($skill),
        ];
        $allowedTools = trim(Typed::string($skill['allowed_tools'] ?? null));
        if ($allowedTools !== '') {
            $command[] = '--allowedTools';
            $command[] = $allowedTools;
        }

        $process = new Process(
            $command,
            Environment::getProjectPath(),
            null,
            $this->promptBuilder->buildUserPrompt($content),
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
