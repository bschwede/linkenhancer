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
     * are filtered out.
     *
     * @return array<int, array{xref: string, rectype: string, name: string, url: string, link_count: int}>
     */
    public function incomingReferences(Tree $tree, string $xref): array
    {
        $rows = DB::table(XrefsService::INDEX_SCAN_TABLE . ' AS s')
            ->join(XrefsService::INDEX_LINK_TABLE . ' AS l', static function ($join): void {
                $join->on('l.file', '=', 's.file')
                    ->on('l.xref', '=', 's.xref')
                    ->on('l.rectype', '=', 's.rectype');
            })
            ->where('l.target_xref', '=', $xref)
            ->where('s.file', '=', $tree->id())
            ->select(['s.file', 's.xref', DB::raw('s.rectype AS rectype'), DB::raw('COUNT(*) AS link_count')])
            ->groupBy('s.file', 's.xref', 's.rectype')
            ->orderBy('s.rectype')
            ->orderBy('s.xref')
            ->get();

        $results = [];
        foreach ($rows as $row) {
            $record = Registry::gedcomRecordFactory()->make($row->xref, $tree);
            if ($record === null || !$record->canShow()) {
                continue;
            }

            $results[] = [
                'xref'       => $row->xref,
                'rectype'    => $row->rectype,
                'name'       => $record->fullName(),
                'url'        => $record->url(),
                'link_count' => (int) $row->link_count,
            ];
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
        $tree_id = (int) $tree->id();

        $target_linker = function (string $xref, ?string $target_tree_name) use ($tree): ?array {
            $t = ($target_tree_name !== null && $target_tree_name !== '')
                ? Registry::container()->get(\Fisharebest\Webtrees\Services\TreeService::class)->all()->get($target_tree_name)
                : $tree;
            if (!$t instanceof Tree) {
                return null;
            }
            $rec = Registry::gedcomRecordFactory()->make($xref, $t);
            if ($rec === null) {
                return null;
            }
            return [
                'name'       => $rec->fullName(),
                'url'        => $rec->url(),
                'tree_label' => $t->name(),
                'actual'     => $rec->tag(),
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
