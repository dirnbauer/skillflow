<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Runner;

use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Webconsulting\Skillflow\Configuration\ExtensionSettings;
use Webconsulting\Skillflow\Domain\RunStatus;
use Webconsulting\Skillflow\Domain\SkillRunResult;
use Webconsulting\Skillflow\Exception\ExecutionBlockedException;

/**
 * Runs a skill through the nr_llm extension, reusing the LLM connection already
 * configured in AI → Setup (provider, model and the vault-stored
 * API key). nr_llm's chat() prefers its backend-managed default configuration,
 * so no skillflow-specific key or env var is needed.
 */
final readonly class NrLlmRunner implements SkillRunnerInterface
{
    public function __construct(
        private ExtensionSettings $settings,
        private PromptBuilder $promptBuilder,
        private LlmServiceManagerInterface $llmServiceManager,
    ) {}

    #[\Override]
    public function getName(): string
    {
        return 'nr-llm';
    }

    /**
     * Uses the same default-configuration resolution as nr_llm chat().
     */
    public function isAvailable(): bool
    {
        try {
            return $this->llmServiceManager->resolveEffectiveConfiguration() !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    #[\Override]
    public function run(array $skill, string $content, array $files = []): SkillRunResult
    {
        if (!$this->isAvailable()) {
            throw new ExecutionBlockedException(
                'No usable LLM connection is configured in nr_llm (AI → Setup).',
                1760002000
            );
        }

        $options = new ChatOptions()
            ->withSystemPrompt($this->promptBuilder->buildSystemPrompt($skill))
            ->withMaxTokens($this->settings->maxTokens);

        try {
            $response = $this->llmServiceManager->chat(
                [['role' => 'user', 'content' => $this->promptBuilder->buildUserPrompt($content)]],
                $options,
            );
        } catch (\Throwable $e) {
            throw new \RuntimeException('nr_llm chat request failed: ' . $e->getMessage(), 1760002001, $e);
        }

        $text = trim($response->getText());
        if ($text === '') {
            throw new \RuntimeException('nr_llm returned an empty response', 1760002002);
        }

        return new SkillRunResult(RunStatus::Success->value, $text, $this->getName());
    }
}
