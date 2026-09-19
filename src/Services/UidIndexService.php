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

namespace Schwendinger\Webtrees\Module\LinkEnhancer\Services;

use Fisharebest\Webtrees\DB;
use Illuminate\Support\Collection;
use Throwable;
use Schwendinger\Webtrees\Module\LinkEnhancer\LinkEnhancerModule;

use function boolval;
use function in_array;
use function mb_substr;
use function strtotime;
use function time;

/**
 * UID index (le_uid_index) - the write / query / status service.
 *
 * Analogous to the link index (XrefsService): a persistent index of the UID
 * tags (_UID in GEDCOM 5.5.1 / UID in GEDCOM 7.0) found in every record,
 * maintained exclusively by cli/build-uid-index.php (no live scan on request,
 * D4). The fingerprint/candidate source is reused from the link index (D1);
 * the freshness metadata lives in the shared le_index_meta single row under
 * its own columns (D5). The uid value is stored verbatim (no normalization)
 * and compared case-insensitively (R5).
 */
final class UidIndexService
{
    public const UID_INDEX_TABLE = 'le_uid_index';

    /**
     * D1: the fingerprint / candidate source is the link index' record scan.
     * Kept as a named alias so the dependency is explicit and greppable.
     */
    public const UID_SCAN_SOURCE = XrefsService::INDEX_SCAN_TABLE;

    /**
     * The DB regex pre-filter for records that may contain a UID tag - single
     * source for the index build and the PHP pre-check (hasUidCandidate()).
     *
     * A UID is a subtag, so it is anchored to a level (1-9) followed by a
     * space and the tag name (optionally underscore-prefixed). Anchoring to
     * "level + space" keeps a "_UID" that merely occurs inside a note's text
     * from matching. This is an over-approximation - UidTagCollector is the
     * source of truth. No \b (word boundary) is used, since the MariaDB
     * default regex does not support it (same convention as
     * XrefsService::linkPrefilterPatterns).
     *
     * @return array<int,string>
     */
    public static function uidPrefilterPatterns(): array
    {
        return ['[1-9] _?UID '];
    }

