<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Service;

/**
 * Resolves the relative links of a SKILL.md rendered on a skill detail page.
 *
 * A skill links the files next to it (`references/full-guide.md`,
 * `assets/template.md`) and other skills (`../typo3-seo/SKILL.md`). On the
 * website those paths would resolve below the detail page's URL, where
 * nothing exists. They point instead to
 *
 * - the detail page of the linked skill, when `$pageUrl` knows one for its
 *   repository path;
 * - the file in the skill's repository (`$fileBaseUrl`, and `$rawBaseUrl` for
 *   images), resolved against the directory of `$documentPath`;
 * - nowhere, when neither is known or the path leaves the repository: the
 *   link text stays, the link goes.
 *
 * Absolute URLs, root-relative paths, protocol-relative URLs and in-page
 * anchors are kept.
 */
final readonly class SkillDocumentLinkResolver implements RelativeLinkResolverInterface
{
    /**
     * @param string $documentPath Path of the Markdown file in its repository, e.g. `skills/typo3-seo/SKILL.md`
     * @param string $fileBaseUrl URL a repository path is appended to for links, e.g. `https://github.com/o/r/blob/<ref>/`; '' when unknown
     * @param string $rawBaseUrl URL a repository path is appended to for images, e.g. `https://github.com/o/r/raw/<ref>/`; '' when unknown
     * @param \Closure(string): ?string $pageUrl Maps a repository path to the URL of a page that renders it, null when none does
     */
    public function __construct(
        private string $documentPath,
        private string $fileBaseUrl,
        private string $rawBaseUrl,
        private \Closure $pageUrl,
    ) {}

    /**
     * Repository file and raw URLs for the repository hosts whose URL layout
     * is known (GitHub, GitLab); ['', ''] for any other host.
     *
     * @return array{0: string, 1: string} file base URL, raw base URL
     */
    public static function repositoryBaseUrls(string $repositoryUrl, string $revision): array
    {
        $repositoryUrl = rtrim(trim($repositoryUrl), '/');
        if (str_ends_with($repositoryUrl, '.git')) {
            $repositoryUrl = substr($repositoryUrl, 0, -4);
        }
        $revision = trim($revision);
        $host = strtolower((string)parse_url($repositoryUrl, PHP_URL_HOST));
        if ($revision === '' || $host === '' || !str_starts_with($repositoryUrl, 'https://')) {
            return ['', ''];
        }
        $revision = rawurlencode($revision);

        return match (true) {
            $host === 'github.com' => [$repositoryUrl . '/blob/' . $revision . '/', $repositoryUrl . '/raw/' . $revision . '/'],
            $host === 'gitlab.com' || str_starts_with($host, 'gitlab.') => [$repositoryUrl . '/-/blob/' . $revision . '/', $repositoryUrl . '/-/raw/' . $revision . '/'],
            default => ['', ''],
        };
    }

    #[\Override]
    public function resolve(string $url, bool $isImage): ?string
    {
        $url = trim($url);
        if ($url === '' || str_starts_with($url, '#') || str_starts_with($url, '/') || preg_match('/^[a-z][a-z0-9+.-]*:/i', $url) === 1) {
            return null;
        }

        $fragment = '';
        $hashPosition = strpos($url, '#');
        if ($hashPosition !== false) {
            $fragment = substr($url, $hashPosition);
            $url = substr($url, 0, $hashPosition);
        }
        $query = '';
        $queryPosition = strpos($url, '?');
        if ($queryPosition !== false) {
            $query = substr($url, $queryPosition);
            $url = substr($url, 0, $queryPosition);
        }

        $path = $this->resolvePath(rawurldecode($url));
        if ($path === null) {
            return '';
        }

        if (!$isImage) {
            $pageUrl = ($this->pageUrl)($path);
            if ($pageUrl !== null && $pageUrl !== '') {
                return $pageUrl . $fragment;
            }
        }

        $baseUrl = $isImage ? $this->rawBaseUrl : $this->fileBaseUrl;
        if ($baseUrl === '') {
            return '';
        }

        return $baseUrl . implode('/', array_map(rawurlencode(...), explode('/', $path))) . $query . $fragment;
    }

    /**
     * The repository path a relative URL names, seen from the document's
     * directory; null when it leaves the repository or names no file.
     */
    private function resolvePath(string $relativeUrl): ?string
    {
        $directory = dirname($this->documentPath);
        $segments = $directory === '.' || $directory === '' ? [] : explode('/', $directory);
        foreach (explode('/', $relativeUrl) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($segments === []) {
                    return null;
                }
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        return $segments === [] ? null : implode('/', $segments);
    }
}
