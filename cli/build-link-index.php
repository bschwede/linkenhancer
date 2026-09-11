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

// Builds / updates the link index (P1 Phase 2) for the XREF overview.
//
// Run (from the webtrees root, with the same PHP version as the instance):
//   php modules_v4/linkenhancer/cli/build-link-index.php [--limit=N] [--tree=<id>] [--rebuild] [--flush]
//
// How it works:
//   - the INITIAL build (first run, no last_run in le_index_meta, or
//     --rebuild) resets both index tables, creates all scan rows
//     server-side in one bulk statement per GEDCOM table (MD5 fingerprint
//     base, zero row transfer) and scans only the records that pass the
//     link pre-filter (the same patterns as the live overview) in PHP
//   - INCREMENTAL runs (the normal cron case) only touch records whose
//     MD5 fingerprint changed or which were added/removed - cursor
//     chunked, so repeated full-table scans are avoided
//   - a FULL run (without --tree) that is NOT cut by --limit writes
//     le_index_meta.last_run - that is what makes the index "fresh" on
//     the admin page; a run cut by --limit continues where it stopped
//   - a run cut mid initial build converges on the next run (the initial
//     build is idempotent) - use a higher --limit for large initial builds
//   - --flush (without --rebuild) empties the index tables and exits -
//     for testing (force the page onto the live scan) and maintenance
//     (clean restart, broken index); the next run is an initial build
//
// Engine: requires MariaDB, MySQL or PostgreSQL (REGEXP + MD5). The live
// overview itself also works on SQLite/SQL Server in a limited LIKE mode,
// but the index is not available there.
//
// Conventions: see cli/_template-maintenance.php and README.md
// ("CLI scripts & maintenance").

require __DIR__ . '/../../../vendor/autoload.php';
require __DIR__ . '/../autoload.php';

use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Gedcom;
use Fisharebest\Webtrees\Webtrees;
use Schwendinger\Webtrees\Services\CliBootstrap;
use Schwendinger\Webtrees\Module\LinkEnhancer\Services\TextTagCollector;
use Schwendinger\Webtrees\Module\LinkEnhancer\Services\XrefsService;

CliBootstrap::guard();

if (CliBootstrap::siteIsOffline()) {
    fwrite(STDOUT, 'site offline (data/offline.txt) - skipped' . PHP_EOL);
    exit(0);
}

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
if (!DB::schema()->hasTable(XrefsService::INDEX_SCAN_TABLE)
    || !DB::schema()->hasTable(XrefsService::INDEX_LINK_TABLE)
    || !DB::schema()->hasTable(XrefsService::INDEX_META_TABLE)
) {
    echo "Init module first - table is missing" . PHP_EOL;
    exit(1);
}

// ---------------------------------------------------------------- lock
$lock_file = Webtrees::DATA_DIR . 'linkenhancer-build-link-index.lock';
$lock      = fopen($lock_file, 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo "already running (lock: {$lock_file})" . PHP_EOL;
    exit(0);
}

// ------------------------------------------------------------- flush
// --flush (without --rebuild): empty the index tables and stop. The meta
// row must be reset in every case - otherwise indexStatus() would report
// the now-empty index as fresh and the page would show an empty
// "index active" table instead of falling back to the live scan.
if ($flush && !$rebuild) {
    if ($tree_id > 0) {
        DB::table(XrefsService::INDEX_LINK_TABLE)->where('file', '=', $tree_id)->delete();
        DB::table(XrefsService::INDEX_SCAN_TABLE)->where('file', '=', $tree_id)->delete();
    } else {
        DB::statement('TRUNCATE TABLE ' . XrefsService::INDEX_LINK_TABLE);
        DB::statement('TRUNCATE TABLE ' . XrefsService::INDEX_SCAN_TABLE);
        DB::statement('TRUNCATE TABLE ' . XrefsService::INDEX_META_TABLE);
    }
    DB::table(XrefsService::INDEX_META_TABLE)
        ->where('id', '=', 1)
        ->update(['last_run' => null]);
    echo 'link index flushed' . ($tree_id > 0 ? " (tree {$tree_id})" : '') . PHP_EOL;
    fclose($lock);
    exit(0);
}

