<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Tests\Functional\Fixtures;

use Netresearch\NrLlm\Service\Skill\Exception\GitHubApiException;
use Netresearch\NrLlm\Service\Skill\GitHubClientInterface;
use Psr\Http\Client\ClientInterface;

/**
 * In-memory GitHub: every repository consists of the files in self::$files.
 * Registered through Tests/Functional/Fixtures/Extensions/skillflow_test.
 */
final class FakeGitHubClient implements GitHubClientInterface
{
    /** @var array<string, string> repository path => file body */
    public static array $files = [];
    public static string $sha = 'fixture-sha';
    public static ?\Throwable $failure = null;

    public static function reset(): void
    {
        self::$files = [];
        self::$sha = 'fixture-sha';
        self::$failure = null;
    }

    public function resolveSha(string $owner, string $repo, string $ref, ?string $tokenUuid): string
    {
        if (self::$failure !== null) {
            throw self::$failure;
        }

        return self::$sha;
    }

    public function listTree(string $owner, string $repo, string $sha, ?string $tokenUuid): array
    {
        return array_keys(self::$files);
    }

    public function fetchRawBySha(string $owner, string $repo, string $sha, string $path, ?string $tokenUuid): string
    {
        return self::$files[$path] ?? throw GitHubApiException::forStatus($owner . '/' . $repo . '/' . $path, 404);
    }

    public function fetchAllowedUrl(string $url, ?string $tokenUuid): string
    {
        throw new \LogicException('Marketplace sources are not covered by this fixture.');
    }

    public function setHttpClient(ClientInterface $client): void
    {
    }
}
