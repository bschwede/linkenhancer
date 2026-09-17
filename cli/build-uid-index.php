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

// Builds / updates the UID index (le_uid_index) for the "goto UID" lookup.
//
// Run (from the webtrees root, with the same PHP version as the instance):
//   php modules_v4/linkenhancer/cli/build-uid-index.php [--limit=N] [--tree=<id>] [--rebuild] [--flush]
//
// How it works:
//   - the UID index is SELF-CONTAINED (D1): it computes each record's MD5
//     fingerprint directly and keeps it in le_uid_index.hash. It neither
//     maintains its own all-records scan table nor reads le_record_scan, so
//     its freshness does not depend on the link index cron. (UidIndexService::
//     sourceAvailable() still reports whether the link index is present, for
//     context on the admin page.)
//   - the INITIAL build (no uid_last_run in le_index_meta, or --rebuild) resets
//     le_uid_index and indexes every record that passes the UID pre-filter
//     (cursor chunked)
//   - INCREMENTAL runs (the normal cron case) delete orphaned rows (their
//     record is gone) and re-index records that are new or whose MD5
//     fingerprint changed - cursor chunked, so repeated full-table scans are
//     avoided
//   - a FULL run (without --tree) that is NOT cut by --limit writes
//     le_index_meta.uid_last_run / uid_rows (row id = 1, D5) - that is what
//     makes the index "fresh"; a run cut by --limit continues where it stopped
//   - --flush (without --rebuild) empties le_uid_index and resets
//     le_index_meta.uid_last_run - for testing and maintenance; the next run
//     is an initial build
//
// Engine: requires MariaDB, MySQL or PostgreSQL (REGEXP + MD5).
//
// Conventions: see modules_v4/cronjob/cli/_template-maintenance.php and README.md
// ("CLI scripts & maintenance").

require __DIR__ . '/../../../vendor/autoload.php';
require __DIR__ . '/../autoload.php';

use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Webtrees;
use Schwendinger\Webtrees\Module\LinkEnhancer\Services\UidIndexService;
use Schwendinger\Webtrees\Module\LinkEnhancer\Services\XrefsService;
use Schwendinger\Webtrees\Services\CliBootstrap;

CliBootstrap::guard();
CliBootstrap::exitOnSiteOffline();

// ---------------------------------------------------------------- arguments
$limit   = 1000;
$tree_id = 0;
$rebuild = false;
$flush   = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--help' || $arg === '-h') {
        echo 'usage: php ' . basename(__FILE__) . ' [--limit=N] [--tree=<id>] [--rebuild] [--flush]' . PHP_EOL;
        exit(0);
    }
    if (str_starts_with($arg, '--limit=')) {
        $limit = max(1, (int) substr($arg, 8));
    }
    if (str_starts_with($arg, '--tree=')) {
        $tree_id = (int) substr($arg, 7);
    }
    if ($arg === '--rebuild') {
        $rebuild = true;
    }
    if ($arg === '--flush') {
        $flush = true;
    }
}

// ---------------------------------------------------------------- bootstrap
CliBootstrap::boot();

// Engine gate: the index needs a working REGEXP operator and MD5().
$driver = DB::driverName();
if (!in_array($driver, XrefsService::REGEXP_DRIVERS, true)) {
    echo 'Index requires a REGEXP/MD5-capable engine (MariaDB, MySQL or PostgreSQL); got: ' . $driver . PHP_EOL;
    exit(1);
}

// The module's boot() (and with it the schema migration) does not run in
// CLI context - make sure the index tables exist.
if (!DB::schema()->hasTable(UidIndexService::UID_INDEX_TABLE)
    || !DB::schema()->hasTable(XrefsService::INDEX_META_TABLE)
) {
    echo "Init module first - table is missing" . PHP_EOL;
    exit(1);
}

// ---------------------------------------------------------------- lock
$lock_file = Webtrees::DATA_DIR . 'linkenhancer-build-uid-index.lock';
$lock      = fopen($lock_file, 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo "already running (lock: {$lock_file})" . PHP_EOL;
    exit(0);
}

