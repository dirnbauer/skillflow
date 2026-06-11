<?php

declare(strict_types=1);

use Webconsulting\Skills\Controller\SkillsModuleController;

/**
 * Backend module "Skills" in the Content module group.
 */
return [
    'content_webconskills' => [
        'parent' => 'content',
        'position' => ['bottom'],
        'access' => 'user',
        'workspaces' => '*',
        'path' => '/module/content/webconskills',
        'iconIdentifier' => 'webconskills-module',
        'labels' => 'LLL:EXT:webcon_skills/Resources/Private/Language/locallang_mod.xlf',
        'routes' => [
            '_default' => [
                'target' => SkillsModuleController::class . '::handleRequest',
            ],
        ],
    ],
];
