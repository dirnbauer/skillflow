<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\ViewHelpers;

use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;
use Webconsulting\Skillflow\Service\MarkdownRenderer;
use Webconsulting\Skillflow\Support\Typed;

/**
 * Renders GitHub-flavored Markdown to XSS-safe HTML.
 *
 * The converter escapes raw HTML and strips unsafe links itself, so the
 * Markdown must reach it verbatim: neither the tag content nor the "source"
 * argument is HTML-escaped by Fluid (escaping first would turn `<Type>` in a
 * code block into a visible `&lt;Type&gt;` and a `>` quote into a paragraph).
 * The output is emitted unescaped; do NOT wrap it in `f:format.html` or
 * otherwise re-encode it.
 *
 * Usable both as a tag pair and via the `source` argument:
 *
 * ```
 *   <sf:markdown>{skill.body}</sf:markdown>
 *   <sf:markdown source="{skill.body}" />
 * ```
 *
 * `headingOffset` moves every Markdown heading down that many levels (capped
 * at h6). Where the page already has its h1 — the skill title on the detail
 * page — `headingOffset="1"` turns the document's `# Title` into an h2, so the
 * page keeps exactly one h1:
 *
 * ```
 *   <sf:markdown source="{skill.body}" headingOffset="1" />
 * ```
 */
final class MarkdownViewHelper extends AbstractViewHelper
{
    protected $escapeOutput = false;

    protected $escapeChildren = false;

    public function __construct(
        private readonly MarkdownRenderer $markdownRenderer,
    ) {}

    #[\Override]
    public function initializeArguments(): void
    {
        $this->registerArgument('source', 'string', 'Markdown source; defaults to the tag content', false, null, false);
        $this->registerArgument('headingOffset', 'int', 'Levels to move every heading down (0 keeps them, 1 turns # into h2); capped at h6', false, 0);
    }

    #[\Override]
    public function render(): string
    {
        $source = $this->arguments['source'] ?? $this->renderChildren();

        return $this->markdownRenderer->toHtml(Typed::string($source), Typed::int($this->arguments['headingOffset'] ?? 0));
    }

    /**
     * Allows the `source` argument to be supplied as the tag content.
     */
    #[\Override]
    public function getContentArgumentName(): string
    {
        return 'source';
    }
}
