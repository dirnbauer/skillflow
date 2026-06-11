<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') or die();

ExtensionManagementUtility::addTCAcolumns('sys_workspace', [
    'tx_webconskills_auto_workflow' => [
        'label' => 'LLL:EXT:webcon_skills/Resources/Private/Language/locallang_db.xlf:workspace.auto_workflow',
        'description' => 'LLL:EXT:webcon_skills/Resources/Private/Language/locallang_db.xlf:workspace.auto_workflow.description',
        'config' => [
            'type' => 'check',
            'renderType' => 'checkboxToggle',
            'default' => 0,
        ],
    ],
    'tx_webconskills_auto_workflow_stage' => [
        'label' => 'LLL:EXT:webcon_skills/Resources/Private/Language/locallang_db.xlf:workspace.auto_workflow_stage',
        'description' => 'LLL:EXT:webcon_skills/Resources/Private/Language/locallang_db.xlf:workspace.auto_workflow_stage.description',
        'displayCond' => 'FIELD:tx_webconskills_auto_workflow:REQ:true',
        'config' => [
            'type' => 'select',
            'renderType' => 'selectSingle',
            'foreign_table' => 'sys_workspace_stage',
            'foreign_table_where' => 'AND {#sys_workspace_stage}.{#parentid} = ###THIS_UID### AND {#sys_workspace_stage}.{#parenttable} = \'sys_workspace\'',
            'items' => [
                ['label' => 'LLL:EXT:webcon_skills/Resources/Private/Language/locallang_db.xlf:workspace.auto_workflow_stage.none', 'value' => 0],
            ],
            'default' => 0,
        ],
    ],
]);

ExtensionManagementUtility::addToAllTCAtypes(
    'sys_workspace',
    '--div--;LLL:EXT:webcon_skills/Resources/Private/Language/locallang_db.xlf:tab.skills, tx_webconskills_auto_workflow, tx_webconskills_auto_workflow_stage'
);