    /**
     * Cheap PHP pre-check (before running the collector): does this record's
     * raw text plausibly contain a UID tag?
     */
    public static function hasUidCandidate(string $gedcom, string $rectype): bool
    {
        foreach (self::uidPrefilterPatterns() as $pattern) {
            if (@preg_match('/' . $pattern . '/', $gedcom) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * (Re)write the UID rows of one record: delete-then-insert keyed on
     * (file, xref, rectype), so a record that lost all UIDs is cleaned up.
     * One row per UID tag occurrence (D3); the record text's MD5 fingerprint
     * is stored per row for change detection (D1).
     *
     * @param object $row    a row with ->file (int), ->xref (string), ->gedcom (string)
     *
     * @return int the number of rows written
     */
    public static function writeUidRows(object $row, string $rectype, string $hash): int
    {
        $file = (int) $row->file;
        $xref = (string) $row->xref;

        DB::table(self::UID_INDEX_TABLE)
            ->where('file', '=', $file)
            ->where('xref', '=', $xref)
            ->where('rectype', '=', $rectype)
            ->delete();

        $rows = [];
        foreach (UidTagCollector::collect((string) $row->gedcom, UidTagCollector::DEFAULT_TAGS, $rectype) as $uid) {
            if ($uid['value'] === '') {
                continue; // no value - nothing to index
            }
            $rows[] = [
                'file'     => $file,
                'xref'     => $xref,
                'uid'      => $uid['value'],
                'rectype'  => $rectype,
                'tag_path' => mb_substr($uid['path'], 0, 255),
                'hash'     => $hash,
            ];
        }

        if ($rows !== []) {
            DB::table(self::UID_INDEX_TABLE)->insert($rows);
        }

        return count($rows);
    }

    /**
     * Global lookup of a UID. The stored value is verbatim (no normalization,
     * R5), but the match is case-insensitive - UID letter case is not
     * semantically significant (GEDCOM 5.5.1/7 do not define it as
     * case-sensitive). Case-insensitive matching is also safer: a case-variant
     * UID returns a selection list (D2) rather than a possibly-wrong single
     * redirect.
     *
     * @param string $uid  the UID value (compared case-insensitively)
     * @param int    $tree limit to one tree (file) when given
     *
     * @return Collection<int,object> rows with file, xref, rectype, tag_path, uid
     *         (empty if the index is missing/unreadable - D4, no live scan)
     */
    public static function lookup(string $uid, ?int $tree = null): Collection
    {
        try {
            $query = DB::table(self::UID_INDEX_TABLE);

            // R5: case-insensitive match. The MariaDB/MySQL default collation is
            // already case-insensitive (index-friendly); PostgreSQL/SQLite
            // compare text case-sensitively, so fold both sides to lower-case.
            if (in_array(DB::driverName(), [DB::MARIADB, DB::MYSQL], true)) {
                $query->where('uid', '=', $uid);
            } else {
                $query->whereRaw('lower(uid) = lower(?)', [$uid]);
            }

            if ($tree !== null) {
                $query->where('file', '=', $tree);
            }

            return $query
                ->orderBy('file')
                ->orderBy('xref')
                ->orderBy('tag_path')
                ->get(['file', 'xref', 'rectype', 'tag_path', 'uid']);
        } catch (Throwable) {
            return new Collection();
        }
    }

    /**
     * Freshness / status of the UID index, from the shared le_index_meta row
     * (id = 1) under its own columns (D5).
     *
     * @return array{rows: int, last_run: string|null, fresh: bool, source_available: bool}
     */
    public static function indexStatus(int $fresh_seconds = XrefsService::INDEX_FRESH_SECONDS): array
    {
        try {
            $source_available = self::sourceAvailable();

            if (!DB::schema()->hasTable(self::UID_INDEX_TABLE)
                || !DB::schema()->hasTable(XrefsService::INDEX_META_TABLE)
            ) {
                return ['rows' => 0, 'last_run' => null, 'fresh' => false, 'source_available' => $source_available];
            }

            $meta = DB::table(XrefsService::INDEX_META_TABLE)
                ->where('id', '=', 1)
                ->first(['uid_last_run', 'uid_rows']);

            $rows     = (int) ($meta->uid_rows ?? 0);
            $last_run = $meta->uid_last_run !== null ? (string) $meta->uid_last_run : null;
            $fresh    = $last_run !== null
                && strtotime($last_run) > time() - $fresh_seconds;

            return ['rows' => $rows, 'last_run' => $last_run, 'fresh' => $fresh, 'source_available' => $source_available];
        } catch (Throwable) {
            return ['rows' => 0, 'last_run' => null, 'fresh' => false, 'source_available' => false];
        }
    }

    /**
     * D1: can the build derive candidates/fingerprints from the link index'
     * record scan, or must it fall back to its own full scan?
     */
    public static function sourceAvailable(): bool
    {
        try {
            return DB::schema()->hasTable(self::UID_SCAN_SOURCE)
                && (int) DB::table(self::UID_SCAN_SOURCE)->count() > 0;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Is the UID feature active (module preference PREF_UID_ACTIVE)? Read from
     * module_setting directly - the module is not booted in CLI context, so
     * AbstractModule::getPref() is unavailable. A missing row means the schema
     * default (on), matching getPref(PREF_UID_ACTIVE, true).
     */
    public static function uidFeatureEnabled(): bool
    {
        $value = DB::table('module_setting')
            ->where('module_name', '=', LinkEnhancerModule::MODULE_NAME)
            ->where('setting_name', '=', LinkEnhancerModule::PREF_UID_ACTIVE)
            ->value('setting_value');

        return self::interpretUidPref($value);
    }

    /**
     * Pure interpretation of a stored UID_ACTIVE value: a missing row is the
     * schema default (enabled), present values are bool-cast (setPref stores
     * '1'/'0'). Split out so the default-on semantics are unit-testable.
     */
    public static function interpretUidPref(?string $value): bool
    {
        return $value === null ? true : boolval($value);
    }
}
