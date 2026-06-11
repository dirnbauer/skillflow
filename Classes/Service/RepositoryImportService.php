<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Service;

use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Webconsulting\Skillflow\Domain\ImportResult;
use Webconsulting\Skillflow\Support\Typed;

/**
 * Imports/updates skills from a remote git hosting provider (GitHub, GitLab,
 * Gitea/Codeberg or any direct zip URL). The repository archive is downloaded
 * over HTTPS, extracted to var/transient and handed to the folder importer.
 *
 * Re-running the sync refetches the archive and overwrites changed skills
 * while keeping their uids (and therefore all stage/page assignments) stable.
 *
 * Credentials: for private repositories the record only stores the NAME of an
 * environment variable; the token itself never touches the database.
 */
final class RepositoryImportService
{
    public function __construct(
        private readonly SkillImportService $skillImportService,
        private readonly SkillFinder $skillFinder,
        private readonly RequestFactory $requestFactory,
        private readonly ConnectionPool $connectionPool,
    ) {
    }

    public function sync(int $repositoryUid): ImportResult
    {
        $repository = $this->skillFinder->findRepositoryByUid($repositoryUid);
        if ($repository === null) {
            throw new \RuntimeException('Repository record ' . $repositoryUid . ' not found', 1760000010);
        }

        $workDirectory = '';
        try {
            $workDirectory = $this->createWorkDirectory();
            $archiveFile = $this->download($repository, $workDirectory);
            $extractedRoot = $this->extract($archiveFile, $workDirectory);

            $importPath = $extractedRoot;
            $subfolder = trim(Typed::string($repository['subfolder'] ?? null), '/');
            if ($subfolder !== '') {
                $importPath .= '/' . $subfolder;
            }

            $result = $this->skillImportService->importFromPath($importPath, 'repository', $repositoryUid);
            $this->updateRepositoryRecord($repositoryUid, $result->errors === [] ? '' : implode(' | ', $result->errors));
            return $result;
        } catch (\Throwable $e) {
            $this->updateRepositoryRecord($repositoryUid, $e->getMessage());
            throw $e;
        } finally {
            if ($workDirectory !== '' && is_dir($workDirectory)) {
                GeneralUtility::rmdir($workDirectory, true);
            }
        }
    }

    private function createWorkDirectory(): string
    {
        $directory = Environment::getVarPath() . '/transient/skillflow/' . bin2hex(random_bytes(8));
        GeneralUtility::mkdir_deep($directory);
        return $directory;
    }

    /**
     * @param array<string, mixed> $repository
     */
    private function download(array $repository, string $workDirectory): string
    {
        $token = $this->resolveToken($repository);
        $archiveUrl = $this->buildArchiveUrl($repository, $token !== null);

        $headers = ['User-Agent' => 'TYPO3-skillflow'];
        if ($token !== null) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        $response = $this->requestFactory->request($archiveUrl, 'GET', [
            'headers' => $headers,
            'allow_redirects' => true,
            'timeout' => 60,
        ]);
        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException(
                sprintf('Download of "%s" failed with HTTP %d', $archiveUrl, $response->getStatusCode()),
                1760000011
            );
        }

        $archiveFile = $workDirectory . '/archive.zip';
        file_put_contents($archiveFile, $response->getBody()->getContents());
        return $archiveFile;
    }

    /**
     * @param array<string, mixed> $repository
     */
    private function resolveToken(array $repository): ?string
    {
        $envVar = trim(Typed::string($repository['token_env_var'] ?? null));
        if ($envVar === '') {
            return null;
        }
        $token = getenv($envVar);
        if ($token === false || $token === '') {
            throw new \RuntimeException(
                sprintf('Repository "%s" expects an access token in env var "%s", but it is not set', Typed::string($repository['title']), $envVar),
                1760000012
            );
        }
        return $token;
    }

    /**
     * @param array<string, mixed> $repository
     */
    private function buildArchiveUrl(array $repository, bool $withToken): string
    {
        $url = rtrim(trim(Typed::string($repository['url'])), '/');
        $branch = trim(Typed::string($repository['branch'] ?? null)) ?: 'main';

        if (str_ends_with($url, '.zip')) {
            return $url;
        }
        $url = (string)preg_replace('/\.git$/', '', $url);
        $host = (string)parse_url($url, PHP_URL_HOST);
        $path = trim((string)parse_url($url, PHP_URL_PATH), '/');

        if ($host === 'github.com') {
            // Private repos need the API zipball endpoint, public ones can use codeload
            return $withToken
                ? sprintf('https://api.github.com/repos/%s/zipball/%s', $path, rawurlencode($branch))
                : sprintf('https://codeload.github.com/%s/zip/refs/heads/%s', $path, rawurlencode($branch));
        }
        if (str_contains($host, 'gitlab')) {
            $project = basename($path);
            return sprintf('%s/-/archive/%s/%s-%s.zip', $url, rawurlencode($branch), $project, rawurlencode($branch));
        }
        // Gitea / Forgejo / Codeberg style
        return sprintf('%s/archive/%s.zip', $url, rawurlencode($branch));
    }

    private function extract(string $archiveFile, string $workDirectory): string
    {
        $extractDirectory = $workDirectory . '/extracted';
        GeneralUtility::mkdir_deep($extractDirectory);

        $zip = new \ZipArchive();
        if ($zip->open($archiveFile) !== true) {
            throw new \RuntimeException('Could not open downloaded archive', 1760000013);
        }
        if (!$zip->extractTo($extractDirectory)) {
            $zip->close();
            throw new \RuntimeException('Could not extract downloaded archive', 1760000014);
        }
        $zip->close();

        // Hosting providers wrap the content in a single top-level directory
        $entries = array_values(array_filter(
            scandir($extractDirectory) ?: [],
            static fn(string $entry): bool => $entry !== '.' && $entry !== '..'
        ));
        if (count($entries) === 1 && is_dir($extractDirectory . '/' . $entries[0])) {
            return $extractDirectory . '/' . $entries[0];
        }
        return $extractDirectory;
    }

    private function updateRepositoryRecord(int $repositoryUid, string $error): void
    {
        $this->connectionPool->getConnectionForTable('tx_skillflow_repository')->update(
            'tx_skillflow_repository',
            [
                'last_synced' => time(),
                'last_error' => mb_substr($error, 0, 60000),
                'tstamp' => time(),
            ],
            ['uid' => $repositoryUid]
        );
    }
}
