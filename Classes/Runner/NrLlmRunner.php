<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Runner;

use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Service\LlmServiceManager;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Webconsulting\Skillflow\Domain\SkillRunResult;
use Webconsulting\Skillflow\Exception\ExecutionBlockedException;
use Webconsulting\Skillflow\Support\Typed;

/**
 * Runs a skill through the nr_llm extension, reusing the LLM connection already
 * configured in the "LLM" backend module (provider, model and the vault-stored
 * API key). nr_llm's chat() prefers its backend-managed default configuration,
 * so no skillflow-specific key or env var is needed.
 *
 * Soft dependency: nr_llm is resolved lazily and only ever touched behind the
 * class_exists() guard in isAvailable(), so skillflow still installs and runs
 * (via AnthropicApiRunner / ClaudeCliRunner) when nr_llm is absent.
 */
final class NrLlmRunner implements SkillRunnerInterface
{
    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly PromptBuilder $promptBuilder,
    ) {
    }

    public function getName(): string
    {
        return 'nr-llm';
    }

    /**
     * True when nr_llm is installed and has at least one usable provider — i.e.
     * a working LLM connection has been configured in the LLM backend module.
     */
    public function isAvailable(): bool
    {
        if (!class_exists(LlmServiceManager::class)) {
            return false;
        }
        try {
            // Mirror LlmServiceManager::chat()'s resolution: a backend-module default
            // configuration with a model (and no BE-group restriction) drives generation
            // — this is the case when the LLM is configured via the "LLM" module rather
            // than via ext-config provider keys.
            if (class_exists(LlmConfigurationRepository::class)) {
                $default = GeneralUtility::makeInstance(LlmConfigurationRepository::class)->findDefault();
                if ($default !== null && $default->getLlmModel() !== null && !$default->hasAccessRestrictions()) {
                    return true;
                }
            }

            // Otherwise an ext-config provider configured with an API key.
            return GeneralUtility::makeInstance(LlmServiceManager::class)->hasAvailableProvider();
        } catch (\Throwable) {
            return false;
        }
    }

    public function run(array $skill, string $content, array $files = []): SkillRunResult
    {
        if (!$this->isAvailable()) {
            throw new ExecutionBlockedException(
                'No usable LLM connection is configured in nr_llm (the "LLM" backend module).',
                1760002000
            );
        }

        $manager = GeneralUtility::makeInstance(LlmServiceManager::class);

        $system = $this->promptBuilder->buildSystemPrompt($skill)
            . $this->promptBuilder->buildFilesSection(Typed::string($skill['body'] ?? null), $files);
        $user = $this->promptBuilder->buildUserPrompt($content);

        $maxTokens = max(256, Typed::int($this->configuration()['maxTokens'] ?? 2048));
        $options = (new ChatOptions())->withSystemPrompt($system)->withMaxTokens($maxTokens);

        try {
            $response = $manager->chat([['role' => 'user', 'content' => $user]], $options);
        } catch (\Throwable $e) {
            throw new \RuntimeException('nr_llm chat request failed: ' . $e->getMessage(), 1760002001, $e);
        }

        $text = trim($response->getText());
        if ($text === '') {
            throw new \RuntimeException('nr_llm returned an empty response', 1760002002);
        }

        return new SkillRunResult('success', $text, $this->getName());
    }

    /**
     * @return array<string, mixed>
     */
    private function configuration(): array
    {
        try {
            return Typed::stringKeyedArray($this->extensionConfiguration->get('skillflow'));
        } catch (\Throwable) {
            return [];
        }
    }
}
