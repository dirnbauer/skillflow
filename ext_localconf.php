<?php

declare(strict_types=1);

use Webconsulting\Skillflow\Hooks\DataHandlerHook;

defined('TYPO3') or die();

// React to workspace stage changes (run skills assigned to the target stage)
// and to new records created in a workspace (auto-start the review workflow).
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processCmdmapClass']['skillflow'] = DataHandlerHook::class;
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass']['skillflow'] = DataHandlerHook::class;

// Frontend plugin "Skill detail" (CType skillflow_skilldetail): render one skill by identifier.
\TYPO3\CMS\Extbase\Utility\ExtensionUtility::configurePlugin(
    'Skillflow',
    'SkillDetail',
    [\Webconsulting\Skillflow\Controller\SkillDetailController::class => 'show'],
    [],
);

// The detail view renders the page's h1 itself (the skill title, or "Skill not
// found"), and its Markdown body starts at h2. Themes that render a page-title
// h1 of their own read the registry lib.pageHeadingOwnedByContent (Desiderio
// 4.4+) and step aside when any entry renders something. This entry does so on
// every page holding a skill detail element. It only adds a key; a theme that
// does not know the registry never renders it, so there is no dependency.
// The key is arbitrary but should not clash: 7545 spells SKIL on a keypad.
\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addTypoScriptSetup(<<<'TYPOSCRIPT'
lib.pageHeadingOwnedByContent.7545 = TEXT
lib.pageHeadingOwnedByContent.7545 {
  value = 1
  if.isTrue.numRows {
    table = tt_content
    select {
      pidInList = this
      where = {#CType} = 'skillflow_skilldetail'
    }
  }
}
TYPOSCRIPT);
