<?php

/**
 * PHPStan bootstrap: registers PSR-4 namespaces directly.
 * Bypasses the version-gated autoloader in wt-shared-libs/autoload.php.
 * Dev-only — excluded from release archive.
 */

$loader = new \Composer\Autoload\ClassLoader();
$shared = __DIR__ . '/vendor/bschwede/wt-shared-libs/src/';
$loader->addPsr4('Schwendinger\\Webtrees\\Helpers\\', $shared . 'Helpers');
$loader->addPsr4('Schwendinger\\Webtrees\\Services\\', $shared . 'Services');
$loader->addPsr4('Schwendinger\\Webtrees\\Traits\\', $shared . 'Traits');
$loader->addPsr4('Schwendinger\\Webtrees\\Module\\LinkEnhancer\\', __DIR__ . '/src/');
$loader->register(true);
