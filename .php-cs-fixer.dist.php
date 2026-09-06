<?php

declare(strict_types=1);

return (new PhpCsFixer\Config())
    ->setRules(['@PSR12' => true, 'no_unused_imports' => true])
    ->setCacheFile(__DIR__ . '/.Build/php-cs-fixer.cache')
    ->setFinder(PhpCsFixer\Finder::create()->in([
        __DIR__ . '/Classes', __DIR__ . '/Configuration', __DIR__ . '/Tests',
    ])->append([__DIR__ . '/ext_localconf.php']));
