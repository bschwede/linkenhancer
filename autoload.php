<?php

use Composer\Autoload\ClassLoader;

require __DIR__ . '/vendor/bschwede/wt-shared-libs/autoload.php';

$loader = new ClassLoader();
$loader->addPsr4('Schwendinger\\Webtrees\\Module\\LinkEnhancer\\', __DIR__ . '/src/');
$loader->register();