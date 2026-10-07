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

namespace Schwendinger\Webtrees\Module\LinkEnhancer\DataFix\Handlers;

use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\GedcomRecord;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\DataFixService;
use Fisharebest\Webtrees\Services\TreeService;
use Fisharebest\Webtrees\Tree;
use Illuminate\Support\Collection;
use Schwendinger\Webtrees\Module\LinkEnhancer\DataFix\FixHandlerInterface;
use Schwendinger\Webtrees\Module\LinkEnhancer\LinkEnhancerModule;
use Schwendinger\Webtrees\Module\LinkEnhancer\Services\IdResolver;
use Schwendinger\Webtrees\Module\LinkEnhancer\Services\IndexRebuildScheduler;
use Schwendinger\Webtrees\Module\LinkEnhancer\Services\UidIndexService;
use Schwendinger\Webtrees\Module\LinkEnhancer\Services\XrefsService;

use function preg_match;
use function preg_replace_callback;
use function strlen;
use function strpos;
use function strrpos;
use function substr;
use function str_starts_with;

final class XrefUidSwapFix implements FixHandlerInterface
{
    public const ID = 'xref_uid_swap';

    public const DIR_XREF_TO_UID = 'xref_to_uid';
    public const DIR_UID_TO_XREF = 'uid_to_xref';

