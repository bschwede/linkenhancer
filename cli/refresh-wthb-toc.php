<?php

/*
 * webtrees - linkenhancer (custom module)
 *
 * Copyright (C) 2026 Bernd Schwendinger
 *
 * webtrees: online genealogy application
 * Copyright (C) 2026 webtrees development team.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

// Refresh WTHB TOC translations from GitHub Pages.
//
// Run (from the webtrees root):
//   php modules_v4/linkenhancer/cli/refresh-wthb-toc.php              # scope from module pref
//   php modules_v4/linkenhancer/cli/refresh-wthb-toc.php --all        # override: all available
//   php modules_v4/linkenhancer/cli/refresh-wthb-toc.php --lang=en,fr # override: specific
//   php modules_v4/linkenhancer/cli/refresh-wthb-toc.php --clear      # only clear cache
//
// The download scope is read from the module preference WTHB_TOC_DL_SCOPE:
//   all    - all languages from languages.json (except default)
//   active - intersection of active webtrees languages and available TOC languages
//   custom - user-defined list from WTHB_TOC_DL_LANGS
//
// CLI arguments override the pref when given.

require __DIR__ . '/../../../vendor/autoload.php';
require __DIR__ . '/../autoload.php';

use Schwendinger\Webtrees\Module\LinkEnhancer\Services\WthbTocService;
use Schwendinger\Webtrees\Services\CliBootstrap;

CliBootstrap::guard();

// ---------------------------------------------------------------- arguments
$override = null;
$clear_only = false;
$jitter_max = null;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--help' || $arg === '-h') {
        echo 'usage: php ' . basename(__FILE__) . ' [--all] [--lang=<codes>] [--clear] [--jitter=<seconds>]' . PHP_EOL;
        echo '  --jitter=<seconds>  max. Zufallsverzögerung vor dem Refresh (Default 30, Env WTHB_TOC_JITTER)' . PHP_EOL;
        exit(0);
    }
    if ($arg === '--all') {
        $override = 'all';
    }
    if (str_starts_with($arg, '--lang=')) {
        $override = substr($arg, 7);
    }
    if ($arg === '--clear') {
        $clear_only = true;
    }
    if (str_starts_with($arg, '--jitter=')) {
        $jitter_max = (int) substr($arg, 9);
    }
}
// Jitter-Fenster: --jitter > Env WTHB_TOC_JITTER > Default 30
if ($jitter_max === null) {
    $env_jitter = getenv('WTHB_TOC_JITTER');
    $jitter_max = ($env_jitter !== false && $env_jitter !== '') ? (int) $env_jitter : 30;
}

// ---------------------------------------------------------------- bootstrap
CliBootstrap::boot();

$svc = new WthbTocService();

// ---------------------------------------------------------------- clear only
if ($clear_only) {
    $svc->clearCache();
    echo 'Cache cleared.' . PHP_EOL;
    exit(0);
}

// ---------------------------------------------------------------- refresh
// de-thunder: verteilt Requests über ein Jitter-Fenster, damit bei identischen
// Cron-Zeiten nicht alle Instanzen gleichzeitig den Feed anfragen.
$jitter = random_int(0, max(0, $jitter_max));
echo 'Jitter: ' . $jitter . ' s vor dem Refresh.' . PHP_EOL;
if ($jitter > 0) {
    sleep($jitter);
}

$start = microtime(true);

$langs = $svc->resolveLanguages($override);
echo 'Languages: ' . ($langs === [] ? '(none)' : implode(', ', $langs)) . PHP_EOL;

$results = $svc->refresh($langs);

echo 'Downloaded:' . PHP_EOL;
foreach ($results as $file => $count) {
    echo '  ' . $file . ': ' . $count . PHP_EOL;
}

echo sprintf('Done in %.1fs.', microtime(true) - $start) . PHP_EOL;
