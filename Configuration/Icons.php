<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider;

return [
    'webconskills-module' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:webcon_skills/Resources/Public/Icons/Module.svg',
    ],
    'webconskills-skill' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:webcon_skills/Resources/Public/Icons/Skill.svg',
    ],
    'webconskills-repository' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:webcon_skills/Resources/Public/Icons/Repository.svg',
    ],
    'webconskills-run' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:webcon_skills/Resources/Public/Icons/Run.svg',
    ],
];
