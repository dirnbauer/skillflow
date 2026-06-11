<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider;

return [
    'skillflow-module' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:skillflow/Resources/Public/Icons/Module.svg',
    ],
    'skillflow-skill' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:skillflow/Resources/Public/Icons/Skill.svg',
    ],
    'skillflow-repository' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:skillflow/Resources/Public/Icons/Repository.svg',
    ],
    'skillflow-run' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:skillflow/Resources/Public/Icons/Run.svg',
    ],
];
