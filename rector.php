<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\ValueObject\PhpVersion;
use Ssch\TYPO3Rector\Set\Typo3SetList;

return RectorConfig::configure()
    ->withPaths([__DIR__ . '/Classes', __DIR__ . '/Configuration', __DIR__ . '/ext_localconf.php'])
    ->withPhpVersion(PhpVersion::PHP_84)
    ->withSets([Typo3SetList::TYPO3_14]);
