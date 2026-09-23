<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Configuration;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use Webconsulting\Skillflow\Support\Typed;

/**
 * The extension configuration, read once and typed. The string-keyed,
 * string-valued shape TYPO3 stores exists only here, together with the
 * defaults and floors the runners used to repeat at every call site.
 *
 * Registered as a service built by {@see self::load()}; tests build one with
 * the constructor or {@see self::fromArray()} and need no TYPO3 at all.
 */
final readonly class ExtensionSettings
{
    public const string DEFAULT_MODEL = 'claude-sonnet-4-6';
    public const string DEFAULT_API_KEY_ENV_VAR = 'ANTHROPIC_API_KEY';
    public const string DEFAULT_CLAUDE_BINARY = 'claude';
    public const string CLASSIC_ENGINE = 'classic';
    public const int DEFAULT_MAX_TOKENS = 2048;

    /** Below this an API report cannot hold findings and suggestions. */
    public const int MINIMUM_MAX_TOKENS = 256;

    public function __construct(
        public RunnerMode $runner = RunnerMode::Api,
        /** Model id for the direct Anthropic runner. */
        public string $model = self::DEFAULT_MODEL,
        /** Name of the environment variable holding the Anthropic API key. */
        public string $apiKeyEnvVar = self::DEFAULT_API_KEY_ENV_VAR,
        public int $maxTokens = self::DEFAULT_MAX_TOKENS,
        /** Claude Code executable: a name resolved through PATH or an absolute path. */
        public string $claudeBinary = self::DEFAULT_CLAUDE_BINARY,
        /** JSON array of remote MCP servers for the Anthropic runner ('' = none). */
        public string $mcpServersJson = '',
        /** Claude Code .mcp.json content for the CLI runner ('' = none). */
        public string $mcpConfigJson = '',
        /** Engine used when neither the run nor the skill names one. */
        public string $defaultEngine = self::CLASSIC_ENGINE,
        /** Fall back to the classic chain when the selected engine is unavailable. */
        public bool $engineFallback = true,
        /** Only execute skills in Development context inside DDEV. */
        public bool $requireLocalEnvironment = true,
    ) {}

    public static function load(ExtensionConfiguration $extensionConfiguration): self
    {
        try {
            $raw = Typed::stringKeyedArray($extensionConfiguration->get('skillflow'));
        } catch (\Throwable) {
            // Not configured yet (or the extension is mid-install): use the defaults.
            $raw = [];
        }

        return self::fromArray($raw);
    }

    /**
     * @param array<string, mixed> $raw values as TYPO3 stores them, i.e. strings
     */
    public static function fromArray(array $raw): self
    {
        return new self(
            RunnerMode::tryFrom(trim(Typed::string($raw['runner'] ?? null))) ?? RunnerMode::Api,
            trim(Typed::string($raw['model'] ?? null)) ?: self::DEFAULT_MODEL,
            trim(Typed::string($raw['apiKeyEnvVar'] ?? null)) ?: self::DEFAULT_API_KEY_ENV_VAR,
            max(self::MINIMUM_MAX_TOKENS, Typed::int($raw['maxTokens'] ?? self::DEFAULT_MAX_TOKENS)),
            trim(Typed::string($raw['claudeBinary'] ?? null)) ?: self::DEFAULT_CLAUDE_BINARY,
            trim(Typed::string($raw['mcpServersJson'] ?? null)),
            trim(Typed::string($raw['mcpConfigJson'] ?? null)),
            trim(Typed::string($raw['defaultEngine'] ?? null)) ?: self::CLASSIC_ENGINE,
            self::flag($raw['engineFallback'] ?? null, true),
            self::flag($raw['requireLocalEnvironment'] ?? null, true),
        );
    }

    /** TYPO3 stores checkboxes as '0'/'1'; anything unset keeps the safe default. */
    private static function flag(mixed $value, bool $default): bool
    {
        return match (true) {
            is_bool($value) => $value,
            $value === null, $value === '' => $default,
            default => Typed::int($value) !== 0,
        };
    }
}
