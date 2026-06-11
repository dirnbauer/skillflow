<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') or die();

ExtensionManagementUtility::addTCAcolumns('sys_workspace_stage', [
    'tx_webconskills_skills' => [
        'label' => 'LLL:EXT:webcon_skills/Resources/Private/Language/locallang_db.xlf:stage.skills',
        'description' => 'LLL:EXT:webcon_skills/Resources/Private/Language/locallang_db.xlf:stage.skills.description',
        'config' => [
            'type' => 'select',
            'renderType' => 'selectMultipleSideBySide',
            'foreign_table' => 'tx_webconskills_skill',
            'foreign_table_where' => 'AND {#tx_webconskills_skill}.{#hidden} = 0 ORDER BY tx_webconskills_skill.title',
            'size' => 6,
        ],
    ],
    'tx_webconskills_auto_run' => [
        'label' => 'LLL:EXT:webcon_skills/Resources/Private/Language/locallang_db.xlf:stage.auto_run',
        'description' => 'LLL:EXT:webcon_skills/Resources/Private/Language/locallang_db.xlf:stage.auto_run.description',
        'config' => [
            'type' => 'check',
            'renderType' => 'checkboxToggle',
            'default' => 0,
        ],
    ],
]);

ExtensionManagementUtility::addToAllTCAtypes(
    'sys_workspace_stage',
    '--div--;LLL:EXT:webcon_skills/Resources/Private/Language/locallang_db.xlf:tab.skills, tx_webconskills_skills, tx_webconskills_auto_run'
);
