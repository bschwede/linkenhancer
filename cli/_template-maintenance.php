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

// TEMPLATE - not a production script. Copy this file into this directory,
// rename it (without the leading underscore), and adapt the marked sections.
//
// Run (from the webtrees root, with the same PHP version as the instance):
//   php modules_v4/linkenhancer/cli/<name>.php [--limit=500] [--help]
//
// Conventions for maintenance scripts (see README.md, "CLI scripts & maintenance"):
//   1. SAPI guard: MANDATORY - this directory is inside the web root, the
//      guard answers HTTP 403 instead of running the script.
//   2. Arguments: plain $argv (simple "--name=value" loop + --help),
//      no console framework.
//   3. Lock: flock() on data/linkenhancer-<name>.lock so overlapping cron
//      runs do not collide; a held lock is NOT an error (exit 0).
//   4. Idempotent batches: design the job so one run finishes in roughly
//      5-10 minutes and the next run continues where the last one stopped
//      (e.g. "only not-yet-processed records" + --limit).
//   5. Logging: plain stdout lines (cron mail / log file). NEVER print
//      personal data - counters and XREFs, never names.
//   6. Exit codes: 0 = ok (incl. "nothing to do", "lock held"), 1 = error.

require __DIR__ . '/../../../vendor/autoload.php';
require __DIR__ . '/../autoload.php';

use Fisharebest\Webtrees\Webtrees;
use Schwendinger\Webtrees\Services\CliBootstrap;

CliBootstrap::guard();
CliBootstrap::exitOnSiteOffline();

// ---------------------------------------------------------------- arguments
$limit = 500;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--help' || $arg === '-h') {
        echo 'usage: php ' . basename(__FILE__) . " [--limit=N]\n";
        exit(0);
    }
    if (str_starts_with($arg, '--limit=')) {
        $limit = max(1, (int) substr($arg, 8));
    }
}

// ---------------------------------------------------------------- bootstrap
CliBootstrap::boot();

// This file is a template and does nothing. A real script continues here
// with the sections below (uncomment, adapt, and remove this line).
exit('template');

// ---------------------------------------------------------------- lock
// $lock_file = Webtrees::DATA_DIR . 'linkenhancer-<name>.lock';
// $lock      = fopen($lock_file, 'c');
// if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
//     echo "already running (lock: {$lock_file})\n";
//     exit(0);
// }
//
// ---------------------------------------------------------------- batch
// Process only records that still need work, up to $limit; mark each as
// done while processing, so the next cron run picks up the remainder.
//
// $processed = 0;
// foreach (DB::table('...')->where('...')->limit($limit)->get() as $row) {
//     // ... do the work ...
//     $processed++;
// }
// echo "processed {$processed} record(s)\n";
//
// Logging via \Fisharebest\Webtrees\Log::addLog() is an optional
// alternative to stdout when the result should survive for inspection.
//
// ------------------------------------------------------------- clean up
// fclose($lock); // releases the flock automatically
