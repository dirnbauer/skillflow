<?php

declare(strict_types=1);

use Webconsulting\Skillflow\Hooks\DataHandlerHook;

defined('TYPO3') or die();

// React to workspace stage changes (run skills assigned to the target stage)
// and to new records created in a workspace (auto-start the review workflow).
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processCmdmapClass']['skillflow'] = DataHandlerHook::class;
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass']['skillflow'] = DataHandlerHook::class;
