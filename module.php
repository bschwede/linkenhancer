<?php

/**
 * LinkEnhancer
 */

declare(strict_types=1);

namespace Schwendinger\Webtrees\Module\LinkEnhancer;

use Fisharebest\Webtrees\Webtrees;
use Illuminate\Support\Collection;

require_once __DIR__ . '/CustomMarkdownFactory.php';
require_once __DIR__ . '/LinkEnhancerModule.php';
require_once __DIR__ . '/Schema/Migration0.php';
require_once __DIR__ . '/Schema/SeedHelpTable.php';


//+++ taken from https://github.com/vesta-webtrees-2-custom-modules/vesta_classic_laf/blob/master/module.php
//webtrees major version switch
if (defined("WT_MODULES_DIR")) {
    //this is a webtrees 2.x module. it cannot be used with webtrees 1.x. See README.md.
    return;
}

//cf ModuleService
$pattern = Webtrees::MODULES_DIR . '*/autoload.php';
$filenames = glob($pattern, GLOB_NOSORT);

Collection::make($filenames)
    ->filter(static function (string $filename): bool {
        // Special characters will break PHP variable names.
        // This also allows us to ignore modules called "foo.example" and "foo.disable"
        $module_name = basename(dirname($filename));

        foreach (['.', ' ', '[', ']'] as $character) {
            if (str_contains($module_name, $character)) {
                return false;
            }
        }

        return strlen($module_name) <= 30;
    })
    ->each(static function (string $filename): void {
        require_once $filename;
    });
//---

$vesta_installed = class_exists("Cissee\WebtreesExt\AbstractModule", false);
if ($vesta_installed) {
    require_once __DIR__ . '/LinkEnhancerModuleExt.php';
    return new LinkEnhancerModuleExt();
} else {
    return new LinkEnhancerModule();
}