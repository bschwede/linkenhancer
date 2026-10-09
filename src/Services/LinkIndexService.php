<?php

declare(strict_types=1);

namespace Schwendinger\Webtrees\Module\LinkEnhancer\Services;

use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Gedcom;
use Fisharebest\Webtrees\Tree;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;
use Schwendinger\Webtrees\Module\LinkEnhancer\LinkEnhancerModule;
use Throwable;

use function array_filter;
use function array_intersect;
use function array_keys;
use function array_map;
use function array_merge;
use function array_unique;
use function array_values;
use function count;
use function in_array;
use function strtotime;
use function time;

/**
 * DB query and index service for the link index (le_link_index / le_record_scan).
 * Extracted from XrefsService — pure data access, no pattern matching.
 */
final class LinkIndexService
{
    public static function supportsRegexp(): bool
    {
        try {
            return in_array(DB::driverName(), XrefsService::REGEXP_DRIVERS, true);
        } catch (Throwable) {
            return false;
        }
    }

    public static function supportedGedcomTableKeys(): array
    {
        return array_keys(XrefsService::GEDCOM_TABLES);
    }

    public static function supportedGedcomRecordKeys(): array
    {
        return array_merge(self::supportedGedcomTableKeys(), XrefsService::GEDCOM_OTHER_SUBTYPES);
    }

    /**
     * @param array<string> $rectypes
     */
    public static function getRecordsQuery(Tree|null $tree = null, string|null $xref = null, array $rectypes = [], bool $ordered = true): Builder
    {
        $gedcom_table_keys = self::supportedGedcomTableKeys();
        $gedcom_record_keys = self::supportedGedcomRecordKeys();
        $rectypes = array_map('strtoupper', $rectypes);
        $rectypes = array_values(array_filter($rectypes, fn($s) => in_array($s, $gedcom_record_keys)));
        $rectypes = count($rectypes) === 0 ?
            $gedcom_table_keys :
            $rectypes;
        $other_subtypes_filter = in_array('OTHER', $rectypes) ?
            XrefsService::GEDCOM_OTHER_SUBTYPES :
            array_values(array_filter($rectypes, fn($s) => in_array($s, XrefsService::GEDCOM_OTHER_SUBTYPES)));
        if ($other_subtypes_filter !== [] && !in_array('OTHER', $rectypes)) {
            $rectypes[] = 'OTHER';
        }
        $rectypes_filter = array_values(array_filter($rectypes, fn($s) => in_array($s, $gedcom_table_keys)));

        $xref ??= Gedcom::REGEX_XREF;
        $file = $tree instanceof Tree ? $tree->id() : null;

        $unionQuery = null;
        foreach ($rectypes_filter as $rectype) {
            $params = XrefsService::GEDCOM_TABLES[$rectype];
            $subquery = self::getGedcomRecTypeSubquery($params, $xref, $file);

            if ($rectype === 'OTHER') {
                $subquery->whereIn('o_type', $other_subtypes_filter);
            }

            $unionQuery = $unionQuery ? $unionQuery->unionAll($subquery) : $subquery;
        }

        if ($unionQuery === null) {
            throw new InvalidArgumentException('No valid GEDCOM record types given.');
        }

        $query = DB::query()
            ->fromSub($unionQuery, 'u')
            ->select('u.*');

        if ($ordered) {
            $query->orderBy('u.file')->orderBy('u.xref');
        }

        return $query;
    }

