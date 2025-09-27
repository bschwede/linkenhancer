<?php

use Composer\Autoload\ClassLoader;

$loader = new ClassLoader();
$loader->addPsr4('Schwendinger\\Webtrees\\Module\\LinkEnhancer\\', __DIR__);
$loader->addPsr4('Schwendinger\\Webtrees\\Module\\LinkEnhancer\\Schema\\', __DIR__ . "/Schema");
$loader->register();