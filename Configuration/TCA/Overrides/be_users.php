<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') or die();

ExtensionManagementUtility::addTCAcolumns('be_users', [
    'tx_skillflow_nrllm_skills' => [
        'label' => 'LLL:EXT:skillflow/Resources/Private/Language/locallang_db.xlf:users.skills',
        'description' => 'LLL:EXT:skillflow/Resources/Private/Language/locallang_db.xlf:users.skills.description',
        'config' => [
            'type' => 'select',
            'renderType' => 'selectMultipleSideBySide',
            'foreign_table' => 'tx_nrllm_skill',
            'foreign_table_where' => 'AND {#tx_nrllm_skill}.{#hidden} = 0 AND {#tx_nrllm_skill}.{#enabled} = 1 AND {#tx_nrllm_skill}.{#orphaned} = 0 ORDER BY tx_nrllm_skill.name',
            'size' => 6,
        ],
    ],
]);

ExtensionManagementUtility::addToAllTCAtypes(
    'be_users',
    '--div--;LLL:EXT:skillflow/Resources/Private/Language/locallang_db.xlf:tab.skills, tx_skillflow_nrllm_skills',
);
