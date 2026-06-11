<?php

declare(strict_types=1);

namespace Webconsulting\Skills\Runner;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\RequestFactory;
use Webconsulting\Skills\Domain\SkillRunResult;
use Webconsulting\Skills\Exception\ExecutionBlockedException;
use Webconsulting\Skills\Support\Typed;

/**
 * Runs a skill through the Anthropic Messages API.
 *
 * The API key is read from an environment variable (never stored in the
 * database). Optionally remote MCP servers can be attached through the
 * Anthropic MCP connector by configuring "mcpServersJson" in the
 * extension configuration.
 */
final class AnthropicApiRunner implements SkillRunnerInterface
{
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';

    public function __construct(
        private readonly RequestFactory $requestFactory,
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly PromptBuilder $promptBuilder,
    ) {
    }

    public function getName(): string
    {
        return 'anthropic-api';
    }

    public function run(array $skill, string $content): SkillRunResult
    {
        $conf = $this->configuration();
        $envVar = trim(Typed::string($conf['apiKeyEnvVar'] ?? null)) ?: 'ANTHROPIC_API_KEY';
        $apiKey = getenv($envVar);
        if ($apiKey === false || $apiKey === '') {
            throw new ExecutionBlockedException(
                sprintf('Anthropic API key env var "%s" is not set. Configure it in your local .ddev environment.', $envVar),
                1760000030
            );
        }

        $payload = [
            'model' => trim(Typed::string($conf['model'] ?? null)) ?: 'claude-sonnet-4-6',
            'max_tokens' => max(256, Typed::int($conf['maxTokens'] ?? 2048)),
            'system' => $this->promptBuilder->buildSystemPrompt($skill),
            'messages' => [
                ['role' => 'user', 'content' => $this->promptBuilder->buildUserPrompt($content)],
            ],
        ];

        $headers = [
            'Content-Type' => 'application/json',
            'x-api-key' => $apiKey,
            'anthropic-version' => '2023-06-01',
        ];

        $mcpServersJson = trim(Typed::string($conf['mcpServersJson'] ?? null));
        if ($mcpServersJson !== '') {
            $mcpServers = json_decode($mcpServersJson, true);
            if (!is_array($mcpServers)) {
                throw new \RuntimeException('Extension setting "mcpServersJson" is not valid JSON', 1760000031);
            }
            $payload['mcp_servers'] = $mcpServers;
            $headers['anthropic-beta'] = 'mcp-client-2025-04-04';
        }

        $response = $this->requestFactory->request(self::ENDPOINT, 'POST', [
            'headers' => $headers,
            'body' => (string)json_encode($payload),
            'timeout' => 120,
            'http_errors' => false,
        ]);

        $body = $response->getBody()->getContents();
        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException(
                sprintf('Anthropic API returned HTTP %d: %s', $response->getStatusCode(), mb_substr($body, 0, 500)),
                1760000032
            );
        }

        $data = json_decode($body, true);
        $contentBlocks = is_array($data) && is_array($data['content'] ?? null) ? $data['content'] : [];
        $text = '';
        foreach ($contentBlocks as $block) {
            if (is_array($block) && ($block['type'] ?? '') === 'text') {
                $text .= Typed::string($block['text'] ?? null);
            }
        }
        if (trim($text) === '') {
            throw new \RuntimeException('Anthropic API returned an empty response', 1760000033);
        }

        return new SkillRunResult('success', trim($text), $this->getName());
    }

    /**
     * @return array<string, mixed>
     */
    private function configuration(): array
    {
        try {
            return Typed::stringKeyedArray($this->extensionConfiguration->get('webcon_skills'));
        } catch (\Throwable) {
            return [];
        }
    }
}
