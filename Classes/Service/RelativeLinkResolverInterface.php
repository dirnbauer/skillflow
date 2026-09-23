<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Service;

/**
 * Decides where a relative link or image in a Markdown document points once
 * the document is rendered somewhere else than next to its source files.
 */
interface RelativeLinkResolverInterface
{
    /**
     * @param string $url The link or image URL exactly as the Markdown source has it
     * @return string|null The URL to use instead; null keeps the URL, '' removes the
     *                     link and keeps its text (an image is replaced by its alt text)
     */
    public function resolve(string $url, bool $isImage): ?string;
}
