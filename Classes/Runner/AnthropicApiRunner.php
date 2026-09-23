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
 * database). Remote MCP servers can be attached through the Anthropic MCP
 * connector by configuring "mcpServersJson" in the extension configuration.
 */
final readonly class AnthropicApiRunner implements SkillRunnerInterface
{
    private const string ENDPOINT = 'https://api.anthropic.com/v1/messages';
    private const string API_VERSION = '2023-06-01';
    private const string MCP_CONNECTOR_BETA = 'mcp-client-2025-11-20';

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

        $connector = $this->mcpConnector();
        if ($connector !== null) {
            $payload['mcp_servers'] = $connector['servers'];
            $payload['tools'] = $connector['toolsets'];
            $headers['anthropic-beta'] = self::MCP_CONNECTOR_BETA;
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

    /**
     * The MCP connector needs two halves: the server definitions and one
     * "mcp_toolset" entry per server. The setting holds only the servers, so
     * the toolsets are derived here. A server entry in the shape of the
     * retired 2025-04-04 beta ("tool_configuration" with "enabled" and
     * "allowed_tools") is translated into the toolset's allowlist, so existing
     * settings keep their tool restrictions.
     *
     * @return array{servers: list<array<string, mixed>>, toolsets: list<array<string, mixed>>}|null
     */
    private function mcpConnector(): ?array
    {
        if ($this->settings->mcpServersJson === '') {
            return null;
        }
        $decoded = json_decode($this->settings->mcpServersJson, true);
        if (!is_array($decoded) || !array_is_list($decoded)) {
            throw new ExecutionBlockedException('Extension setting "mcpServersJson" must be a JSON array of MCP server definitions.', 1760000031);
        }

        $servers = [];
        $toolsets = [];
        foreach ($decoded as $definition) {
            $server = Typed::stringKeyedArray($definition);
            $name = Typed::string($server['name'] ?? null);
            if ($name === '' || Typed::string($server['url'] ?? null) === '') {
                throw new ExecutionBlockedException('Every MCP server in "mcpServersJson" needs a "name" and a "url".', 1760000034);
            }
            $toolConfiguration = Typed::stringKeyedArray($server['tool_configuration'] ?? null);
            unset($server['tool_configuration']);
            $servers[] = ['type' => 'url'] + $server;
            $toolsets[] = self::toolset($name, $toolConfiguration);
        }

        return ['servers' => $servers, 'toolsets' => $toolsets];
    }

    /**
     * @param array<string, mixed> $toolConfiguration legacy per-server tool configuration
     * @return array<string, mixed>
     */
    private static function toolset(string $serverName, array $toolConfiguration): array
    {
        $toolset = ['type' => 'mcp_toolset', 'mcp_server_name' => $serverName];
        $allowedTools = is_array($toolConfiguration['allowed_tools'] ?? null)
            ? array_values(array_filter($toolConfiguration['allowed_tools'], is_string(...)))
            : [];
        if (($toolConfiguration['enabled'] ?? true) === false) {
            $toolset['default_config'] = ['enabled' => false];
        } elseif ($allowedTools !== []) {
            $toolset['default_config'] = ['enabled' => false];
            $toolset['configs'] = array_fill_keys($allowedTools, ['enabled' => true]);
        }

        return $toolset;
    }
}