$chunk = 2000; // rows fetched per query; --limit is the total cap per run

$start     = microtime(true);
$scanned   = 0; // records scanned in PHP
$removed   = 0; // records deleted
$links     = 0; // link rows written
$processed = 0; // $scanned + $removed, against --limit
$cut       = false; // run cut by --limit before all work was done

$is_full    = $tree_id === 0; // only full runs may mark the index fresh
$meta       = DB::table(XrefsService::INDEX_META_TABLE)->first(['last_run']);
$is_initial = $rebuild
    || $meta === null
    || ($meta->last_run === null);

if ($rebuild) {
    // Let the page fall back to the live scan during the rebuild.
    DB::table(XrefsService::INDEX_META_TABLE)
        ->where('id', '=', 1)
        ->update(['last_run' => null]);
}

/**
 * Link rows for one record (without touching the scan row).
 */
$write_links = static function (object $row, string $rectype_val) use (&$links): void {
    $file = (int) $row->file;
    $xref = (string) $row->xref;

    DB::table(XrefsService::INDEX_LINK_TABLE)
        ->where('file', '=', $file)
        ->where('xref', '=', $xref)
        ->where('rectype', '=', $rectype_val)
        ->delete();

    $inventory = XrefsService::classifyGedcomText((string) $row->gedcom, TextTagCollector::DEFAULT_TAGS, $rectype_val);

    $link_rows = [];
    foreach ($inventory['entries'] as $entry) {
        $targets = XrefsService::extractLinkTargets($entry['token']);
        if ($targets === []) {
            $targets = [['xref' => null, 'tree' => null]];
        }
        foreach ($targets as $target) {
            $link_rows[] = [
                'file'          => $file,
                'xref'          => $xref,
                'rectype'       => $rectype_val,
                'tag_path'      => mb_substr($entry['path'], 0, 255),
                'link_class'    => $entry['class'],
                'token'         => $entry['token'],
                'snippet'       => $entry['snippet'],
                'target_xref'   => $target['xref'],
                'target_tree'   => $target['tree'],
            ];
        }
    }
    if ($link_rows !== []) {
        DB::table(XrefsService::INDEX_LINK_TABLE)->insert($link_rows);
    }
    $links += count($link_rows);
};

