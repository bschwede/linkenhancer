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

// Smoke test for TextTagCollector against real database data.
//
// Run on a LIVE webtrees instance from the webtrees root:
//   php modules_v4/linkenhancer/tests/smoke-text-tag-collector.php [limit=10]
//
// Finds individual records whose raw GEDCOM contains the enhanced-link
// marker "#@wt=" and prints every captured TEXT/NOTE/_TODO value with its
// location (webtrees tag hierarchy) and the enhanced links found in it.

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../autoload.php';

use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\TreeService;
use Schwendinger\Webtrees\Services\CliBootstrap;
use Schwendinger\Webtrees\Module\LinkEnhancer\Services\TextTagCollector;

CliBootstrap::guard();
CliBootstrap::exitOnSiteOffline();
CliBootstrap::boot();

$limit = (int) ($argv[1] ?? 10);
$found = 0;

$tree_service = Registry::container()->get(TreeService::class);

foreach ($tree_service->all() as $tree) {
    $rows = DB::table('individual')
        ->select('i_id', 'i_gedcom')
        ->where('i_file', '=', $tree->id())
        ->where('i_gedcom', 'RLIKE', '#@wt=')
        ->limit($limit)
        ->get();

    foreach ($rows as $row) {
        $found++;
        $record = Registry::individualFactory()->make($row->i_id, $tree, $row->i_gedcom);
        echo "== @{$row->i_id}@ in tree " . $tree->name() . "\n";
        foreach (TextTagCollector::collectForRecord($record) as $entry) {
            $links = preg_match_all('/#@wt=([ifsnrl])?@([A-Za-z0-9:_.-]+)@([^@\s&]*)/', $entry['value'], $m);
            printf("  %-28s enhanced links: %d\n", $entry['path'], $links);
            echo '    ' . trim((string) preg_replace('/\s+/', ' ', $entry['value'])) . "\n";
        }
        echo "\n";
    }
}

echo $found === 0
    ? 'No individuals with enhanced links found.' . "\n"
    : "Done: {$found} record(s) scanned." . "\n";
