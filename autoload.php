<?php

use Composer\Autoload\ClassLoader;

$loader = new ClassLoader();
$loader->addPsr4('Schwendinger\\Webtrees\\Module\\LinkEnhancer\\', __DIR__);
$loader->addPsr4('Schwendinger\\Webtrees\\Module\\LinkEnhancer\\Factories\\', __DIR__ . "/Factories");
$loader->addPsr4('Schwendinger\\Webtrees\\Module\\LinkEnhancer\\Schema\\', __DIR__ . "/Schema");
$loader->addPsr4('Schwendinger\\Webtrees\\Module\\LinkEnhancer\\Services\\', __DIR__ . "/Services");
$loader->addPsr4('Schwendinger\\Webtrees\\Module\\LinkEnhancer\\CommonMark\\', __DIR__ . "/CommonMark");
$loader->register();