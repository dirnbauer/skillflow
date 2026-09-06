<?php

declare(strict_types=1);

if (getenv('IS_DDEV_PROJECT') === 'true') {
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['trustedHostsPattern'] = '^skillflow\\.ddev\\.site(?::[0-9]+)?$';
    $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport'] = 'smtp';
    $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport_smtp_server'] = 'localhost:1025';
}
