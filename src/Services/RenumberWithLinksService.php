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

use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Family;
use Fisharebest\Webtrees\GedcomRecord;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Media;
use Fisharebest\Webtrees\Note;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Repository;
use Fisharebest\Webtrees\Services\AdminService;
use Fisharebest\Webtrees\Services\TimeoutService;
use Fisharebest\Webtrees\Services\TreeService;
use Fisharebest\Webtrees\Source;
use Fisharebest\Webtrees\Tree;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Query\JoinClause;

/**
 * Single-pass renumber of this tree's cross-tree XREF collisions that ALSO keeps
 * the linkenhancer le-links (GEDCOM text) and the le_* index consistent.
 *
 * Port of the core RenumberTreeAction (app/Http/RequestHandlers/RenumberTreeAction.php)
 * for the core tables, plus repairLeLinks() (le-link text) and repairLeIndexes()
 * (le_* index, inline fallback). The le_* index update is deferred to the cronjob
 * module (IndexRebuildScheduler) when an active job exists; otherwise the inline
 * repairLeIndexes() runs. See wt2-2-6_data-fix-renumber.md / -index-rebuild-cron.md.
 */
final class RenumberWithLinksService
{
    /** table => row prefix (i/f/m/s/o) - mirrors the core renumber's per-table blocks. */
    private const PREFIX = [
        'individuals' => 'i',
        'families'    => 'f',
        'media'       => 'm',
        'sources'     => 's',
        'other'       => 'o',
    ];

    public function __construct(
        private readonly AdminService $admin_service,
        private readonly TimeoutService $timeout_service,
        private readonly TreeService $tree_service,
    ) {
    }

    /**
     * Renumber the cross-tree XREF collisions of $tree.
     *
     * @return array<string, mixed> report
     */
    public function renumber(Tree $tree, bool $dry_run = false): array
    {
        $conflicts = $this->admin_service->duplicateXrefs($tree);

        if ($dry_run) {
            $list = [];
            foreach ($conflicts as $old => $type) {
                $list[] = ['old' => (string) $old, 'type' => (string) $type];
            }

            $plan = IndexRebuildScheduler::deferPlan();

            return [
                'dry_run'     => true,
                'count'       => count($list),
                'list'        => $list,
                'defer_index' => $plan['link'] || $plan['uid'],
                'defer'       => $plan,
            ];
        }

        // Decide which index parts can be deferred to cron (each independently).
        $defer = IndexRebuildScheduler::deferPlan();

        $report = [
            'dry_run'      => false,
            'timed_out'    => false,
            'count'        => count($conflicts),
            'per'          => [],
            'core_rows'    => 0,
            'links'        => 0,
            'index_rows'   => 0,
            'defer_index'  => $defer['link'] || $defer['uid'],
            'defer'        => $defer,
        ];

        // No transaction (matches the core RenumberTreeAction): conflicts are
        // applied one by one with partial progress - on the time limit we stop
        // and the remaining conflicts are renumbered on a later run.
        foreach ($conflicts as $old_xref => $type) {
            $old_xref = (string) $old_xref;
            $type     = (string) $type;

            $new_xref = Registry::xrefFactory()->make($type);
            $core     = $this->applyCoreRename($tree, $old_xref, $new_xref, $type);
            $links    = $this->repairLeLinks($tree, $old_xref, $new_xref);

            $index = 0;
            if (!$defer['link']) {
                $index += $this->repairLinkIndexes($tree, $old_xref, $new_xref);
            }
            if (!$defer['uid']) {
                $index += $this->repairUidIndexes($tree, $old_xref, $new_xref);
            }

            $report['per'][] = [
                'old'        => $old_xref,
                'new'        => $new_xref,
                'type'       => $type,
                'core_rows'  => $core,
                'links'      => $links,
                'index_rows' => $index,
            ];
            $report['core_rows']  += $core;
            $report['links']      += $links;
            $report['index_rows'] += $index;

            // How much time do we have left? Stop with partial progress (core pattern).
            if ($this->timeout_service->isTimeNearlyUp()) {
                $report['timed_out'] = true;
                $report['message']   = I18N::translate('The server’s time limit has been reached.');
                break;
            }
        }

        // Push the dirty events for the deferred index parts - the incremental
        // rebuild converges on whatever actually changed.
        IndexRebuildScheduler::defer($defer, $tree->id());

        return $report;
    }