// ------------------------------------------------------------- flush
// --flush (without --rebuild): empty the UID index and stop. The meta row must
// be reset in every case - otherwise indexStatus() would report the now-empty
// index as fresh and the page would show an empty "index active" table.
if ($flush && !$rebuild) {
    if ($tree_id > 0) {
        DB::table(UidIndexService::UID_INDEX_TABLE)->where('file', '=', $tree_id)->delete();
    } else {
        DB::statement('TRUNCATE TABLE ' . UidIndexService::UID_INDEX_TABLE);
    }
    DB::table(XrefsService::INDEX_META_TABLE)
        ->where('id', '=', 1)
        ->update(['uid_last_run' => null]);
    echo 'uid index flushed' . ($tree_id > 0 ? " (tree {$tree_id})" : '') . PHP_EOL;
    fclose($lock);
    exit(0);
}

$chunk = 2000; // rows fetched per query; --limit is the total cap per run

$start     = microtime(true);
$scanned   = 0; // records (re)indexed
$removed   = 0; // orphan records deleted
$written   = 0; // UID rows written
$processed = 0; // $scanned + $removed, against --limit
$cut       = false; // run cut by --limit before all work was done

$patterns = UidIndexService::uidPrefilterPatterns();

$is_full    = $tree_id === 0; // only full runs may mark the index fresh
$meta       = DB::table(XrefsService::INDEX_META_TABLE)->where('id', '=', 1)->first(['uid_last_run']);
$is_initial = $rebuild
    || $meta === null
    || ($meta->uid_last_run === null);

if ($rebuild) {
    // Let the page fall back (index reported as not fresh) during the rebuild.
    DB::table(XrefsService::INDEX_META_TABLE)
        ->where('id', '=', 1)
        ->update(['uid_last_run' => null]);
}

