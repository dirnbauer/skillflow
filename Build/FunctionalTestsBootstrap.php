<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$testbase = new TYPO3\TestingFramework\Core\Testbase();
$testbase->defineOriginalRootPath();
$testbase->createDirectory(ORIGINAL_ROOT . 'typo3temp/var/tests');
$testbase->createDirectory(ORIGINAL_ROOT . 'typo3temp/var/transient');
