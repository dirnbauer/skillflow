<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Runner;

use TYPO3\CMS\Core\Http\RequestFactory;
use Webconsulting\Skillflow\Configuration\ExtensionSettings;
use Webconsulting\Skillflow\Domain\RunStatus;
use Webconsulting\Skillflow\Domain\SkillRunResult;
use Webconsulting\Skillflow\Exception\ExecutionBlockedException;
use Webconsulting\Skillflow\Support\Typed;

/**
 * Runs a skill through the Anthropic Messages API.
 *
 * The API key is read from an environment variable (never stored in the
 * database). Optionally remote MCP servers can be attached through the
 * Anthropic MCP connector by configuring "mcpServersJson" in the extension
 * configuration.
 */
final readonly class AnthropicApiRunner implements SkillRunnerInterface
{
    private const string ENDPOINT = 'https://api.anthropic.com/v1/messages';
    private const string API_VERSION = '2023-06-01';

    public function __construct(
        private RequestFactory $requestFactory,
        private ExtensionSettings $settings,
        private PromptBuilder $promptBuilder,
    ) {}

    #[\Override]
    public function getName(): string
    {
        return 'anthropic-api';
    }

    #[\Override]
    public function run(array $skill, string $content, array $files = []): SkillRunResult
    {
        $apiKey = getenv($this->settings->apiKeyEnvVar);
        if ($apiKey === false || $apiKey === '') {
            throw new ExecutionBlockedException(
                sprintf('Anthropic API key env var "%s" is not set. Configure it in your local .ddev environment.', $this->settings->apiKeyEnvVar),
                1760000030
            );
        }

        $payload = [
            'model' => $this->settings->model,
            'max_tokens' => $this->settings->maxTokens,
            'system' => $this->promptBuilder->buildSystemPrompt($skill),
            'messages' => [
                ['role' => 'user', 'content' => $this->promptBuilder->buildUserPrompt($content)],
            ],
        ];
        $headers = [
            'Content-Type' => 'application/json',
            'x-api-key' => $apiKey,
            'anthropic-version' => self::API_VERSION,
        ];

        if ($this->settings->mcpServersJson !== '') {
            $mcpServers = json_decode($this->settings->mcpServersJson, true);
            if (!is_array($mcpServers)) {
                throw new \RuntimeException('Extension setting "mcpServersJson" is not valid JSON', 1760000031);
            }
            $payload['mcp_servers'] = $mcpServers;
            $headers['anthropic-beta'] = 'mcp-client-2025-04-04';
        }

        $response = $this->requestFactory->request(self::ENDPOINT, 'POST', [
            'headers' => $headers,
            'body' => json_encode($payload, JSON_THROW_ON_ERROR),
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

        return new SkillRunResult(RunStatus::Success->value, trim($text), $this->getName());
    }
}
