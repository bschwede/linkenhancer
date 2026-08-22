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

// P1 scaling measurement for the XREF overview - STRICTLY READ-ONLY.
//
// Created as part of the P1 execution; run it MANUALLY on the live
// instance (it is NOT a gate for the implementation, it only feeds
// the later calibration of the DataTables defaults - see
// .opencode/plans/linkenhancer-xref-overview-p1-scaling.md, Q5):
//
//   php modules_v4/linkenhancer/tests/p1-measure.php [--tree=<id>]
//
// Output:
//   1. PHP runtime limits (max_execution_time, memory_limit)
//   2. row count of every source table
//   3. cost of the live XREF-overview query:
//        - DB-only:  COUNT(*) over the UNION (the datatables pagination cost)
//        - DB+PHP:   full fetch (the behaviour of the pre-P1 page)
//   4. match distribution per record type
//   5. link index status (Phase 2, if the tables exist yet)

require __DIR__ . '/../../../vendor/autoload.php';
require __DIR__ . '/../autoload.php';

use DomainException;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\TreeService;
use Schwendinger\Webtrees\Module\LinkEnhancer\Services\CliBootstrap;
use Schwendinger\Webtrees\Module\LinkEnhancer\Services\XrefsService;

use function date;
use function ini_get;
use function microtime;
use function sprintf;
use function str_starts_with;
use function substr;

CliBootstrap::guard();

// ---------------------------------------------------------------- arguments
$tree_id = 0;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--help' || $arg === '-h') {
        echo 'usage: php modules_v4/linkenhancer/tests/p1-measure.php [--tree=<id>]' . PHP_EOL;
        exit(0);
    }
    if (str_starts_with($arg, '--tree=')) {
        $tree_id = (int) substr($arg, 7);
    }
}

// ---------------------------------------------------------------- bootstrap
CliBootstrap::boot();

// The live query works on every engine (REGEXP gate on MariaDB/MySQL/
// PostgreSQL, coarse LIKE gate on SQLite/SQL Server); the link index part
// below needs REGEXP + MD5 and simply reports "not built" otherwise.
$engine_note = XrefsService::supportsRegexp()
    ? 'REGEXP-capable'
    : 'limited mode (no REGEXP support - LIKE gate, no link index)';
echo 'database engine: ' . DB::driverName() . ' (' . $engine_note . ')' . PHP_EOL . PHP_EOL;

$measure = static function (string $label, callable $fn): float {
    $start = microtime(true);
    $fn();
    $seconds = microtime(true) - $start;
    printf("%-62s %10.3f s" . PHP_EOL, $label, $seconds);
    return $seconds;
};

echo 'P1 measurement - ' . date('Y-m-d H:i:s') . ($tree_id > 0 ? " (tree {$tree_id})" : ' (all trees)') . PHP_EOL . PHP_EOL;

// ---------------------------------------------------------------- PHP limits
echo 'max_execution_time: ' . (ini_get('max_execution_time') ?: 'unset') . ' s' . PHP_EOL;
echo 'memory_limit:       ' . (ini_get('memory_limit') ?: 'unset') . PHP_EOL;
echo PHP_EOL;

// ------------------------------------------------------ source table sizes
foreach (XrefsService::GEDCOM_TABLES as $rectype => $params) {
    $table = $params['table'];
    $count = $tree_id > 0
        ? DB::table($table)->where($params['prefix'] . '_file', '=', $tree_id)->count()
        : DB::table($table)->count();
    printf("rows %-8s %-12s: %d" . PHP_EOL, $rectype, $table, $count);
}
echo PHP_EOL;

// --------------------------------------------------- live query cost probe
$tree = null;
if ($tree_id > 0) {
    try {
        $tree = Registry::container()->get(TreeService::class)->find($tree_id);
    } catch (DomainException) {
        echo "tree {$tree_id} not found" . PHP_EOL;
        exit(1);
    }
}

$query = XrefsService::getRecordsQuery($tree);
$sql   = $query->toSql();
$binds = $query->getBindings();

$measure('live query, DB-only: COUNT(*) over the UNION', static function () use ($sql, $binds): void {
    DB::select('SELECT COUNT(*) AS c FROM (' . $sql . ') AS u', $binds);
});
$measure('live query, DB+PHP: full fetch (pre-P1 page behaviour)', static function () use ($query): void {
    $query->get();
});

echo PHP_EOL;
echo 'match distribution per type:' . PHP_EOL;
foreach (DB::select('SELECT type, COUNT(*) AS c FROM (' . $sql . ') AS u GROUP BY type ORDER BY c DESC', $binds) as $row) {
    printf('  %-12s: %d' . PHP_EOL, $row->type, (int) $row->c);
}

// --------------------------------------------------- link index status (P2)
echo PHP_EOL;
if (DB::schema()->hasTable(XrefsService::INDEX_SCAN_TABLE)) {
    $status = XrefsService::indexStatus();
    printf("le_record_scan:  %d row(s), last verified %s (fresh: %s)" . PHP_EOL,
        $status['rows'],
        $status['scanned_at'] ?? 'never',
        $status['fresh'] ? 'yes' : 'no'
    );
    printf("le_link_index:   %d row(s)" . PHP_EOL,
        DB::table(XrefsService::INDEX_LINK_TABLE)->count()
    );
} else {
    echo 'link index:      not built yet (tables missing)' . PHP_EOL;
}

echo PHP_EOL;
echo 'done (read-only - no data was modified)' . PHP_EOL;