    /**
     * Port of the core per-record XREF rename (RenumberTreeAction.php:79-552).
     * Renames the record in the core tables and rewrites the core tag links that
     * point to it. Returns the affected-row count of the primary rename.
     */
    private function applyCoreRename(Tree $tree, string $old_xref, string $new_xref, string $type): int
    {
        $tree_id = $tree->id();
        $primary = 0;

        switch ($type) {
            case Individual::RECORD_TYPE:
                $primary = DB::table('individuals')
                    ->where('i_file', '=', $tree_id)
                    ->where('i_id', '=', $old_xref)
                    ->update([
                        'i_id'     => $new_xref,
                        'i_gedcom' => new Expression("REPLACE(i_gedcom, '0 @$old_xref@ INDI', '0 @$new_xref@ INDI')"),
                    ]);

                DB::table('families')
                    ->where('f_husb', '=', $old_xref)->where('f_file', '=', $tree_id)
                    ->update(['f_husb' => $new_xref, 'f_gedcom' => new Expression("REPLACE(f_gedcom, ' HUSB @$old_xref@', ' HUSB @$new_xref@')")]);

                DB::table('families')
                    ->where('f_wife', '=', $old_xref)->where('f_file', '=', $tree_id)
                    ->update(['f_wife' => $new_xref, 'f_gedcom' => new Expression("REPLACE(f_gedcom, ' WIFE @$old_xref@', ' WIFE @$new_xref@')")]);

                $this->replaceTaggedLinks($tree_id, $old_xref, $new_xref, 'families', 'f', ['CHIL', 'ASSO', '_ASSO']);
                $this->replaceTaggedLinks($tree_id, $old_xref, $new_xref, 'individuals', 'i', ['ALIA', 'ASSO', '_ASSO']);

                DB::table('placelinks')->where('pl_file', '=', $tree_id)->where('pl_gid', '=', $old_xref)->update(['pl_gid' => $new_xref]);
                DB::table('dates')->where('d_file', '=', $tree_id)->where('d_gid', '=', $old_xref)->update(['d_gid' => $new_xref]);

                DB::table('user_gedcom_setting')
                    ->where('gedcom_id', '=', $tree_id)
                    ->where('setting_value', '=', $old_xref)
                    ->whereIn('setting_name', [UserInterface::PREF_TREE_ACCOUNT_XREF, UserInterface::PREF_TREE_DEFAULT_XREF])
                    ->update(['setting_value' => $new_xref]);
                break;

            case Family::RECORD_TYPE:
                $primary = DB::table('families')
                    ->where('f_file', '=', $tree_id)->where('f_id', '=', $old_xref)
                    ->update([
                        'f_id'     => $new_xref,
                        'f_gedcom' => new Expression("REPLACE(f_gedcom, '0 @$old_xref@ FAM', '0 @$new_xref@ FAM')"),
                    ]);

                $this->replaceTaggedLinks($tree_id, $old_xref, $new_xref, 'individuals', 'i', ['FAMC', 'FAMS']);

                DB::table('placelinks')->where('pl_file', '=', $tree_id)->where('pl_gid', '=', $old_xref)->update(['pl_gid' => $new_xref]);
                DB::table('dates')->where('d_file', '=', $tree_id)->where('d_gid', '=', $old_xref)->update(['d_gid' => $new_xref]);
                break;

            case Source::RECORD_TYPE:
                $primary = DB::table('sources')
                    ->where('s_file', '=', $tree_id)->where('s_id', '=', $old_xref)
                    ->update([
                        's_id'     => $new_xref,
                        's_gedcom' => new Expression("REPLACE(s_gedcom, '0 @$old_xref@ SOUR', '0 @$new_xref@ SOUR')"),
                    ]);

                foreach (['individuals', 'families', 'media', 'other'] as $t) {
                    $this->replaceTaggedLinks($tree_id, $old_xref, $new_xref, $t, self::PREFIX[$t], ['SOUR']);
                }
                break;

            case Repository::RECORD_TYPE:
                $primary = DB::table('other')
                    ->where('o_file', '=', $tree_id)->where('o_id', '=', $old_xref)->where('o_type', '=', 'REPO')
                    ->update([
                        'o_id'     => $new_xref,
                        'o_gedcom' => new Expression("REPLACE(o_gedcom, '0 @$old_xref@ REPO', '0 @$new_xref@ REPO')"),
                    ]);

                $this->replaceTaggedLinks($tree_id, $old_xref, $new_xref, 'sources', 's', ['REPO']);
                break;

            case Note::RECORD_TYPE:
                $primary = DB::table('other')
                    ->where('o_file', '=', $tree_id)->where('o_id', '=', $old_xref)->where('o_type', '=', 'NOTE')
                    ->update([
                        'o_id'     => $new_xref,
                        'o_gedcom' => new Expression("REPLACE(o_gedcom, '0 @$old_xref@ NOTE', '0 @$new_xref@ NOTE')"),
                    ]);

                foreach (['individuals', 'families', 'media', 'sources', 'other'] as $t) {
                    $this->replaceTaggedLinks($tree_id, $old_xref, $new_xref, $t, self::PREFIX[$t], ['NOTE']);
                }
                break;

            case Media::RECORD_TYPE:
                $primary = DB::table('media')
                    ->where('m_file', '=', $tree_id)->where('m_id', '=', $old_xref)
                    ->update([
                        'm_id'     => $new_xref,
                        'm_gedcom' => new Expression("REPLACE(m_gedcom, '0 @$old_xref@ OBJE', '0 @$new_xref@ OBJE')"),
                    ]);

                DB::table('media_file')->where('m_file', '=', $tree_id)->where('m_id', '=', $old_xref)->update(['m_id' => $new_xref]);

                foreach (['individuals', 'families', 'sources', 'other'] as $t) {
                    $this->replaceTaggedLinks($tree_id, $old_xref, $new_xref, $t, self::PREFIX[$t], ['OBJE']);
                }
                break;

            default:
                $primary = DB::table('other')
                    ->where('o_file', '=', $tree_id)->where('o_id', '=', $old_xref)->where('o_type', '=', $type)
                    ->update([
                        'o_id'     => $new_xref,
                        'o_gedcom' => new Expression("REPLACE(o_gedcom, '0 @$old_xref@ $type', '0 @$new_xref@ $type')"),
                    ]);

                foreach (['individuals', 'families', 'media', 'sources', 'other'] as $t) {
                    $this->replaceTaggedLinks($tree_id, $old_xref, $new_xref, $t, self::PREFIX[$t], [$type]);
                }
                break;
        }

        DB::table('name')->where('n_file', '=', $tree_id)->where('n_id', '=', $old_xref)->update(['n_id' => $new_xref]);
        DB::table('default_resn')->where('gedcom_id', '=', $tree_id)->where('xref', '=', $old_xref)->update(['xref' => $new_xref]);
        DB::table('hit_counter')->where('gedcom_id', '=', $tree_id)->where('page_parameter', '=', $old_xref)->update(['page_parameter' => $new_xref]);
        DB::table('link')->where('l_file', '=', $tree_id)->where('l_from', '=', $old_xref)->update(['l_from' => $new_xref]);
        DB::table('link')->where('l_file', '=', $tree_id)->where('l_to', '=', $old_xref)->update(['l_to' => $new_xref]);
        DB::table('favorite')->where('gedcom_id', '=', $tree_id)->where('xref', '=', $old_xref)->update(['xref' => $new_xref]);

        return $primary;
    }

