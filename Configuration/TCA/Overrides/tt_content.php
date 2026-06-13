<?php

declare(strict_types=1);

use TYPO3\CMS\Extbase\Utility\ExtensionUtility;

defined('TYPO3') or die();

$cType = ExtensionUtility::registerPlugin(
    'Skillflow',
    'SkillDetail',
    'LLL:EXT:skillflow/Resources/Private/Language/locallang_db.xlf:plugin.skilldetail.title',
    'skillflow-skill',
    'skillflow',
    'LLL:EXT:skillflow/Resources/Private/Language/locallang_db.xlf:plugin.skilldetail.description',
);

$GLOBALS['TCA']['tt_content']['ctrl']['typeicon_classes'][$cType] = 'skillflow-skill';

$GLOBALS['TCA']['tt_content']['columns']['CType']['config']['itemGroups']['skillflow']
    = 'LLL:EXT:skillflow/Resources/Private/Language/locallang_db.xlf:plugin.group';
