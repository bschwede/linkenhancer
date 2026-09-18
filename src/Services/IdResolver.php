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

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\GedcomRecord;
use Fisharebest\Webtrees\Http\Exceptions\HttpException;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\TreeService;
use Fisharebest\Webtrees\Tree;

use function strlen;

/**
 * Resolve an id (a record XREF or a UID) to the visible candidate records.
 * Shared by the cross-reference overview (AdminXrefOverviewData) and the
 * goto-id route (see .opencode/plans/linkenhancer-uid-link-target-cross-tree-goto.md).
 *
 * Length-aware and bidirectional: an id at or above UID_MIN_LENGTH is tried
 * against the UID index first, a shorter one against the record XREF - and
 * when the first choice yields nothing, the other one is tried, so a
 * resolution is never lost. UIDs resolve globally (across trees); a bare XREF
 * still needs a tree to resolve against, so in a tree-less context
 * (HTML/FAQ block, no explicit @tree) only the global UID lookup runs.
 *
 * Visibility is enforced per candidate with Auth::checkRecordAccess (R3).
 */
final class IdResolver
{
    /**
     * Minimum length for an id to be treated as UID-first. Shorter ids are
     * XREF-first. Single source of truth for the length heuristic - the CLI
     * builder (cli/build-link-index.php) uses the same constant to split
     * target_xref / target_uid offline. UIDs are at least 18 chars (PAF / v5),
     * XREFs at most 20; the 18-20 overlap is resolved by the bidirectional
     * fallback.
     */
    public const int UID_MIN_LENGTH = 18;

    /**
     * @param Tree|null $tree            the tree to resolve in; null = no tree context (global UID-only)
     * @param int|null  $source_tree_id  the source record's tree, for the cross-tree label (0/null = no source)
     *
     * @return array<int, array{record: GedcomRecord, uid: string|null, rectype: string, tag_path: string, tree_label: string}>
     */
    public static function candidates(string $id, ?Tree $tree, ?int $source_tree_id = null): array
    {
        if ($tree === null) {
            return self::uidCandidates($id, null, $source_tree_id);
        }

        if (strlen($id) >= self::UID_MIN_LENGTH) {
            $candidates = self::uidCandidates($id, (int) $tree->id(), $source_tree_id);
            if ($candidates !== []) {
                return $candidates;
            }

            return self::xrefCandidates($id, $tree, $source_tree_id);
        }

        $candidates = self::xrefCandidates($id, $tree, $source_tree_id);
        if ($candidates !== []) {
            return $candidates;
        }

        return self::uidCandidates($id, (int) $tree->id(), $source_tree_id);
    }

    /**
     * @return array<int, array{record: GedcomRecord, uid: string|null, rectype: string, tag_path: string, tree_label: string}>
     */
    private static function xrefCandidates(string $id, Tree $tree, ?int $source_tree_id): array
    {
        $record = Registry::gedcomRecordFactory()->make($id, $tree);
        if (!$record instanceof GedcomRecord) {
            return [];
        }

        try {
            Auth::checkRecordAccess($record, false);
        } catch (HttpException) {
            return [];
        }

        return [[
            'record'     => $record,
            'uid'        => null,
            'rectype'    => $record->tag(),
            'tag_path'   => '',
            'tree_label' => self::treeLabel($record, $source_tree_id),
        ]];
    }

    /**
     * @return array<int, array{record: GedcomRecord, uid: string|null, rectype: string, tag_path: string, tree_label: string}>
     */
    private static function uidCandidates(string $id, ?int $tree_id, ?int $source_tree_id): array
    {
        $tree_service = Registry::container()->get(TreeService::class);
        $candidates   = [];

        foreach (UidIndexService::lookup($id, $tree_id) as $hit) {
            $tree = $tree_service->find((int) $hit->file);
            if (!$tree instanceof Tree) {
                continue;
            }

            $record = Registry::gedcomRecordFactory()->make((string) $hit->xref, $tree);
            if (!$record instanceof GedcomRecord) {
                continue;
            }

            try {
                Auth::checkRecordAccess($record, false);
            } catch (HttpException) {
                continue;
            }

            $candidates[] = [
                'record'     => $record,
                'uid'        => (string) $hit->uid,
                'rectype'    => (string) $hit->rectype,
                'tag_path'   => (string) $hit->tag_path,
                'tree_label' => self::treeLabel($record, $source_tree_id),
            ];
        }

        return $candidates;
    }

    /**
     * The tree name when the record is not in the source record's tree (the
     * overview prefixes such labels), else an empty string.
     */
    private static function treeLabel(GedcomRecord $record, ?int $source_tree_id): string
    {
        $tree = $record->tree();

        return ((int) $tree->id() !== $source_tree_id) ? $tree->name() : '';
    }
}
