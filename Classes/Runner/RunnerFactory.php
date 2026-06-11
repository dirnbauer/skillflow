<?php

declare(strict_types=1);

namespace Webconsulting\Skills\Runner;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

final class RunnerFactory
{
    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly AnthropicApiRunner $anthropicApiRunner,
        private readonly ClaudeCliRunner $claudeCliRunner,
    ) {
    }

    public function create(): SkillRunnerInterface
    {
        try {
            $conf = (array)$this->extensionConfiguration->get('webcon_skills');
        } catch (\Throwable) {
            $conf = [];
        }
        return (($conf['runner'] ?? 'api') === 'cli') ? $this->claudeCliRunner : $this->anthropicApiRunner;
    }
}
