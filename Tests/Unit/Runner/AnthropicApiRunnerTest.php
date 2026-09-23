<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Tests\Unit\Runner;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\Stream;
use Webconsulting\Skillflow\Configuration\ExtensionSettings;
use Webconsulting\Skillflow\Exception\ExecutionBlockedException;
use Webconsulting\Skillflow\Runner\AnthropicApiRunner;
use Webconsulting\Skillflow\Runner\PromptBuilder;

final class AnthropicApiRunnerTest extends TestCase
{
    private const string KEY_VARIABLE = 'SKILLFLOW_TEST_ANTHROPIC_KEY';

    /** @var array<string, mixed> */
    private array $sentOptions = [];

    protected function setUp(): void
    {
        parent::setUp();
        putenv(self::KEY_VARIABLE . '=test-key');
    }

    protected function tearDown(): void
    {
        putenv(self::KEY_VARIABLE);
        parent::tearDown();
    }

    public function testRequestWithoutMcpServersSendsNoConnectorHalf(): void
    {
        $result = $this->runner([])->run(['name' => 'Review', 'body' => 'Check it.'], 'Page content');

        self::assertSame('success', $result->status);
        self::assertSame('Looks good.', $result->output);
        $headers = $this->sentHeaders();
        self::assertSame('test-key', $headers['x-api-key']);
        self::assertSame('2023-06-01', $headers['anthropic-version']);
        self::assertArrayNotHasKey('anthropic-beta', $headers);
        $payload = $this->sentPayload();
        self::assertSame('claude-sonnet-4-6', $payload['model']);
        self::assertSame(2048, $payload['max_tokens']);
        self::assertArrayNotHasKey('mcp_servers', $payload);
        self::assertArrayNotHasKey('tools', $payload);
    }

    public function testEveryMcpServerGetsItsToolset(): void
    {
        $this->runner(['mcpServersJson' => json_encode([
            ['type' => 'url', 'url' => 'https://mcp.example.test/sse', 'name' => 'typo3', 'authorization_token' => 'secret'],
            ['url' => 'https://docs.example.test/mcp', 'name' => 'docs'],
        ], JSON_THROW_ON_ERROR)])->run(['name' => 'Review'], 'Content');

        self::assertSame('mcp-client-2025-11-20', $this->sentHeaders()['anthropic-beta']);
        $payload = $this->sentPayload();
        self::assertSame([
            ['type' => 'url', 'url' => 'https://mcp.example.test/sse', 'name' => 'typo3', 'authorization_token' => 'secret'],
            ['type' => 'url', 'url' => 'https://docs.example.test/mcp', 'name' => 'docs'],
        ], $payload['mcp_servers']);
        self::assertSame([
            ['type' => 'mcp_toolset', 'mcp_server_name' => 'typo3'],
            ['type' => 'mcp_toolset', 'mcp_server_name' => 'docs'],
        ], $payload['tools']);
    }

    public function testLegacyToolConfigurationBecomesAToolsetAllowlist(): void
    {
        $this->runner(['mcpServersJson' => json_encode([
            ['type' => 'url', 'url' => 'https://mcp.example.test/sse', 'name' => 'typo3', 'tool_configuration' => ['enabled' => true, 'allowed_tools' => ['GetPage', 'Search']]],
            ['type' => 'url', 'url' => 'https://off.example.test/sse', 'name' => 'off', 'tool_configuration' => ['enabled' => false]],
        ], JSON_THROW_ON_ERROR)])->run(['name' => 'Review'], 'Content');

        $payload = $this->sentPayload();
        self::assertArrayNotHasKey('tool_configuration', $payload['mcp_servers'][0]);
        self::assertSame([
            [
                'type' => 'mcp_toolset',
                'mcp_server_name' => 'typo3',
                'default_config' => ['enabled' => false],
                'configs' => ['GetPage' => ['enabled' => true], 'Search' => ['enabled' => true]],
            ],
            ['type' => 'mcp_toolset', 'mcp_server_name' => 'off', 'default_config' => ['enabled' => false]],
        ], $payload['tools']);
    }

    public function testMalformedServerListBlocksTheRun(): void
    {
        $this->expectException(ExecutionBlockedException::class);
        $this->runner(['mcpServersJson' => '{"name":"typo3"}'])->run(['name' => 'Review'], 'Content');
    }

    public function testServerWithoutUrlBlocksTheRun(): void
    {
        $this->expectException(ExecutionBlockedException::class);
        $this->runner(['mcpServersJson' => '[{"name":"typo3"}]'])->run(['name' => 'Review'], 'Content');
    }

    public function testMissingKeyBlocksBeforeAnyRequest(): void
    {
        putenv(self::KEY_VARIABLE);

        $this->expectException(ExecutionBlockedException::class);
        try {
            $this->runner([])->run(['name' => 'Review'], 'Content');
        } finally {
            self::assertSame([], $this->sentOptions);
        }
    }

    /** @param array<string, string> $settings */
    private function runner(array $settings): AnthropicApiRunner
    {
        $requestFactory = self::createStub(RequestFactory::class);
        $requestFactory->method('request')->willReturnCallback(function (string $uri, string $method, array $options): ResponseInterface {
            $this->sentOptions = $options;
            $body = new Stream('php://temp', 'rw');
            $body->write(json_encode(['content' => [['type' => 'text', 'text' => 'Looks good.']]], JSON_THROW_ON_ERROR));
            $body->rewind();

            return new Response($body, 200);
        });

        return new AnthropicApiRunner(
            $requestFactory,
            ExtensionSettings::fromArray($settings + ['apiKeyEnvVar' => self::KEY_VARIABLE]),
            new PromptBuilder(),
        );
    }

    /** @return array<string, mixed> */
    private function sentHeaders(): array
    {
        self::assertIsArray($this->sentOptions['headers'] ?? null);

        return $this->sentOptions['headers'];
    }

    /** @return array<string, mixed> */
    private function sentPayload(): array
    {
        self::assertIsString($this->sentOptions['body'] ?? null);
        $payload = json_decode($this->sentOptions['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        return $payload;
    }
}