// ------------------------------------------------------- initial build
if ($is_initial) {
    echo 'initial build' . ($rebuild ? ' (--rebuild)' : '') . PHP_EOL;

    // Reset (a tree-scoped rebuild only resets that tree's rows).
    if ($tree_id > 0) {
        DB::table(UidIndexService::UID_INDEX_TABLE)->where('file', '=', $tree_id)->delete();
    } else {
        DB::statement('TRUNCATE TABLE ' . UidIndexService::UID_INDEX_TABLE);
    }

    // Candidate pass: only records that pass the UID pre-filter are scanned in
    // PHP. No all-records scan table is written (self-contained, D1).
    foreach (XrefsService::GEDCOM_TABLES as $rectype => $params) {
        if ($processed >= $limit) {
            $cut = true;
            break;
        }
        $prefix    = $params['prefix'];
        $table     = $params['table'];
        $idcol     = $prefix . '_id';
        $filecol   = $prefix . '_file';
        $gedcomcol = $prefix . '_gedcom';

        $cursor = '';
        while ($processed < $limit) {
            $n = min($chunk, $limit - $processed);
            $query = DB::table($table)
                ->whereRaw($idcol . ' > ?', [$cursor])
                ->where(static function ($q) use ($patterns, $gedcomcol): void {
                    foreach ($patterns as $pattern) {
                        $q->orWhere($gedcomcol, DB::regexOperator(), $pattern);
                    }
                })
                ->orderBy($idcol)
                ->limit($n);
            if ($rectype === 'OTHER') {
                $query->whereIn('o_type', XrefsService::GEDCOM_OTHER_SUBTYPES);
            }
            if ($tree_id > 0) {
                $query->where($filecol, '=', $tree_id);
            }
            $batch = $query->get([
                $idcol . ' AS xref',
                $filecol . ' AS file',
                $gedcomcol . ' AS gedcom',
                $rectype === 'OTHER' ? 'o_type' : DB::raw("'" . $rectype . "' AS o_type"),
                DB::raw('MD5(' . $gedcomcol . ') AS hash'),
            ]);
            if ($batch->isEmpty()) {
                break;
            }
            foreach ($batch as $row) {
                $written += UidIndexService::writeUidRows($row, (string) $row->o_type, (string) $row->hash);
                $cursor    = (string) $row->xref;
                $scanned++;
                $processed++;
            }
            if (count($batch) < $n) {
                break; // table exhausted
            }
            if ($processed >= $limit) {
                $cut = true; // full chunk AND budget exhausted: more may follow
                break;
            }
        }
    }
} else {
    // --------------------------------------------- incremental update
    foreach (XrefsService::GEDCOM_TABLES as $rectype => $params) {
        $prefix    = $params['prefix'];
        $table     = $params['table'];
        $idcol     = $prefix . '_id';
        $filecol   = $prefix . '_file';
        $gedcomcol = $prefix . '_gedcom';

        // ------------------------------------------------- removed records
        $orphans = DB::table(UidIndexService::UID_INDEX_TABLE . ' AS s')
            ->leftJoin($table . ' AS t', static function ($join) use ($prefix, $rectype): void {
                $join->on('t.' . $prefix . '_file', '=', 's.file')
                    ->on('t.' . $prefix . '_id', '=', 's.xref');
                if ($rectype === 'OTHER') {
                    $join->on('t.o_type', '=', 's.rectype');
                } else {
                    $join->on('s.rectype', '=', DB::raw("'" . $rectype . "'"));
                }
            })
            ->whereNull('t.' . $idcol);
        if ($rectype === 'OTHER') {
            $orphans->whereIn('s.rectype', XrefsService::GEDCOM_OTHER_SUBTYPES);
        } else {
            $orphans->where('s.rectype', '=', $rectype);
        }
        if ($tree_id > 0) {
            $orphans->where('s.file', '=', $tree_id);
        }
        $orphans = $orphans->limit(max(0, $limit - $processed))->get(['s.file', 's.xref', 's.rectype']);
        foreach ($orphans as $o) {
            DB::table(UidIndexService::UID_INDEX_TABLE)
                ->where('file', '=', $o->file)
                ->where('xref', '=', $o->xref)
                ->where('rectype', '=', $o->rectype)
                ->delete();
            $removed++;
            $processed++;
        }
        if (count($orphans) > 0 && $processed >= $limit) {
            $cut = true; // there may be more orphans than the budget allowed
        }

        // ------------------------------------------ changed records
        // Re-derive records that already have UID rows but whose fingerprint
        // changed. This also catches a record that LOST its UID - it no longer
        // passes the pre-filter, so only a hash comparison can spot it. There
        // is deliberately no pre-filter here: writeUidRows() + UidTagCollector
        // are the source of truth, so a record that lost its UID is re-collected
        // to zero rows (stale rows are dropped).
        // DISTINCT: a record with several UID rows is (re)written only once.
        $cursor = '';
        while ($processed < $limit) {
            $n = min($chunk, $limit - $processed);
            $query = DB::table(UidIndexService::UID_INDEX_TABLE . ' AS s')
                ->join($table . ' AS t', static function ($join) use ($prefix, $rectype): void {
                    $join->on('t.' . $prefix . '_file', '=', 's.file')
                        ->on('t.' . $prefix . '_id', '=', 's.xref');
                    if ($rectype === 'OTHER') {
                        $join->on('t.o_type', '=', 's.rectype');
                    } else {
                        $join->on('s.rectype', '=', DB::raw("'" . $rectype . "'"));
                    }
                })
                ->whereRaw('t.' . $idcol . ' > ?', [$cursor])
                ->whereRaw('s.hash <> MD5(t.' . $gedcomcol . ')')
                ->distinct()
                ->select([
                    't.' . $idcol . ' AS xref',
                    't.' . $filecol . ' AS file',
                    't.' . $gedcomcol . ' AS gedcom',
                    $rectype === 'OTHER' ? 't.o_type' : DB::raw("'" . $rectype . "' AS o_type"),
                    DB::raw('MD5(t.' . $gedcomcol . ') AS hash'),
                ])
                ->orderBy('t.' . $idcol)
                ->limit($n);
            if ($rectype === 'OTHER') {
                $query->whereIn('t.o_type', XrefsService::GEDCOM_OTHER_SUBTYPES);
            }
            if ($tree_id > 0) {
                $query->where('s.file', '=', $tree_id);
            }
            $batch = $query->get();
            if ($batch->isEmpty()) {
                break;
            }
            foreach ($batch as $row) {
                $written += UidIndexService::writeUidRows($row, (string) $row->o_type, (string) $row->hash);
                $cursor    = (string) $row->xref;
                $scanned++;
                $processed++;
            }
            if (count($batch) < $n) {
                break; // table exhausted
            }
            if ($processed >= $limit) {
                $cut = true; // full chunk AND budget exhausted: more may follow
                break;
            }
        }

        // ------------------------------------------ new records
        // Records that pass the UID pre-filter but have no le_uid_index row at
        // all - i.e. they gained a UID since the last run (or were added).
        $cursor = '';
        while ($processed < $limit) {
            $n = min($chunk, $limit - $processed);
            $query = DB::table($table . ' AS t')
                ->whereRaw('t.' . $idcol . ' > ?', [$cursor])
                ->where(static function ($q) use ($patterns, $gedcomcol): void {
                    foreach ($patterns as $pattern) {
                        $q->orWhere('t.' . $gedcomcol, DB::regexOperator(), $pattern);
                    }
                })
                ->whereNotExists(static function ($q) use ($prefix, $rectype): void {
                    $q->from(UidIndexService::UID_INDEX_TABLE . ' AS s')
                        ->select(DB::raw('1'))
                        ->whereRaw('s.file = t.' . $prefix . '_file')
                        ->whereRaw('s.xref = t.' . $prefix . '_id');
                    if ($rectype === 'OTHER') {
                        $q->whereRaw('s.rectype = t.o_type');
                    } else {
                        $q->whereRaw('s.rectype = ?', [$rectype]);
                    }
                })
                ->select([
                    't.' . $idcol . ' AS xref',
                    't.' . $filecol . ' AS file',
                    't.' . $gedcomcol . ' AS gedcom',
                    $rectype === 'OTHER' ? 't.o_type' : DB::raw("'" . $rectype . "' AS o_type"),
                    DB::raw('MD5(t.' . $gedcomcol . ') AS hash'),
                ])
                ->orderBy('t.' . $idcol)
                ->limit($n);
            if ($rectype === 'OTHER') {
                $query->whereIn('t.o_type', XrefsService::GEDCOM_OTHER_SUBTYPES);
            }
            if ($tree_id > 0) {
                $query->where('t.' . $filecol, '=', $tree_id);
            }
            $batch = $query->get();
            if ($batch->isEmpty()) {
                break;
            }
            foreach ($batch as $row) {
                $written += UidIndexService::writeUidRows($row, (string) $row->o_type, (string) $row->hash);
                $cursor    = (string) $row->xref;
                $scanned++;
                $processed++;
            }
            if (count($batch) < $n) {
                break; // table exhausted
            }
            if ($processed >= $limit) {
                $cut = true; // full chunk AND budget exhausted: more may follow
                break;
            }
        }
    }
}

// ------------------------------------------------------------------- meta
if ($is_full && !$cut) {
    // Full, complete run - the index is now a verified whole.
    $rows = (int) DB::table(UidIndexService::UID_INDEX_TABLE)->count();
    DB::table(XrefsService::INDEX_META_TABLE)->updateOrInsert(
        ['id' => 1],
        ['uid_last_run' => date('Y-m-d H:i:s'), 'uid_rows' => $rows]
    );
}

echo sprintf(
    'build-uid-index: %s - %d reindexed, %d removed, %d uid row(s) written in %.1f s (limit %d%s%s)' . PHP_EOL,
    $is_initial ? 'initial build' : 'incremental',
    $scanned,
    $removed,
    $written,
    microtime(true) - $start,
    $limit,
    $tree_id > 0 ? ", tree {$tree_id}" : '',
    $is_full && !$cut ? ', index marked fresh' : ($cut ? ', limit reached - continues next run' : '')
);

fclose($lock);
