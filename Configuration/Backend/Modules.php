<?php

declare(strict_types=1);

use Webconsulting\Skillflow\Controller\SkillsModuleController;

/**
 * Backend module "Skills" in the Content module group.
 */
return [
    'content_skillflow' => [
        'parent' => 'content',
        'position' => ['bottom'],
        'access' => 'user',
        'workspaces' => '*',
        'path' => '/module/content/skillflow',
        'iconIdentifier' => 'skillflow-module',
        'labels' => 'LLL:EXT:skillflow/Resources/Private/Language/locallang_mod.xlf',
        'routes' => [
            '_default' => [
                'target' => SkillsModuleController::class . '::handleRequest',
            ],
        ],
    ],
];
