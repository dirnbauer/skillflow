<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') or die();

ExtensionManagementUtility::addTCAcolumns('pages', [
    'tx_webconskills_skills' => [
        'label' => 'LLL:EXT:webcon_skills/Resources/Private/Language/locallang_db.xlf:pages.skills',
        'description' => 'LLL:EXT:webcon_skills/Resources/Private/Language/locallang_db.xlf:pages.skills.description',
        'config' => [
            'type' => 'select',
            'renderType' => 'selectMultipleSideBySide',
            'foreign_table' => 'tx_webconskills_skill',
            'foreign_table_where' => 'AND {#tx_webconskills_skill}.{#hidden} = 0 ORDER BY tx_webconskills_skill.title',
            'size' => 6,
        ],
    ],
]);

ExtensionManagementUtility::addToAllTCAtypes(
    'pages',
    '--div--;LLL:EXT:webcon_skills/Resources/Private/Language/locallang_db.xlf:tab.skills, tx_webconskills_skills'
);
