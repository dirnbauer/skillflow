<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\ViewHelpers;

use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;
use Webconsulting\Skillflow\Service\MarkdownRenderer;
use Webconsulting\Skillflow\Support\Typed;

/**
 * Renders GitHub-flavored Markdown to XSS-safe HTML.
 *
 * The rendered output already contains escaped raw HTML and stripped unsafe
 * links, therefore this ViewHelper sets `$escapeOutput = false` and the
 * surrounding template must NOT wrap it in `f:format.html` or otherwise
 * re-encode the result.
 *
 * Usable both as a tag pair and via the `source` argument:
 *
 * ```
 *   <sf:markdown>{skill.body}</sf:markdown>
 *   <sf:markdown source="{skill.body}" />
 * ```
 */
final class MarkdownViewHelper extends AbstractViewHelper
{
    protected $escapeOutput = false;

    public function __construct(
        private readonly MarkdownRenderer $markdownRenderer,
    ) {}

    public function initializeArguments(): void
    {
        $this->registerArgument('source', 'string', 'Markdown source; defaults to the tag content', false);
    }

    public function render(): string
    {
        $source = $this->arguments['source'] ?? $this->renderChildren();

        return $this->markdownRenderer->toHtml(Typed::string($source));
    }

    /**
     * Allows the `source` argument to be supplied as the tag content.
     */
    public function getContentArgumentName(): string
    {
        return 'source';
    }
}