    /**
     * Rewrite `<tag> @old@` -> `<tag> @new@` inside the $table GEDCOM text for every
     * row of $table that has a `link` row of type $tags pointing to $old_xref
     * (the shared core pattern, parameterised over the record table).
     *
     * @param array<int, string> $tags
     */
    private function replaceTaggedLinks(int $tree_id, string $old_xref, string $new_xref, string $table, string $prefix, array $tags): void
    {
        foreach ($tags as $tag) {
            DB::table($table)
                ->join('link', static function (JoinClause $join) use ($prefix): void {
                    $join->on('l_file', '=', $prefix . '_file')->on('l_from', '=', $prefix . '_id');
                })
                ->where('l_to', '=', $old_xref)
                ->where('l_type', '=', $tag)
                ->where($prefix . '_file', '=', $tree_id)
                ->update([$prefix . '_gedcom' => new Expression("REPLACE(" . $prefix . "_gedcom, ' $tag @$old_xref@', ' $tag @$new_xref@')")]);
        }
    }

    /**
     * Rewrite the le-link text of every record that references the renumbered
     * record (found via the link index), scoped to the renumbered tree. Handles
     * same-tree sources (target_tree NULL, file = this tree) and cross-tree
     * sources (target_tree = this tree's name).
     */
    private function repairLeLinks(Tree $tree, string $old_xref, string $new_xref): int
    {
        $sources = DB::table(XrefsService::INDEX_LINK_TABLE)
            ->where('target_xref', '=', $old_xref)
            ->where(static function ($q) use ($tree): void {
                $q->where(static function ($q2) use ($tree): void {
                        $q2->whereNull('target_tree')->where('file', '=', $tree->id());
                    })->orWhere('target_tree', '=', $tree->name());
            })
            ->distinct()
            ->get(['file', 'xref']);

        $total = 0;
        foreach ($sources as $source) {
            $source_tree = $this->resolveTree((int) $source->file);
            if ($source_tree === null) {
                continue;
            }

            $record = Registry::gedcomRecordFactory()->make((string) $source->xref, $source_tree);
            if (!$record instanceof GedcomRecord) {
                continue;
            }

            $result = XrefsService::replaceLinkTargetXref(
                $record->gedcom(),
                $old_xref,
                $new_xref,
                $source_tree->name(),
                $tree->name()
            );
            if ($result['replaced'] > 0) {
                $record->updateRecord($result['gedcom'], false);
                $total += $result['replaced'];
            }
        }

        return $total;
    }

