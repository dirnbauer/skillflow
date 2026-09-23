<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Service;

use League\CommonMark\Environment\EnvironmentInterface;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\GithubFlavoredMarkdownConverter;
use League\CommonMark\Parser\MarkdownParser;
use League\CommonMark\Renderer\HtmlRenderer;

/**
 * Renders GitHub-flavored Markdown (skill bodies, file contents) into HTML.
 *
 * XSS-safe by configuration: raw HTML in the Markdown source is escaped
 * (`html_input => escape`) and unsafe links such as `javascript:` URIs are
 * stripped (`allow_unsafe_links => false`). The produced HTML is therefore
 * safe to emit unescaped in Fluid templates.
 *
 * A heading offset moves every heading down that many levels (capped at h6),
 * so a document whose `# Title` would be a second h1 fits under the heading
 * of the page or section that embeds it.
 */
final class MarkdownRenderer
{
    public const int MAX_HEADING_LEVEL = 6;

    private ?EnvironmentInterface $environment = null;

    private ?MarkdownParser $parser = null;

    private ?HtmlRenderer $renderer = null;

    public function toHtml(string $markdown, int $headingOffset = 0): string
    {
        if ($markdown === '') {
            return '';
        }

        $document = $this->getParser()->parse($markdown);
        if ($headingOffset > 0) {
            foreach ($document->iterator() as $node) {
                if ($node instanceof Heading) {
                    $node->setLevel(min(self::MAX_HEADING_LEVEL, $node->getLevel() + $headingOffset));
                }
            }
        }

        return (string)$this->getRenderer()->renderDocument($document);
    }

    private function getParser(): MarkdownParser
    {
        return $this->parser ??= new MarkdownParser($this->getEnvironment());
    }

    private function getRenderer(): HtmlRenderer
    {
        return $this->renderer ??= new HtmlRenderer($this->getEnvironment());
    }

    private function getEnvironment(): EnvironmentInterface
    {
        return $this->environment ??= new GithubFlavoredMarkdownConverter([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ])->getEnvironment();
    }
}
