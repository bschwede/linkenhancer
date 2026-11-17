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

// Update the help-mapping data (route→help-URL CSV) from the wt-modules docs site.
//
// Run (from the webtrees root):
//   php modules_v4/linkenhancer/cli/update-help-mapping.php            # normal (hash-check)
//   php modules_v4/linkenhancer/cli/update-help-mapping.php --force    # skip hash-check, always download
//   php modules_v4/linkenhancer/cli/update-help-mapping.php --clear    # remove local cache
//   php modules_v4/linkenhancer/cli/update-help-mapping.php --status   # show current state
//
// Auto-update gate: when the module preference HELP_MAPPING_AUTO_UPDATE is '0',
// the refresh is skipped (exit 0) unless --force is given. --clear and --status
// are not affected by the gate.
//
// De-thunder: a random jitter delay (default 30 s, env HELP_MAPPING_JITTER)
// is applied before the download, so that instances with identical cron times
// don't all hit the feed simultaneously.

require __DIR__ . '/../../../vendor/autoload.php';
require __DIR__ . '/../autoload.php';

use Schwendinger\Webtrees\Module\LinkEnhancer\Services\HelpMappingService;
use Schwendinger\Webtrees\Services\CliBootstrap;

CliBootstrap::guard();

// ---------------------------------------------------------------- arguments
$force = false;
$clear_only = false;
$status_only = false;
$jitter_max = null;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--help' || $arg === '-h') {
        echo 'usage: php ' . basename(__FILE__) . ' [--force] [--clear] [--status] [--jitter=<seconds>]' . PHP_EOL;
        echo '  --force             run even if auto-update is disabled (HELP_MAPPING_AUTO_UPDATE=0)' . PHP_EOL;
        echo '  --clear             only clear local cache, no download' . PHP_EOL;
        echo '  --status            show current state (applied hash, bundled hash, auto-update toggle)' . PHP_EOL;
        echo '  --jitter=<seconds>  max. random delay before refresh (default 30, env HELP_MAPPING_JITTER)' . PHP_EOL;
        exit(0);
    }
    if ($arg === '--force') {
        $force = true;
    }
    if ($arg === '--clear') {
        $clear_only = true;
    }
    if ($arg === '--status') {
        $status_only = true;
    }
    if (str_starts_with($arg, '--jitter=')) {
        $jitter_max = (int) substr($arg, 9);
    }
}
// Jitter-Fenster: --jitter > Env HELP_MAPPING_JITTER > Default 30
if ($jitter_max === null) {
    $env_jitter = getenv('HELP_MAPPING_JITTER');
    $jitter_max = ($env_jitter !== false && $env_jitter !== '') ? (int) $env_jitter : 30;
}

// ---------------------------------------------------------------- bootstrap
CliBootstrap::boot();

$svc = new HelpMappingService();

// ---------------------------------------------------------------- status
if ($status_only) {
    echo 'Help-mapping status:' . PHP_EOL;
    echo '  Base URL:         ' . $svc->baseUrl() . PHP_EOL;
    echo '  Bundled hash:     ' . $svc->bundledHash() . PHP_EOL;
    echo '  Applied hash:     ' . $svc->currentAppliedHash() . PHP_EOL;
    echo '  Auto-update:      ' . ($svc->autoUpdateEnabled() ? 'enabled' : 'disabled') . PHP_EOL;
    exit(0);
}

// ---------------------------------------------------------------- clear only
if ($clear_only) {
    $svc->clearCache();
    echo 'Cache cleared.' . PHP_EOL;
    exit(0);
}

// ---------------------------------------------------------------- feature gate
if (!$force && !$svc->autoUpdateEnabled()) {
    echo 'Auto-update disabled (module preference HELP_MAPPING_AUTO_UPDATE) - nothing to update; use --force to update anyway' . PHP_EOL;
    exit(0);
}

// ---------------------------------------------------------------- refresh
// de-thunder: Distributes requests across a jitter window so that, when
// cron times are identical, not all instances request the feed at the same time.
$jitter = random_int(0, max(0, $jitter_max));
echo 'Jitter: ' . $jitter . ' s before the refresh.' . PHP_EOL;
if ($jitter > 0) {
    sleep($jitter);
}

$start = microtime(true);

$updated = $svc->refresh();

if ($updated) {
    echo 'Help-mapping data updated. Applied hash: ' . $svc->currentAppliedHash() . PHP_EOL;
} else {
    echo 'Help-mapping already up to date. Applied hash: ' . $svc->currentAppliedHash() . PHP_EOL;
}

echo sprintf('Done in %.1fs.', microtime(true) - $start) . PHP_EOL;
