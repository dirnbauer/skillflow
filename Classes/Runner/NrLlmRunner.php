<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Runner;

use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use Webconsulting\Skillflow\Domain\SkillRunResult;
use Webconsulting\Skillflow\Exception\ExecutionBlockedException;
use Webconsulting\Skillflow\Support\Typed;

/**
 * Runs a skill through the nr_llm extension, reusing the LLM connection already
 * configured in AI → Setup (provider, model and the vault-stored
 * API key). nr_llm's chat() prefers its backend-managed default configuration,
 * so no skillflow-specific key or env var is needed.
 */
final class NrLlmRunner implements SkillRunnerInterface
{
    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly PromptBuilder $promptBuilder,
        private readonly LlmServiceManagerInterface $llmServiceManager,
    ) {
    }

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

    public function run(array $skill, string $content, array $files = []): SkillRunResult
    {
        if (!$this->isAvailable()) {
            throw new ExecutionBlockedException(
                'No usable LLM connection is configured in nr_llm (AI → Setup).',
                1760002000
            );
        }

        $system = $this->promptBuilder->buildSystemPrompt($skill);
        $user = $this->promptBuilder->buildUserPrompt($content);

        $maxTokens = max(256, Typed::int($this->configuration()['maxTokens'] ?? 2048));
        $options = (new ChatOptions())->withSystemPrompt($system)->withMaxTokens($maxTokens);

        try {
            $response = $this->llmServiceManager->chat([['role' => 'user', 'content' => $user]], $options);
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
