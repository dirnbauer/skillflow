<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') or die();

ExtensionManagementUtility::addTCAcolumns('sys_workspace_stage', [
    'tx_skillflow_skills' => [
        'label' => 'LLL:EXT:skillflow/Resources/Private/Language/locallang_db.xlf:stage.skills',
        'description' => 'LLL:EXT:skillflow/Resources/Private/Language/locallang_db.xlf:stage.skills.description',
        'config' => [
            'type' => 'select',
            'renderType' => 'selectMultipleSideBySide',
            'foreign_table' => 'tx_skillflow_skill',
            'foreign_table_where' => 'AND {#tx_skillflow_skill}.{#hidden} = 0 ORDER BY tx_skillflow_skill.title',
            'size' => 6,
        ],
    ],
    'tx_skillflow_auto_run' => [
        'label' => 'LLL:EXT:skillflow/Resources/Private/Language/locallang_db.xlf:stage.auto_run',
        'description' => 'LLL:EXT:skillflow/Resources/Private/Language/locallang_db.xlf:stage.auto_run.description',
        'config' => [
            'type' => 'check',
            'renderType' => 'checkboxToggle',
            'default' => 0,
        ],
    ],
]);

ExtensionManagementUtility::addToAllTCAtypes(
    'sys_workspace_stage',
    '--div--;LLL:EXT:skillflow/Resources/Private/Language/locallang_db.xlf:tab.skills, tx_skillflow_skills, tx_skillflow_auto_run'
);
