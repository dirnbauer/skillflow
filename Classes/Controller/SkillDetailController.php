<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Controller;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use Webconsulting\Skillflow\Service\MarkdownRenderer;
use Webconsulting\Skillflow\Service\SkillFinder;
use Webconsulting\Skillflow\Support\Typed;

/**
 * Frontend plugin "Skill detail": render a single skill by its identifier.
 */
final class SkillDetailController extends ActionController
{
    public function __construct(
        private readonly SkillFinder $skillFinder,
        private readonly MarkdownRenderer $markdownRenderer,
    ) {
    }

    public function showAction(int $skill = 0): ResponseInterface
    {
        // The 'SkillDetail' route enhancer uses a PersistedAliasMapper aspect on
        // the 'identifier' field (tableName tx_skillflow_skill, routeFieldName
        // identifier), so the speaking URL segment resolves to the record uid
        // before Extbase passes it here as int $skill. There is no string fallback.
        $row = $skill > 0 ? $this->skillFinder->findSkillByUid($skill) : null;

        if ($row === null) {
            $this->view->assign('notFound', true);
            $this->view->assign('identifier', (string)$skill);
            return $this->htmlResponse();
        }

        $metadata = Typed::string($row['metadata'] ?? '');
        $meta = [];
        if ($metadata !== '') {
            $decoded = json_decode($metadata, true);
            if (is_array($decoded)) {
                $meta = $decoded;
            }
        }

        $this->view->assignMultiple([
            'skill' => $row,
            'meta' => $meta,
            'files' => $this->skillFinder->findFilesForSkill(Typed::int($row['uid'] ?? 0)),
            'bodyHtml' => $this->markdownRenderer->toHtml(Typed::string($row['body'] ?? '')),
        ]);

        return $this->htmlResponse();
    }
}
