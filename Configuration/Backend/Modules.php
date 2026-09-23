<?php

declare(strict_types=1);

use Webconsulting\Skillflow\Controller\SkillsModuleController;

/**
 * Content → Skills: run skills on the page selected in the page tree.
 */
return [
    'content_skillflow' => [
        'parent' => 'content',
        'position' => ['bottom'],
        'access' => 'user',
        'workspaces' => '*',
        'path' => '/module/content/skillflow',
        'iconIdentifier' => 'skillflow-module',
        'labels' => 'skillflow.modules.skills',
        'routes' => [
            '_default' => [
                'target' => SkillsModuleController::class . '::handleRequest',
            ],
        ],
    ],
];