    /** @var Tree[] tree_name => Tree (per-request cache) */
    private array $tree_cache = [];

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return I18N::translate('XREF ⇄ UID reference swap');
    }

    public function optionsHtml(Tree $tree, array $params): string
    {
        return view(LinkEnhancerModule::MODULE_NAME . '::datafix-xref-uid-swap-options', [
            'direction'  => (string) ($params['direction'] ?? self::DIR_XREF_TO_UID),
            'uid_active' => UidIndexService::uidFeatureEnabled(),
        ]);
    }

    public function recordsToFix(Tree $tree, array $params): Collection
    {
        $direction = (string) ($params['direction'] ?? self::DIR_XREF_TO_UID);
        $rectypes  = $this->selectedRectypes($params);

        $query = DB::table(XrefsService::INDEX_LINK_TABLE)
            ->where('file', '=', $tree->id())
            ->whereIn('rectype', $rectypes);

        if ($direction === self::DIR_XREF_TO_UID) {
            $query->whereNotNull('target_xref')->whereNull('target_uid');
        } else {
            $query->whereNotNull('target_uid');
        }

        $rows = $query->distinct()->select('file', 'xref', 'rectype')->get();

        return $rows
            ->map(static fn (object $row): object => (object) ['xref' => $row->xref, 'type' => $row->rectype])
            ->values();
    }

    public function needsUpdate(GedcomRecord $record, array $params): bool
    {
        $direction = (string) ($params['direction'] ?? self::DIR_XREF_TO_UID);
        $gedcom    = $record->gedcom();

        if ($direction === self::DIR_XREF_TO_UID) {
            return (bool) preg_match('/[?&@](?:wt|id)=[a-z]?@[A-Za-z0-9][A-Za-z0-9:_.-]{0,17}@/', $gedcom);
        }

        return (bool) preg_match('/[?&@](?:wt|id)=[a-z]?@[A-Za-z0-9][A-Za-z0-9:_.-]{17,254}@/', $gedcom);
    }

    public function preview(GedcomRecord $record, array $params): string
    {
        $result = $this->convert($record->gedcom(), $params, $record->tree());
        $data_fix_service = Registry::container()->get(DataFixService::class);

        return $data_fix_service->gedcomDiff($record->tree(), $record->gedcom(), $result['gedcom']);
    }

    public function apply(GedcomRecord $record, array $params): void
    {
        $tree   = $record->tree();
        $result = $this->convert($record->gedcom(), $params, $tree);

        if ($result['swapped'] > 0) {
            $record->updateRecord($result['gedcom'], false);

            if (!IndexRebuildScheduler::canDefer(IndexRebuildScheduler::EVENT_LINK_INDEX)) {
                $this->repairIndexInline($tree, $record->xref());
            }
        }

        if (IndexRebuildScheduler::canDefer(IndexRebuildScheduler::EVENT_LINK_INDEX)) {
            IndexRebuildScheduler::defer(['link' => true, 'uid' => false], $tree->id());
        }
    }

    public function processBlocks(Tree $tree, array $params): array
    {
        $direction = (string) ($params['direction'] ?? self::DIR_XREF_TO_UID);
        $processed = 0;
        $changed   = 0;
        $skipped   = 0;
        $errors    = [];
        $details   = [];

        $pre_filter = XrefsService::BLOCK_LE_LIKE;

        foreach (XrefsService::BLOCKS as $module_name => $block_def) {
            $text_settings = XrefsService::blockTextSettings($module_name);
            if ($text_settings === []) {
                continue;
            }

            $title_setting = $block_def['settings']['title'] ?? null;

            $query = DB::table('block AS b')
                ->join('block_setting AS bs', 'bs.block_id', '=', 'b.block_id')
                ->where('b.module_name', '=', $module_name)
                ->whereIn('bs.setting_name', $text_settings)
                ->where('bs.setting_value', 'LIKE', '%' . $pre_filter . '%')
                ->where('b.gedcom_id', '=', $tree->id())
                ->select(['b.block_id', 'b.gedcom_id', 'bs.setting_name', 'bs.setting_value']);

            $rows = $query->get();

            $block_changes = 0;
            foreach ($rows as $row) {
                $processed++;
                $swapped = 0;
                $new_value = preg_replace_callback(
                    XrefsService::RE_LE_HTML_LINK,
                    function (array $m) use ($direction, $tree, &$swapped, &$skipped): string {
                        $token    = $m[0];
                        $href_pos = strpos($token, 'href="');
                        $quote    = '"';
                        if ($href_pos === false) {
                            $href_pos = strpos($token, "href='");
                            $quote    = "'";
                        }
                        if ($href_pos === false) {
                            return $token;
                        }
                        $href_start = $href_pos + 6;
                        $href_end   = strpos($token, $quote, $href_start);
                        if ($href_end === false) {
                            return $token;
                        }
                        $href_val = substr($token, $href_start, $href_end - $href_start);
                        $is_le    = str_starts_with($href_val, '#@');
                        $url      = $this->convertWtTargets(
                            $is_le ? substr($href_val, 2) : $href_val,
                            $direction,
                            $tree,
                            $swapped,
                            $skipped
                        );
                        return substr($token, 0, $href_start) . ($is_le ? '#@' : '') . $url . substr($token, $href_end);
                    },
                    $row->setting_value
                );

                if ($swapped > 0) {
                    DB::table('block_setting')
                        ->where('block_id', '=', $row->block_id)
                        ->where('setting_name', '=', $row->setting_name)
                        ->update(['setting_value' => $new_value]);
                    $changed++;
                    $block_changes += $swapped;
                } else {
                    $skipped++;
                }
            }

            if ($new_value !==  $row->setting_value) { //$processed > 0) {
                $block_title = $title_setting !== null
                    ? (string) DB::table('block_setting')
                        ->where('block_id', '=', $row->block_id)
                        ->where('setting_name', '=', $title_setting)
                        ->value('setting_value')
                    : '';
                $details[] = [
                    'name'    => $block_title !== '' ? $block_title : $block_def['title'],
                    'module'  => $module_name,
                    'changes' => $block_changes,
                ];
            }
        }

        return ['processed' => $processed, 'changed' => $changed, 'skipped' => $skipped, 'errors' => $errors, 'details' => $details];
    }

    private function findTreeById(int $tree_id): ?Tree
    {
        try {
            return Registry::container()->get(TreeService::class)->find($tree_id);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Core token rewrite. Returns the new GEDCOM text + counters.
     *
     * @param array<string, string> $params
     * @return array{gedcom: string, swapped: int, skipped: int}
     */
    private function convert(string $gedcom, array $params, Tree $source_tree): array
    {
        $direction = (string) ($params['direction'] ?? self::DIR_XREF_TO_UID);
        $swapped   = 0;
        $skipped   = 0;

        // Markdown le-links: [text](#@url) / ![pic](#@url)
        $gedcom = preg_replace_callback(
            XrefsService::RE_LE_LINK,
            function (array $m) use ($direction, $source_tree, &$swapped, &$skipped): string {
                $token = $m[0];
                $pos   = strrpos($token, '(#@');
                if ($pos === false) {
                    return $token;
                }
                $head = substr($token, 0, $pos + 3);
                $url  = $this->convertWtTargets(
                    substr($token, $pos + 3, -1),
                    $direction,
                    $source_tree,
                    $swapped,
                    $skipped
                );
                return $head . $url . ')';
            },
            $gedcom
        );

        return ['gedcom' => $gedcom, 'swapped' => $swapped, 'skipped' => $skipped];
    }

    /**
     * Apply the direction-specific swap to all wt= targets in one extracted URL.
     */
    private function convertWtTargets(
        string $url,
        string $direction,
        Tree $source_tree,
        int &$swapped,
        int &$skipped
    ): string {
        return preg_replace_callback(
            XrefsService::RE_WT_TARGET,
            function (array $m) use ($direction, $source_tree, &$swapped, &$skipped): string {
                $value = $m['xref'];
                $url_tree = ($m['tree'] !== '' ? XrefsService::stripDiaSuffix((string) $m['tree']) : '');
                $target_tree_name = ($url_tree === '' ? $source_tree->name() : $url_tree);

                if ($direction === self::DIR_XREF_TO_UID) {
                    if (strlen($value) >= IdResolver::UID_MIN_LENGTH) {
                        return $m[0]; // already a UID, nothing to do
                    }
                    $uid = $this->resolveXrefToUid($value, $target_tree_name);
                    if ($uid === null) {
                        $skipped++;
                        return $m[0];
                    }
                    $swapped++;
                    return $this->replaceValueInMatch($m[0], $value, $uid);
                }

                // uid_to_xref
                if (strlen($value) < IdResolver::UID_MIN_LENGTH) {
                    return $m[0]; // already an XREF, nothing to do
                }
                $xref = $this->resolveUidToXref($value, $target_tree_name);
                if ($xref === null) {
                    $skipped++;
                    return $m[0];
                }
                $swapped++;
                return $this->replaceValueInMatch($m[0], $value, $xref);
            },
            $url
        );
    }

    /**
     * Replace the value between the @...@ in a wt= match string.
     */
    private function replaceValueInMatch(string $match, string $old_value, string $new_value): string
    {
        return str_replace('@' . $old_value . '@', '@' . $new_value . '@', $match);
    }

    /**
     * Resolve an XREF to the UID of the target record.
     * Returns null if the record has no UID or the target tree is not found.
     */
    private function resolveXrefToUid(string $xref, string $target_tree_name): ?string
    {
        $tree = $this->findTree($target_tree_name);
        if ($tree === null) {
            return null;
        }

        $uids = UidIndexService::uidsForRecord($tree->id(), $xref);
        return $uids[0] ?? null;
    }

    /**
     * Resolve a UID to the XREF of the target record.
     * Returns null if the UID is not found or is ambiguous (multiple results).
     */
    private function resolveUidToXref(string $uid, string $target_tree_name): ?string
    {
        $tree = $this->findTree($target_tree_name);
        if ($tree === null) {
            return null;
        }

        $results = UidIndexService::lookup($uid, $tree->id());
        if ($results->isEmpty()) {
            return null;
        }
        if ($results->count() > 1) {
            return null; // ambiguous
        }

        return (string) $results->first()->xref;
    }

    /**
     * Resolve a tree name to a Tree object (cached per request).
     */
    private function findTree(string $name): ?Tree
    {
        if (array_key_exists($name, $this->tree_cache)) {
            return $this->tree_cache[$name];
        }
        try {
            $tree = Registry::container()->get(TreeService::class)->all()->get($name);
            $this->tree_cache[$name] = $tree;
            return $tree;
        } catch (\Throwable) {
            $this->tree_cache[$name] = null;
            return null;
        }
    }

    /**
     * Inline index repair fallback (when cron deferral is not active).
     * Deletes and lets the next build re-populate; targeted update of the
     * source record's index rows.
     */
    private function repairIndexInline(Tree $tree, string $xref): void
    {
        DB::table(XrefsService::INDEX_LINK_TABLE)
            ->where('file', '=', $tree->id())
            ->where('xref', '=', $xref)
            ->delete();

        DB::table(XrefsService::INDEX_SCAN_TABLE)
            ->where('file', '=', $tree->id())
            ->where('xref', '=', $xref)
            ->delete();
    }

    /**
     * Extract selected record types from params (default: all).
     * Uses individual field names (rectype_INDI, rectype_FAM, ...) to avoid
     * the core data-fix-page.phtml JS bug with FormData array overwriting.
     *
     * @return array<int, string>
     */
    private function selectedRectypes(array $params): array
    {
        $all = ['INDI', 'FAM', 'NOTE', 'SOUR', 'REPO', '_LOC', 'OBJE'];
        $selected = [];
        foreach ($all as $rt) {
            if (!empty($params['rectype_' . $rt])) {
                $selected[] = $rt;
            }
        }
        return $selected;
    }
}
