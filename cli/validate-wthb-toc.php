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

// Validate locally cached WTHB TOC data: schema check + coverage report.
//
// Run (from the webtrees root):
//   php modules_v4/linkenhancer/cli/validate-wthb-toc.php

require __DIR__ . '/../../../vendor/autoload.php';
require __DIR__ . '/../autoload.php';

use Schwendinger\Webtrees\Module\LinkEnhancer\Services\WthbTocService;
use Schwendinger\Webtrees\Services\CliBootstrap;

CliBootstrap::guard();

if (($argv[1] ?? '') === '--help' || ($argv[1] ?? '') === '-h') {
    echo 'usage: php ' . basename(__FILE__) . PHP_EOL;
    exit(0);
}

CliBootstrap::boot();

$svc    = new WthbTocService();
$report = $svc->validate();

// Structure
$struct = $report['structure'];
echo 'Structure: ' . $struct['items'] . ' items, version ' . $struct['version'] . ($struct['valid'] ? ' ✓' : ' ✗') . PHP_EOL;

// Languages
if (empty($report['languages'])) {
    echo 'Languages: (none cached)' . PHP_EOL;
} else {
    foreach ($report['languages'] as $lang => $data) {
        echo sprintf(
            '%s.json: %d/%d titles (%d%%)' . PHP_EOL,
            $lang,
            $data['translated'],
            $data['total'],
            $data['pct']
        );
    }
}

// Version
$ver = $report['version'];
echo 'version: hash ' . $ver['hash'] . ', updated ' . $ver['updated'] . PHP_EOL;