    /**
     * @param array<int,string> $rectypes
     */
    public static function getBlockQuery(Tree|null $tree, array $rectypes, bool $index_mode = false, int|null $user_id = null, string $filter_xref = ''): ?Builder
    {
        $module_names = self::blockModuleNames();
        $table_prefix = DB::getTablePrefix();

        if ($rectypes !== []) {
            $module_names = array_values(array_intersect($module_names, $rectypes));
            if ($module_names === []) {
                return null;
            }
        }

        $union = null;
        foreach ($module_names as $module_name) {
            $text_settings = self::blockTextSettings($module_name);
            if ($text_settings === []) {
                continue;
            }

            if ($index_mode) {
                $select = [
                    DB::raw("{$table_prefix}b.gedcom_id AS file"),
                    DB::raw("CONCAT('BLOCK-', {$table_prefix}b.block_id) AS xref"),
                    DB::raw("'" . $module_name . "' AS type"),
                    DB::raw($table_prefix . 'b.block_id AS block_id'),
                    DB::raw($table_prefix . 'b.user_id'),
                ];
            } else {
                $select = [
                    DB::raw("CONCAT('BLOCK-', {$table_prefix}b.block_id) AS xref"),
                    DB::raw($table_prefix . 'b.gedcom_id AS file'),
                    DB::raw("'" . $module_name . "' AS type"),
                    DB::raw('NULL AS gedcom'),
                    DB::raw($table_prefix . 'b.block_id AS block_id'),
                    DB::raw($table_prefix . 'b.user_id'),
                ];
            }

            $subquery = DB::table('block AS b')
                ->select($select)
                ->where('b.module_name', '=', $module_name);

            if ($tree instanceof Tree) {
                $subquery->where(static function ($q) use ($tree): void {
                    $q->where('b.gedcom_id', '=', $tree->id())
                        ->orWhereNull('b.gedcom_id');
                });
            }

            if ($user_id !== null) {
                $subquery->where(static function ($q) use ($user_id): void {
                    $q->whereNull('b.user_id')
                        ->orWhere('b.user_id', '=', $user_id);
                });
            }

            if ($filter_xref !== '') {
                $uids = ($tree instanceof Tree) ? UidIndexService::uidsForRecord($tree->id(), $filter_xref) : [];
                $subquery->whereExists(static function ($q) use ($filter_xref, $uids, $text_settings): void {
                    $q->select(DB::raw(1))
                        ->from('block_setting')
                        ->whereColumn('block_setting.block_id', 'b.block_id')
                        ->whereIn('block_setting.setting_name', $text_settings)
                        ->where(static function ($sq) use ($filter_xref, $uids): void {
                            $sq->where('block_setting.setting_value', 'like', '%@' . $filter_xref . '@%');
                            foreach ($uids as $uid) {
                                $sq->orWhere('block_setting.setting_value', 'like', '%@' . $uid . '@%');
                            }
                        });
                });
            }

            $subquery->where(static function ($q) use ($text_settings): void {
                foreach ($text_settings as $setting_name) {
                    $q->orWhereExists(static function ($sub) use ($setting_name): void {
                        $sub->select(DB::raw(1))
                            ->from('block_setting')
                            ->whereColumn('block_setting.block_id', 'b.block_id')
                            ->where('block_setting.setting_name', '=', $setting_name);
                        if (self::supportsRegexp()) {
                            $sub->where('block_setting.setting_value', DB::regexOperator(), XrefsService::BLOCK_LE_PREFILTER);
                        } else {
                            $sub->where('block_setting.setting_value', 'like', XrefsService::BLOCK_LE_LIKE);
                        }
                    });
                }
            });

            $union = $union ? $union->unionAll($subquery) : $subquery;
        }

        return $union;
    }

    public static function rectypeSources(string $rectype): array
    {
        if ($rectype === '') {
            return ['gedcom' => true, 'blocks' => true, 'rectypes' => []];
        }
        if ($rectype === XrefsService::RECTYPE_ALL_GEDCOM) {
            return ['gedcom' => true, 'blocks' => false, 'rectypes' => []];
        }
        if ($rectype === XrefsService::RECTYPE_ALL_BLOCKS) {
            return ['gedcom' => false, 'blocks' => true, 'rectypes' => self::blockModuleNames()];
        }
        $upper = strtoupper($rectype);
        if (isset(XrefsService::GEDCOM_TABLES[$upper])) {
            return ['gedcom' => true, 'blocks' => false, 'rectypes' => [$rectype]];
        }
        if (in_array($upper, XrefsService::GEDCOM_OTHER_SUBTYPES, true)) {
            return ['gedcom' => true, 'blocks' => false, 'rectypes' => [$rectype]];
        }
        if (isset(XrefsService::BLOCKS[$rectype])) {
            return ['gedcom' => false, 'blocks' => true, 'rectypes' => [$rectype]];
        }
        return ['gedcom' => false, 'blocks' => false, 'rectypes' => []];
    }

    /**
     * @param array<string, string> $params
     */
    private static function getGedcomRecTypeSubquery(array $params, string $xref = Gedcom::REGEX_XREF, int|null $file = null): Builder
    {
        $table_prefix = DB::getTablePrefix();
        $p = $params['prefix'];

        $subquery = DB::table($params['table'] . " AS {$p}")
            ->select([
                DB::raw("{$p}.{$table_prefix}id AS file"),
                DB::raw("{$p}._id AS xref"),
                DB::raw($params['typestr'] . ' AS type'),
                DB::raw('NULL AS block_id'),
                DB::raw('NULL AS user_id'),
            ]);

        if ($file !== null) {
            $subquery->where("{$p}.{$table_prefix}id", '=', $file);
        }

        if (self::supportsRegexp()) {
            $subquery->where("{$p}.gedcom", DB::regexOperator(), XrefsService::LIKE_XREF_PREDICATE);
        } else {
            $subquery->where("{$p}.gedcom", 'like', XrefsService::LIKE_XREF_PREDICATE);
        }

        return $subquery;
    }

