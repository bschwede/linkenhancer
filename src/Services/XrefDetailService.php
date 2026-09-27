<?php

/*
 * webtrees - linkenhancer (custom module)
 *
 * Copyright (C) 2026 Bernd Schwendinger
 *
 * webtrees: online genealogy
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

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\GedcomRecord;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\TreeService;
use Fisharebest\Webtrees\Tree;
use Schwendinger\Webtrees\Helpers\Functions;

/**
 * Provides outgoing and incoming cross-reference data for a single record,
 * for display on the record detail pages (individual tab, note/media/source/
 * repository tabs, family modal).
 *
 * Outgoing: link targets found in the record's own text (privacy-filtered).
 * Incoming: records that reference this record (from the link index,
 *           visibility-filtered, deduplicated per source record).
 */
final class XrefDetailService
{
    
    private TreeService $tree_service;

    public function __construct() {
        $this->tree_service = Registry::container()->get(TreeService::class);
    }    

    /**
     * Get the outgoing link inventory from a record's text.
     *
     * For managers/admins the raw GEDCOM is used (privatization is a no-op).
     * For other users the privatized GEDCOM is classified.
     *
     * @return array{entries: array, counts: array<string, int>}
     */
    public function outgoingLinks(GedcomRecord $record): array
    {
        $tree = $record->tree();

        if (Auth::isManager($tree)) {
            $text = $record->gedcom();
        } else {
            $text = Functions::getPrivatizedGedcom($record, Auth::accessLevel($tree));
        }

        if ($text === '') {
            return ['entries' => [], 'counts' => XrefsService::emptyCounts()];
        }

        return XrefsService::classifyGedcomText(
            $text,
            TextTagCollector::DEFAULT_TAGS,
            $record->tag()
        );
    }

    /**
     * Get incoming references to a given XREF from the link index.
     *
     * Returns deduplicated source records (by file, xref, rectype) with
     * a link count per source. Records not visible to the current user
     * are filtered out. Includes same-tree and cross-tree references.
     *
     * When $include_blocks is true, block modules that reference the XREF
     * are also included.
     *
     * @return array<int, array{xref: string, rectype: string, name: string, url: string, link_count: int, tree_label: string, source: string}>
     */
    public function incomingReferences(Tree $tree, string $xref, bool $include_blocks = false): array
    {
        $uids = UidIndexService::uidsForRecord((int) $tree->id(), $xref);

        $rows = DB::table(XrefsService::INDEX_SCAN_TABLE . ' AS s')
            ->join(XrefsService::INDEX_LINK_TABLE . ' AS l', static function ($join): void {
                $join->on('l.file', '=', 's.file')
                    ->on('l.xref', '=', 's.xref')
                    ->on('l.rectype', '=', 's.rectype');
            })
            ->where(function ($q) use ($tree, $xref, $uids): void {
                $q->where(function ($q2) use ($tree): void {
                    $q2->where('l.target_tree', '=', $tree->name())
                        ->orWhere(function ($q3) use ($tree): void {
                            $q3->whereNull('l.target_tree')
                                ->where('l.file', '=', $tree->id());
                        });
                });
                $q->where(function ($q2) use ($xref, $uids): void {
                    $q2->where('l.target_xref', '=', $xref);
                    if ($uids !== []) {
                        $q2->orWhereIn('l.target_uid', $uids);
                    }
                });
            })
            ->select(['s.file', 's.xref', DB::raw('s.rectype AS rectype'), DB::raw('COUNT(*) AS link_count')])
            ->groupBy('s.file', 's.xref', 's.rectype')
            ->orderBy('s.file')
            ->orderBy('s.rectype')
            ->orderBy('s.xref')
            ->get();

        $results = [];
        foreach ($rows as $row) {
            $source_tree = $this->tree_service->all()->first(static fn (Tree $t): bool => $t->id() === (int) $row->file);
            if (!$source_tree instanceof Tree) {
                continue;
            }

            $record = Registry::gedcomRecordFactory()->make($row->xref, $source_tree);
            if ($record === null || !$record->canShow()) {
                continue;
            }

            $results[] = [
                'xref'       => $row->xref,
                'rectype'    => $row->rectype,
                'name'       => $record->fullName(),
                'url'        => $record->url(),
                'link_count' => (int) $row->link_count,
                'tree_label' => IdResolver::treeLabel($record, $tree->id()), // $source_tree->id() === $tree->id() ? '' : $source_tree->name(),
                'source'     => 'gedcom',
            ];
        }

        if ($include_blocks) {
            $results = array_merge($results, $this->incomingBlockReferences($tree, $xref));
        }

        return $results;
    }

