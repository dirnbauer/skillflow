<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') or die();

ExtensionManagementUtility::addTCAcolumns('tx_nrllm_skill', [
    'tx_skillflow_search_category' => [
        'label' => 'LLL:EXT:skillflow/Resources/Private/Language/locallang_db.xlf:search.category',
        'config' => ['type' => 'input', 'max' => 255, 'eval' => 'trim', 'searchable' => true],
    ],
    'tx_skillflow_search_tags' => [
        'label' => 'LLL:EXT:skillflow/Resources/Private/Language/locallang_db.xlf:search.tags',
        'description' => 'LLL:EXT:skillflow/Resources/Private/Language/locallang_db.xlf:search.tags.description',
        'config' => ['type' => 'input', 'max' => 1024, 'eval' => 'trim', 'searchable' => true],
    ],
]);

ExtensionManagementUtility::addToAllTCAtypes(
    'tx_nrllm_skill',
    '--div--;LLL:EXT:skillflow/Resources/Private/Language/locallang_db.xlf:tab.search, tx_skillflow_search_category, tx_skillflow_search_tags',
);
