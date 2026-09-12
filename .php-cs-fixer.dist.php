<?php

declare(strict_types=1);

use TYPO3\CodingStandards\CsFixerConfig;

$config = CsFixerConfig::create();
$config->setCacheFile(__DIR__ . '/.Build/php-cs-fixer.cache');
$config->getFinder()
    ->in([__DIR__ . '/Classes', __DIR__ . '/Configuration', __DIR__ . '/Tests', __DIR__ . '/Build/phpunit'])
    ->append([__DIR__ . '/ext_localconf.php']);

return $config;