    /**
     * Find block modules in the given tree that reference the XREF.
     *
     * @return array<int, array{xref: string, rectype: string, name: string, url: string, link_count: int, tree_label: string, source: string}>
     */
    private function incomingBlockReferences(Tree $tree, string $xref): array
    {
        $results = [];
        $tree_id = (int) $tree->id();
        $user_id = Auth::isAdmin() ? null : (int) Auth::id();
        $uids    = UidIndexService::uidsForRecord($tree_id, $xref);

        $accessable_tree_ids = $this->tree_service->all()->map(static fn(Tree $tree): int => $tree->id());        

        foreach (XrefsService::BLOCKS as $module_name => $block_def) {
            $text_settings = XrefsService::blockTextSettings($module_name);
            if ($text_settings === []) {
                continue;
            }

            $query = DB::table('block AS b')
                ->join('block_setting AS bs', 'bs.block_id', '=', 'b.block_id')
                ->where('b.module_name', '=', $module_name)
                ->whereIn('bs.setting_name', $text_settings)
                ->where(function ($q) use ($xref, $uids): void {
                    $q->where('bs.setting_value', 'like', '%@' . $xref . '@%');
                    foreach ($uids as $uid) {
                        $q->orWhere('bs.setting_value', 'like', '%@' . $uid . '@%');
                    }
                })
                ->where(static function ($q) use ($user_id, $accessable_tree_ids): void {
                    // user_id AND gedcom_id are NULL or one of them is set
                    $q->whereIn('b.gedcom_id', $accessable_tree_ids)
                        ->WhereNull('b.user_id')
                        ->orWhereNull('b.gedcom_id')
                        ->whereNull('b.user_id');
                    if ($user_id !== null) {
                        $q->orWhereNull('b.gedcom_id')
                            ->where('b.user_id', '=', $user_id);
                    } else {
                        $q->orWhereNull('b.gedcom_id')
                            ->where('b.user_id', '!=', null);
                    }
                });

            $rows = $query
                ->groupBy('b.block_id', 'b.gedcom_id', 'b.module_name')
                ->select(['b.block_id', 'b.gedcom_id', 'b.module_name', DB::raw('COUNT(*) AS link_count')])
                ->get();

            foreach ($rows as $row) {
                $source_tree = $this->tree_service->all()->first(static fn(Tree $t): bool => $t->id() === (int) ($row->gedcom_id ?? -1));
                $title_setting = $block_def['settings']['title'] ?? null;
                $block_title = $title_setting !== null
                    ? (string) DB::table('block_setting')
                        ->where('block_id', '=', $row->block_id)
                        ->where('setting_name', '=', $title_setting)
                        ->value('setting_value')
                    : '';

                $results[] = [
                    'xref'       => 'BLOCK-' . $row->block_id,
                    'rectype'    => $block_def['title'],
                    'name'       => $block_title !== '' ? $block_title : $block_def['title'],
                    'url'        => '',
                    'link_count' => (int) $row->link_count,
                    'tree_label' => $source_tree ? ($source_tree->id() === $tree->id() ? '' : $source_tree->name()) : '',
                    'source'     => 'block',
                ];
            }
        }

        return $results;
    }

    /**
     * Render the outgoing link inventory as HTML (same as the admin overview's
     * data-record column, without the title/name). Uses linkInventoryHtml with
     * a target linker that resolves XREFs via the record factory.
     */
    public function outgoingLinksHtml(GedcomRecord $record, int $max_links = XrefsService::LINKS_PER_CLASS_DEFAULT): string
    {
        $result = $this->outgoingLinks($record);
        if ($result['entries'] === []) {
            return '';
        }

        $tree = $record->tree();

        $target_linker = function (string $xref, ?string $target_tree_name) use ($tree): ?array {
            $t = ($target_tree_name !== null && $target_tree_name !== '')
                ? $this->tree_service->all()->get($target_tree_name)
                : $tree;
            if (!$t instanceof Tree) {
                return null;
            }

            $candidates = IdResolver::candidates($xref, $t, $t->id());
            if ($candidates === []) {
                return null;
            }

            $candidate = $candidates[0];
            return [
                'name' => $candidate['record']->fullName(),
                'url' => $candidate['record']->url(),
                'tree_label' => $candidate['tree_label'],
                'actual' => $candidate['record']->tag(),
            ];
        };

        $html = XrefsService::linkInventoryHtml(
            $result['entries'],
            $max_links,
            '',
            $target_linker,
            true
        );

        $html = '<div class="col-md-10">' . $html . '</div><div class="col-md-2 text-muted small">' . XrefsService::linkCountSummary($result['counts']) . '</div>';

        return $html;
    }

    /**
     * Quick count of total cross-references (outgoing + incoming) for a record.
     * Used for the badge on non-INDI record pages.
     */
    public function totalCount(GedcomRecord $record): int
    {
        $outgoing = $this->outgoingLinks($record);
        $out_count = 0;
        foreach ($outgoing['counts'] as $n) {
            $out_count += $n;
        }

        $incoming = $this->incomingReferences($record->tree(), $record->xref());
        $in_count = 0;
        foreach ($incoming as $ref) {
            $in_count += $ref['link_count'];
        }

        return $out_count + $in_count;
    }
}
