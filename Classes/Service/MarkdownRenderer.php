<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Service;

use League\CommonMark\GithubFlavoredMarkdownConverter;

/**
 * Renders GitHub-flavored Markdown (skill bodies, file contents) into HTML.
 *
 * XSS-safe by configuration: raw HTML in the Markdown source is escaped
 * (`html_input => escape`) and unsafe links such as `javascript:` URIs are
 * stripped (`allow_unsafe_links => false`). The produced HTML is therefore
 * safe to emit unescaped in Fluid templates.
 */
final class MarkdownRenderer
{
    private ?GithubFlavoredMarkdownConverter $converter = null;

    public function toHtml(string $markdown): string
    {
        if ($markdown === '') {
            return '';
        }

        return (string)$this->getConverter()->convert($markdown);
    }

    private function getConverter(): GithubFlavoredMarkdownConverter
    {
        return $this->converter ??= new GithubFlavoredMarkdownConverter([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]);
    }
}
