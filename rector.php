<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\LevelSetList;
use Rector\ValueObject\PhpVersion;
use Ssch\TYPO3Rector\Set\Typo3LevelSetList;

return RectorConfig::configure()
    ->withPaths([__DIR__ . '/Classes', __DIR__ . '/Configuration', __DIR__ . '/Tests', __DIR__ . '/ext_localconf.php'])
    ->withPhpVersion(PhpVersion::PHP_84)
    ->withSets([LevelSetList::UP_TO_PHP_84, Typo3LevelSetList::UP_TO_TYPO3_14]);