// ------------------------------------------------------- initial build
if ($is_initial) {
    echo 'initial build' . ($rebuild ? ' (--rebuild)' : '') . PHP_EOL;

    // Reset (a tree-scoped rebuild only resets that tree's rows).
    if ($tree_id > 0) {
        DB::table(XrefsService::INDEX_LINK_TABLE)->where('file', '=', $tree_id)->delete();
        DB::table(XrefsService::INDEX_SCAN_TABLE)->where('file', '=', $tree_id)->delete();
    } else {
        DB::statement('TRUNCATE TABLE ' . XrefsService::INDEX_LINK_TABLE);
        DB::statement('TRUNCATE TABLE ' . XrefsService::INDEX_SCAN_TABLE);
    }

    // Bulk scan rows: pure SQL, server-side, zero row transfer.
    foreach (XrefsService::GEDCOM_TABLES as $rectype => $params) {
        $prefix = $params['prefix'];
        $sql    = 'INSERT INTO ' . XrefsService::INDEX_SCAN_TABLE . ' (file, xref, rectype, hash, scanned_at) '
            . 'SELECT ' . $prefix . '_file, ' . $prefix . '_id, '
            . ($rectype === 'OTHER' ? 'o_type' : "'" . $rectype . "'") . ', '
            . 'MD5(' . $prefix . '_gedcom), NOW() FROM ' . $params['table'];
        if ($rectype === 'OTHER') {
            $sql .= ' WHERE o_type IN (' . implode(', ', array_map(static fn (string $t): string => "'" . $t . "'", XrefsService::GEDCOM_OTHER_SUBTYPES)) . ')';
        }
        if ($tree_id > 0) {
            $sql .= ($rectype === 'OTHER' ? ' AND ' : ' WHERE ') . $prefix . '_file = ' . $tree_id;
        }
        DB::statement($sql);
    }

    // Candidate pass: only records that pass the link pre-filter (same
    // patterns as the live overview) are scanned in PHP.
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
        $patterns  = XrefsService::linkPrefilterPatterns(Gedcom::REGEX_XREF, $rectype === 'OTHER');

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
            ]);
            if ($batch->isEmpty()) {
                break;
            }
            foreach ($batch as $row) {
                $write_links($row, (string) $row->o_type);
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
        $orphans = DB::table(XrefsService::INDEX_SCAN_TABLE . ' AS s')
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
            DB::table(XrefsService::INDEX_LINK_TABLE)
                ->where('file', '=', $o->file)
                ->where('xref', '=', $o->xref)
                ->where('rectype', '=', $o->rectype)
                ->delete();
            DB::table(XrefsService::INDEX_SCAN_TABLE)
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

        // ------------------------------------------ new / changed records
        $cursor = '';
        while ($processed < $limit) {
            $n = min($chunk, $limit - $processed);
            $query = DB::table($table . ' AS t')
                ->leftJoin(XrefsService::INDEX_SCAN_TABLE . ' AS s', static function ($join) use ($prefix, $rectype): void {
                    $join->on('s.file', '=', 't.' . $prefix . '_file')
                        ->on('s.xref', '=', 't.' . $prefix . '_id');
                    if ($rectype === 'OTHER') {
                        $join->on('s.rectype', '=', 't.o_type');
                    } else {
                        $join->on('s.rectype', '=', DB::raw("'" . $rectype . "'"));
                    }
                })
                ->whereRaw('t.' . $idcol . ' > ?', [$cursor])
                ->whereRaw('s.file IS NULL OR s.hash <> MD5(t.' . $gedcomcol . ')')
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
                $file = (int) $row->file;
                $xref = (string) $row->xref;

                // Clear stale link rows first (a changed record may have
                // lost its links); only records that still pass the link
                // pre-filter (same semantics as the live overview) get a
                // new classification.
                DB::table(XrefsService::INDEX_LINK_TABLE)
                    ->where('file', '=', $file)
                    ->where('xref', '=', $xref)
                    ->where('rectype', '=', (string) $row->o_type)
                    ->delete();
                if (XrefsService::hasLinkCandidate((string) $row->gedcom, (string) $row->o_type)) {
                    $write_links($row, (string) $row->o_type);
                }

                // Mark the record as scanned (fingerprint written last, so
                // a crash in between only leads to a re-scan next run).
                DB::table(XrefsService::INDEX_SCAN_TABLE)->updateOrInsert(
                    ['file' => $file, 'xref' => $xref, 'rectype' => (string) $row->o_type],
                    ['hash' => (string) $row->hash, 'scanned_at' => date('Y-m-d H:i:s')]
                );
                $scanned++;
                $processed++;
                $cursor = $xref;
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
    $rows = (int) DB::table(XrefsService::INDEX_SCAN_TABLE)->count();
    DB::table(XrefsService::INDEX_META_TABLE)->updateOrInsert(
        ['id' => 1],
        ['last_run' => date('Y-m-d H:i:s'), 'rows' => $rows]
    );
}

echo sprintf(
    'build-link-index: %s - %d scanned, %d removed, %d link row(s) written in %.1f s (limit %d%s%s)' . PHP_EOL,
    $is_initial ? 'initial build' : 'incremental',
    $scanned,
    $removed,
    $links,
    microtime(true) - $start,
    $limit,
    $tree_id > 0 ? ", tree {$tree_id}" : '',
    $is_full && !$cut ? ', index marked fresh' : ($cut ? ', limit reached - continues next run' : '')
);

fclose($lock);
