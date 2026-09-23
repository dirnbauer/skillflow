<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Service;

use League\CommonMark\Environment\EnvironmentInterface;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Inline\AbstractWebResource;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\GithubFlavoredMarkdownConverter;
use League\CommonMark\Node\Node;
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
 *
 * A relative link resolver rewrites the links and images whose target is
 * relative to the Markdown file (`references/guide.md`), which would break
 * once the document is rendered on a page elsewhere.
 */
final class MarkdownRenderer
{
    public const int MAX_HEADING_LEVEL = 6;

    private ?EnvironmentInterface $environment = null;

    private ?MarkdownParser $parser = null;

    private ?HtmlRenderer $renderer = null;

    public function toHtml(string $markdown, int $headingOffset = 0, ?RelativeLinkResolverInterface $links = null): string
    {
        if ($markdown === '') {
            return '';
        }

        $document = $this->getParser()->parse($markdown);
        $webResources = [];
        foreach ($document->iterator() as $node) {
            if ($headingOffset > 0 && $node instanceof Heading) {
                $node->setLevel(min(self::MAX_HEADING_LEVEL, $node->getLevel() + $headingOffset));
            }
            if ($links !== null && $node instanceof AbstractWebResource) {
                $webResources[] = $node;
            }
        }
        if ($links !== null) {
            $this->resolveRelativeLinks($webResources, $links);
        }

        return (string)$this->getRenderer()->renderDocument($document);
    }

    /**
     * Rewritten after the walk over the document: unlinking moves nodes.
     *
     * @param list<AbstractWebResource> $webResources
     */
    private function resolveRelativeLinks(array $webResources, RelativeLinkResolverInterface $links): void
    {
        foreach ($webResources as $webResource) {
            $url = $links->resolve($webResource->getUrl(), $webResource instanceof Image);
            if ($url === '') {
                $this->unwrap($webResource);
            } elseif ($url !== null) {
                $webResource->setUrl($url);
            }
        }
    }

    /** Replaces a link by its text, an image by its alt text. */
    private function unwrap(Node $node): void
    {
        foreach ([...$node->children()] as $child) {
            $node->insertBefore($child);
        }
        $node->detach();
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
