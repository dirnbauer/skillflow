<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Runner;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Webconsulting\Skillflow\Configuration\ExtensionSettings;
use Webconsulting\Skillflow\Domain\RunStatus;
use Webconsulting\Skillflow\Domain\SkillRunResult;
use Webconsulting\Skillflow\Exception\ExecutionBlockedException;
use Webconsulting\Skillflow\Service\SkillAbilityResolver;
use Webconsulting\Skillflow\Support\Typed;

/**
 * Runs a skill through the local Claude Code CLI in non-interactive print
 * mode. When the "mcpConfigJson" setting is set (e.g. to the TYPO3 abilities
 * MCP server), it is written to a transient config and passed via
 * --mcp-config --strict-mcp-config, so the skill can act through the governed
 * abilities registry.
 *
 * --allowedTools combines the skill's "allowed-tools" rules (e.g.
 * "mcp__typo3" for every tool of that server) with the abilities it declares
 * in "abilities:", each allowed as "mcp__<abilitiesMcpServer>__" plus
 * AbilityDefinition::mcpToolName(), e.g. "mcp__typo3__ability_news_list".
 * An explicitly empty "allowed-tools" disables the built-in tools; the
 * declared abilities stay available as MCP tools, and without abilities no
 * MCP server is started at all.
 *
 * This runner is strictly local-only and is additionally protected by the
 * EnvironmentGuard (Development context + DDEV).
 */
final readonly class ClaudeCliRunner implements SkillRunnerInterface
{
    private const string MAX_TURNS = '8';
    private const int TIMEOUT_SECONDS = 300;

    public function __construct(
        private ExtensionSettings $settings,
        private PromptBuilder $promptBuilder,
        private SkillAbilityResolver $abilityResolver,
    ) {}

    #[\Override]
    public function getName(): string
    {
        return 'claude-cli';
    }

    #[\Override]
    public function run(array $skill, string $content, array $files = []): SkillRunResult
    {
        $binary = $this->resolveBinary();
        $mcpConfigFile = $this->usesMcpServers($skill) ? $this->writeMcpConfig() : '';
        $userPrompt = $this->promptBuilder->buildUserPrompt($content);
        try {
            $command = $this->buildCommand($binary, $skill, $mcpConfigFile);

            $process = new Process(
                $command,
                Environment::getProjectPath(),
                null,
                $userPrompt,
                self::TIMEOUT_SECONDS,
            );
            $process->run();

            if (!$process->isSuccessful()) {
                throw new \RuntimeException(
                    sprintf(
                        'Claude CLI failed (exit %d): %s',
                        $process->getExitCode() ?? -1,
                        mb_substr(trim($process->getErrorOutput() . "\n" . $process->getOutput()), 0, 500)
                    ),
                    1760000040
                );
            }

            $output = trim($process->getOutput());
            if ($output === '') {
                throw new \RuntimeException('Claude CLI returned an empty response', 1760000041);
            }

            return new SkillRunResult(RunStatus::Success->value, $output, $this->getName());
        } finally {
            if ($mcpConfigFile !== '' && is_file($mcpConfigFile)) {
                unlink($mcpConfigFile);
            }
        }
    }

    /**
     * The CLI invocation for $skill, without the prompt (it goes to stdin).
     *
     * @param array<string, mixed> $skill a SkillFinder row
     * @return list<string>
     *
     * @internal public for tests
     */
    public function buildCommand(string $binary, array $skill, string $mcpConfigFile): array
    {
        $command = [
            $binary,
            '-p',
            '--output-format', 'text',
            '--max-turns', self::MAX_TURNS,
            '--append-system-prompt', $this->promptBuilder->buildSystemPrompt($skill),
        ];

        if ($this->builtInToolsDisabled($skill)) {
            $command[] = '--tools';
            $command[] = '';
        }
        $allowedTools = $this->allowedTools($skill);
        if ($allowedTools !== []) {
            $command[] = '--allowedTools';
            $command[] = implode(',', $allowedTools);
        }
        if ($mcpConfigFile !== '') {
            // Restrict to exactly the configured servers (the abilities
            // MCP server), ignoring any user/global .mcp.json.
            $command[] = '--mcp-config';
            $command[] = $mcpConfigFile;
        }
        if ($mcpConfigFile !== '' || $this->builtInToolsDisabled($skill)) {
            $command[] = '--strict-mcp-config';
        }

        return $command;
    }

    /**
     * The skill's own allowed-tools rules followed by one rule per declared
     * ability that the registry knows.
     *
     * @param array<string, mixed> $skill
     * @return list<string>
     */
    public function allowedTools(array $skill): array
    {
        $declared = array_values(array_filter(
            array_map(trim(...), explode(',', Typed::string($skill['allowed_tools'] ?? null))),
            static fn(string $tool): bool => $tool !== '',
        ));
        $abilities = is_array($skill['abilities'] ?? null) ? array_values(array_filter($skill['abilities'], is_string(...))) : [];

        return array_values(array_unique([...$declared, ...$this->abilityResolver->allowedTools($abilities)]));
    }

    /**
     * An explicitly empty nr_llm tool declaration ("allowed-tools: []").
     *
     * @param array<string, mixed> $skill
     */
    private function builtInToolsDisabled(array $skill): bool
    {
        return json_decode(Typed::string($skill['allowed_tools_json'] ?? ''), true) === [];
    }

    /**
     * MCP servers start unless the skill disabled every tool and declares
     * no ability it could reach through them.
     *
     * @param array<string, mixed> $skill
     */
    private function usesMcpServers(array $skill): bool
    {
        if (!$this->builtInToolsDisabled($skill)) {
            return true;
        }
        $abilities = is_array($skill['abilities'] ?? null) ? array_values(array_filter($skill['abilities'], is_string(...))) : [];

        return $this->abilityResolver->allowedTools($abilities) !== [];
    }

    /**
     * Materializes the "mcpConfigJson" setting into a transient Claude Code
     * MCP config file (e.g. registering the TYPO3 abilities MCP server), so a
     * skill run can call abilities as governed MCP tools. Returns '' when the
     * setting is empty.
     */
    private function writeMcpConfig(): string
    {
        if ($this->settings->mcpConfigJson === '') {
            return '';
        }

        $decoded = json_decode($this->settings->mcpConfigJson, true);
        if (!is_array($decoded)) {
            throw new ExecutionBlockedException('Extension setting "mcpConfigJson" is not valid JSON.', 1760000044);
        }

        $directory = Environment::getVarPath() . '/transient/skillflow';
        GeneralUtility::mkdir_deep($directory);
        $file = $directory . '/mcp-' . bin2hex(random_bytes(8)) . '.json';
        file_put_contents($file, json_encode($decoded, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        return $file;
    }

    private function resolveBinary(): string
    {
        $binary = $this->settings->claudeBinary;
        if (!str_contains($binary, '/')) {
            $resolved = new ExecutableFinder()->find($binary);
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
