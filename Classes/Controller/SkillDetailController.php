<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Controller;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use Webconsulting\Skillflow\Service\SkillFinder;
use Webconsulting\Skillflow\Support\Typed;

/** Frontend catalogue detail for an active nr_llm skill. */
final class SkillDetailController extends ActionController
{
    public function __construct(private readonly SkillFinder $skillFinder) {}

    public function showAction(int $skill = 0): ResponseInterface
    {
        // The route enhancer maps the nr_llm skill name to its record uid.
        $row = $this->skillFinder->findSkillByUid($skill, false);

        if ($row === null) {
            $this->view->assignMultiple(['notFound' => true, 'identifier' => (string)$skill]);
            return $this->htmlResponse()->withStatus(404);
        }

        $frontmatter = Typed::stringKeyedArray(json_decode(Typed::string($row['metadata'] ?? ''), true));
        $meta = $frontmatter + Typed::stringKeyedArray($frontmatter['metadata'] ?? null);
        $category = trim(Typed::string($row['tx_skillflow_search_category'] ?? ''));
        if ($category !== '') {
            $meta['category'] = $category;
        }
        $tags = trim(Typed::string($row['tx_skillflow_search_tags'] ?? '')) ?: ($meta['tags'] ?? []);
        $tagValues = is_string($tags) ? explode(',', $tags) : (is_array($tags) ? $tags : []);
        $meta['tags'] = array_values(array_filter(
            array_map(static fn(mixed $tag): string => trim(Typed::string($tag)), $tagValues),
            static fn(string $tag): bool => $tag !== '',
        ));
        $this->view->assignMultiple(['skill' => $row, 'meta' => $meta]);
        return $this->htmlResponse();
    }
}