    /**
     * Inline fallback for the LINK index (used only when the "link-index" cron
     * deferral is not active). Keeps target_xref references and the source-record
     * xref columns in sync.
     */
    private function repairLinkIndexes(Tree $tree, string $old_xref, string $new_xref): int
    {
        $tree_id = $tree->id();
        $rows    = 0;

        // References TO the renumbered record: same-tree (NULL + file) or
        // cross-tree (target_tree = this tree's name). Scoping the NULL branch to
        // this tree's file avoids rewriting other trees' own self-references.
        $rows += DB::table(XrefsService::INDEX_LINK_TABLE)
            ->where('target_xref', '=', $old_xref)
            ->where(static function ($q) use ($tree, $tree_id): void {
                $q->where(static function ($q2) use ($tree_id): void {
                        $q2->whereNull('target_tree')->where('file', '=', $tree_id);
                    })->orWhere('target_tree', '=', $tree->name());
            })
            ->update(['target_xref' => $new_xref]);

        // The source record itself was renamed (this tree only).
        $rows += DB::table(XrefsService::INDEX_LINK_TABLE)->where('file', '=', $tree_id)->where('xref', '=', $old_xref)->update(['xref' => $new_xref]);
        $rows += DB::table(XrefsService::INDEX_SCAN_TABLE)->where('file', '=', $tree_id)->where('xref', '=', $old_xref)->update(['xref' => $new_xref]);

        return $rows;
    }

    /**
     * Inline fallback for the UID index (used only when the "uid-index" cron
     * deferral is not active). target_uid is left untouched (an XREF renumber
     * never changes UIDs) - only the record's xref column moves.
     */
    private function repairUidIndexes(Tree $tree, string $old_xref, string $new_xref): int
    {
        return DB::table('le_uid_index')
            ->where('file', '=', $tree->id())
            ->where('xref', '=', $old_xref)
            ->update(['xref' => $new_xref]);
    }

    /**
     * Null-safe tree lookup by id.
     */
    private function resolveTree(int $file_id): ?Tree
    {
        return $this->tree_service->all()->first(static fn (Tree $t): bool => $t->id() === $file_id);
    }
}
