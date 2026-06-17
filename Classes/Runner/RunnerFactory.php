<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Runner;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

final class RunnerFactory
{
    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly AnthropicApiRunner $anthropicApiRunner,
        private readonly ClaudeCliRunner $claudeCliRunner,
        private readonly NrLlmRunner $nrLlmRunner,
    ) {
    }

    public function create(): SkillRunnerInterface
    {
        try {
            $conf = (array)$this->extensionConfiguration->get('skillflow');
        } catch (\Throwable) {
            $conf = [];
        }

        $runner = $conf['runner'] ?? 'api';
        if ($runner === 'cli') {
            return $this->claudeCliRunner;
        }

        // API mode: prefer the connection already configured in nr_llm (the "LLM"
        // backend module) so no separate ANTHROPIC_API_KEY env var is required;
        // fall back to the env-var Anthropic Messages API runner when nr_llm has
        // no usable provider. Set runner=anthropic to force the env-var runner.
        if ($runner !== 'anthropic' && $this->nrLlmRunner->isAvailable()) {
            return $this->nrLlmRunner;
        }
        return $this->anthropicApiRunner;
    }
}
