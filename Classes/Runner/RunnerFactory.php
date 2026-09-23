<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Runner;

use Webconsulting\Skillflow\Configuration\ExtensionSettings;
use Webconsulting\Skillflow\Configuration\RunnerMode;

/** Picks the runner of the classic single-shot chain. */
final readonly class RunnerFactory
{
    public function __construct(
        private ExtensionSettings $settings,
        private AnthropicApiRunner $anthropicApiRunner,
        private ClaudeCliRunner $claudeCliRunner,
        private NrLlmRunner $nrLlmRunner,
    ) {}

    public function create(): SkillRunnerInterface
    {
        // "api" prefers the connection configured in nr_llm (AI → Setup), so no
        // separate ANTHROPIC_API_KEY is required, and falls back to the
        // env-key Anthropic runner when nr_llm has no usable provider.
        return match ($this->settings->runner) {
            RunnerMode::Cli => $this->claudeCliRunner,
            RunnerMode::Anthropic => $this->anthropicApiRunner,
            RunnerMode::Api => $this->nrLlmRunner->isAvailable() ? $this->nrLlmRunner : $this->anthropicApiRunner,
        };
    }
}