    public static function indexStatus(int $fresh_seconds = XrefsService::INDEX_FRESH_SECONDS): array
    {
        try {
            if (!DB::schema()->hasTable(XrefsService::INDEX_META_TABLE)
                || !DB::schema()->hasTable(XrefsService::INDEX_SCAN_TABLE)
            ) {
                return ['rows' => 0, 'scanned_at' => null, 'fresh' => false];
            }
            $meta = DB::table(XrefsService::INDEX_META_TABLE)->first(['last_run', 'rows']);

            $rows       = (int) ($meta->rows ?? 0);
            $scanned_at = $meta->last_run !== null ? (string) $meta->last_run : null;
            $fresh      = $scanned_at !== null
                && strtotime($scanned_at) > time() - $fresh_seconds;

            return ['rows' => $rows, 'scanned_at' => $scanned_at, 'fresh' => $fresh];
        } catch (Throwable $e) {
            LinkEnhancerModule::log()->debug('link index status unavailable: ' . $e->getMessage(), 'XrefsService');
            return ['rows' => 0, 'scanned_at' => null, 'fresh' => false];
        }
    }

    /**
     * @param array<int,string> $rectypes
     */
    public static function getIndexQuery(Tree|null $tree = null, ?string $target_xref = null, array $rectypes = [], bool $ordered = true): Builder
    {
        $table_prefix = DB::getTablePrefix();
        $links = DB::table(XrefsService::INDEX_LINK_TABLE)
            ->distinct()
            ->select('file', 'xref', 'rectype');
        if ($target_xref !== null && $target_xref !== '') {
            $links->where('target_xref', '=', $target_xref);
        }

        $query = DB::table(XrefsService::INDEX_SCAN_TABLE . ' AS s')
            ->joinSub($links, 'l', static function ($join): void {
                $join->on('l.file', '=', 's.file')
                    ->on('l.xref', '=', 's.xref')
                    ->on('l.rectype', '=', 's.rectype');
            })
            ->distinct()
            ->select(['s.file', 's.xref', DB::raw($table_prefix . 's.rectype AS type'), DB::raw('NULL AS block_id'), DB::raw('NULL AS user_id')])
            ->whereIn('s.rectype', self::normalizeIndexRectypes($rectypes));

        if ($ordered) {
            $query->orderBy('s.file')->orderBy('s.xref');
        }
        if ($tree instanceof Tree) {
            $query->where('s.file', '=', $tree->id());
        }

        return $query;
    }

    /**
     * @return array<int, array{tag_path: string, class: string, token: string, snippet: string|null}>
     */
    public static function indexRowLinks(int $file, string $xref, string $rectype): array
    {
        $links = [];
        foreach (DB::table(XrefsService::INDEX_LINK_TABLE)
            ->where('file', '=', $file)
            ->where('xref', '=', $xref)
            ->where('rectype', '=', $rectype)
            ->get(['tag_path', 'link_class', 'token', 'snippet']) as $row) {
            $key = $row->link_class . "\0" . $row->token;
            if (!isset($links[$key])) {
                $links[$key] = [
                    'tag_path' => (string) $row->tag_path,
                    'class'    => (string) $row->link_class,
                    'token'    => (string) $row->token,
                    'snippet'  => $row->snippet !== null ? (string) $row->snippet : null,
                ];
            }
        }

        return array_values($links);
    }

    /**
     * @param array<int,string> $rectypes
     * @return array<int,string>
     */
    private static function normalizeIndexRectypes(array $rectypes): array
    {
        $record_keys = self::supportedGedcomRecordKeys();
        $rectypes    = array_map('strtoupper', $rectypes);
        $rectypes    = array_values(array_filter($rectypes, static fn (string $s): bool => in_array($s, $record_keys, true)));
        if ($rectypes === []) {
            $rectypes = self::supportedGedcomTableKeys();
        }
        $expanded = array_values(array_filter($rectypes, static fn (string $s): bool => $s !== 'OTHER'));
        if (in_array('OTHER', $rectypes, true)) {
            $expanded = array_merge($expanded, XrefsService::GEDCOM_OTHER_SUBTYPES);
        }

        return array_values(array_unique($expanded));
    }

    /**
     * @return array<int,string>
     */
    public static function blockTextSettings(string $module_name): array
    {
        $block = XrefsService::BLOCKS[$module_name] ?? null;
        if ($block === null) {
            return [];
        }
        $settings = $block['settings'];
        return array_keys(array_filter($settings, static fn (string $val): bool => $val === 'text'));
    }

    /**
     * @return array<int,string>
     */
    public static function blockModuleNames(): array
    {
        return array_keys(XrefsService::BLOCKS);
    }
}
