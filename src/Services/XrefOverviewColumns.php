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

use DomainException;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\GedcomRecord;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\TreeService;
use Fisharebest\Webtrees\Tree;
use Throwable;
use Schwendinger\Webtrees\Helpers\ClassName;
use Schwendinger\Webtrees\Helpers\Functions;
use Schwendinger\Webtrees\Helpers\MoreI18N;

use function array_key_exists;
use function array_map;
use function array_slice;
use function count;
use function e;
use function property_exists;
use function route;
use function strlen;

/**
 * Shared column / target-resolution layer of the XREF overviews (the admin
 * page and the per-tree non-admin list). Builds the four table columns
 * (XREF, type, record + link inventory, link count summary) from a query row
 * and resolves referenced targets (XREF/UID) to display labels + URLs, with
 * a per-request cache.
 *
 * $show_snippets = false renders the link tokens without their raw GEDCOM
 * context (privacy: the non-admin overview never shows raw record text).
 * $with_edit_urls = false suppresses the block edit links (non-admin view).
 */
final class XrefOverviewColumns
{
    private TreeService $tree_service;

    /** D3: the UID feature pref (module is the source of truth). */
    private bool $uid_active;

    /** Per-request cache for referenced-record lookups: "tree_id\0xref" => label|null. */
    private array $target_cache = [];

    /** Per-request cache for global UID ("not unique") lookups: "GLOBAL\0uid" => payload|null. */
    private array $ambiguous_cache = [];

    public function __construct(TreeService $tree_service, bool $uid_active = false)
    {
        $this->tree_service = $tree_service;
        $this->uid_active   = $uid_active;
    }

    /**
     * Build the four table columns for one row.
     *
     * @param object $row row with xref, file, type (and gedcom on live rows,
     *                    block_id/user_id on block rows)
     */
    public function dispatchRow(object $row, int $max_links, string $highlight_xref, bool $index_fresh, ?Tree $context_tree = null, bool $show_snippets = true, bool $with_edit_urls = true): array
    {
        $block_id = property_exists($row, 'block_id') ? (int) $row->block_id : 0;
        if ($block_id > 0) {
            return $this->blockRowToColumns($row, $max_links, $highlight_xref, false, $context_tree, $show_snippets, $with_edit_urls);
        }
        if ($index_fresh) {
            return $this->indexRowToColumns($row, $max_links, $highlight_xref, false, $show_snippets, $with_edit_urls);
        }

        return $this->liveRowToColumns($row, $max_links, false, $show_snippets, $with_edit_urls);
    }

    /**
     * True when this row references at least one missing/mismatch target -
     * the "only broken targets" filter predicate.
     */
    public function rowHasProblem(object $row, bool $index_fresh): bool
    {
        $block_id = property_exists($row, 'block_id') ? (int) $row->block_id : 0;
        if ($block_id > 0) {
            return $this->blockRowHasProblem($row);
        }

        return $index_fresh ? $this->indexRowHasProblem($row) : $this->liveRowHasProblem($row);
    }

    /**
     * Build the four table columns for a GEDCOM row using the user's
     * privatized view of the record. Returns null when the record is not
     * visible to the user or contains no visible links.
     *
     * Snippets are safe by construction: they come from the privatized text.
     */
    public function privatizedRowToColumns(
        int $file, string $xref, string $type,
        int $max_links, string $highlight_xref,
        ?Tree $context_tree
    ): ?array {
        $tree = $this->findTree($file);
        if ($tree === null) {
            return null;
        }

        $record = Registry::gedcomRecordFactory()->make($xref, $tree);
        if (!$record instanceof GedcomRecord) {
            return null;
        }

        $access_level = Auth::accessLevel($tree);
        $priv         = Functions::getPrivatizedGedcom($record, $access_level);
        if ($priv === '') {
            return null;
        }

        $result = XrefsService::classifyGedcomText($priv, TextTagCollector::DEFAULT_TAGS, $type);
        if ($result['entries'] === []) {
            return null;
        }

        $url = $record instanceof GedcomRecord ? $record->url() : null;

        $xref_html  = '<a href="' . e($url ?? '#') . '">' . e($xref) . '</a>'
            . '<br><small class="text-muted">'
            . e($tree->name())
            . '</small>';

        $type_html  = e($type);

        $name = $record instanceof GedcomRecord ? $record->fullName() : $xref;
        $name_html = '<strong>' . (
                $record instanceof GedcomRecord
                ? '<a href="' . e($url) . '">' . $name . '</a>' // name contains html, so no escape needed
                : e($name)
            ) . '</strong>';
        $name_html .= XrefsService::linkInventoryHtml(
            $result['entries'], $max_links, $highlight_xref,
            $this->makeTargetLinker($tree, $file), false,
            $this->uid_active ? $this->makeAmbiguousLinker($tree, $file) : null,
            true
        );

        return [
            $xref_html,
            $type_html,
            $name_html,
            XrefsService::linkCountSummary($result['counts']),
        ];
    }

    /**
     * True when the user's privatized view of this record contains at least
     * one missing or type-mismatched target. Target resolution is cached per
     * request, so this is cheap after privatizedRowToColumns() has run.
     */
    public function privatizedRowHasProblem(int $file, string $xref, string $type, ?Tree $context_tree): bool
    {
        $tree = $this->findTree($file);
        if ($tree === null) {
            return false;
        }

        $record = Registry::gedcomRecordFactory()->make($xref, $tree);
        if (!$record instanceof GedcomRecord) {
            return false;
        }

        $priv = Functions::getPrivatizedGedcom($record, Auth::accessLevel($tree));
        if ($priv === '') {
            return false;
        }

        $result = XrefsService::classifyGedcomText($priv, TextTagCollector::DEFAULT_TAGS, $type);

        return XrefsService::inventoryHasProblem($result['entries'], $this->makeTargetLinker($tree, $file));
    }

    /**
     * True when the user's privatized view of this record contains at least
     * one link token that references the given XREF.
     */
    public function privatizedRowReferencesXref(int $file, string $xref, string $type, string $filter_xref, ?Tree $context_tree): bool
    {
        $tree = $this->findTree($file);
        if ($tree === null) {
            return false;
        }

        $record = Registry::gedcomRecordFactory()->make($xref, $tree);
        if (!$record instanceof GedcomRecord) {
            return false;
        }

        $priv = Functions::getPrivatizedGedcom($record, Auth::accessLevel($tree));
        if ($priv === '') {
            return false;
        }

        $result = XrefsService::classifyGedcomText($priv, TextTagCollector::DEFAULT_TAGS, $type);
        foreach ($result['entries'] as $entry) {
            foreach (XrefsService::extractLinkTargets($entry['token']) as $target) {
                if ($target['xref'] === $filter_xref) {
                    return true;
                }
            }
        }

        return false;
    }

    private function indexRowHasProblem(object $row): bool
    {
        $tree = $this->findTree((int) $row->file);

        return XrefsService::inventoryHasProblem($this->indexInventory($row, $tree)['entries'], $this->makeTargetLinker($tree, (int) $row->file));
    }

    private function liveRowHasProblem(object $row): bool
    {
        $tree      = $this->findTree((int) $row->file);
        $inventory = XrefsService::classifyGedcomText((string) $row->gedcom, TextTagCollector::DEFAULT_TAGS, (string) $row->type);

        return XrefsService::inventoryHasProblem($inventory['entries'], $this->makeTargetLinker($tree, (int) $row->file));
    }

    private function blockRowHasProblem(object $row): bool
    {
        $module_name = (string) $row->type;
        $file        = (int) ($row->file ?? 0);
        $tree        = $file > 0 ? $this->findTree($file) : null;

        $block_def = XrefsService::BLOCKS[$module_name] ?? null;
        if ($block_def === null) {
            return false;
        }

        $settings = DB::table('block_setting')
            ->where('block_id', '=', (int) $row->block_id)
            ->pluck('setting_value', 'setting_name');

        $entries = [];
        foreach ($block_def['settings'] as $name => $class) {
            if ($class !== 'text') {
                continue;
            }
            $text = (string) ($settings[$name] ?? '');
            if ($text === '') {
                continue;
            }
            foreach (XrefsService::classifyHtmlLinks($text) as $link) {
                $entries[] = [
                    'path'    => $name,
                    'class'   => $link['class'],
                    'token'   => $link['token'],
                    'snippet' => $link['snippet'],
                ];
            }
        }

        return XrefsService::inventoryHasProblem($entries, $this->makeTargetLinker($tree, $file));
    }

    /**
     * Live scan row: the record text is already part of the row (C4).
     */
    private function liveRowToColumns(object $row, int $max_links, bool $highlight_problems = false, bool $show_snippets = true, bool $with_edit_urls = true): array
    {
        $gedcom = (string) $row->gedcom;
        $tree   = $this->findTree((int) $row->file);

        $record = null;
        if ($tree !== null) {
            $record = Registry::gedcomRecordFactory()->make((string) $row->xref, $tree, $gedcom);
        }

        $inventory = XrefsService::classifyGedcomText($gedcom, TextTagCollector::DEFAULT_TAGS, (string) $row->type);

        return $this->inventoryColumns($row, $tree, $record, $inventory, $max_links, '', $highlight_problems, $show_snippets, $with_edit_urls);
    }

    /**
     * Block row: fetch settings (PK point lookup), classify "text" settings
     * for LE links, build the same 4-column output as GEDCOM rows.
     */
    public function blockRowToColumns(object $row, int $max_links, string $highlight_xref = '', bool $highlight_problems = false, ?Tree $context_tree = null, bool $show_snippets = true, bool $with_edit_urls = true, string $filter_xref = ''): ?array
    {
        $block_id    = (int) $row->block_id;
        $module_name = (string) $row->type;
        $file        = (int) ($row->file ?? 0);
        $tree        = $file > 0 ? $this->findTree($file) : null;
        $user_id     = isset($row->user_id) ? ($row->user_id !== null ? (int) $row->user_id : null) : null;

        $block_def = XrefsService::BLOCKS[$module_name] ?? null;
        if ($block_def === null) {
            return [e($row->xref), e($module_name), '', '0'];
        }

        $settings = DB::table('block_setting')
            ->where('block_id', '=', $block_id)
            ->pluck('setting_value', 'setting_name');

        $title_setting = '';
        foreach ($block_def['settings'] as $name => $class) {
            if ($class === 'title') {
                $title_setting = (string) ($settings[$name] ?? '');
                break;
            }
        }

        $entries = [];
        $counts  = XrefsService::emptyCounts();
        foreach ($block_def['settings'] as $name => $class) {
            if ($class !== 'text') {
                continue;
            }
            $text = (string) ($settings[$name] ?? '');
            if ($text === '') {
                continue;
            }
            foreach (XrefsService::classifyHtmlLinks($text) as $link) {
                $counts[$link['class']] = ($counts[$link['class']] ?? 0) + 1;
                $entries[] = [
                    'path'    => $name,
                    'class'   => $link['class'],
                    'token'   => $link['token'],
                    'snippet' => $link['snippet'],
                ];
            }
        }

        if ($filter_xref !== '' && $entries !== []) {
            $has_ref = false;
            foreach ($entries as $entry) {
                foreach (XrefsService::extractLinkTargets($entry['token']) as $target) {
                    if ($target['xref'] === $filter_xref) {
                        $has_ref = true;
                        break 2;
                    }
                }
            }
            if (!$has_ref) {
                return null;
            }
        }

        $xref_label = (string) $row->xref;
        $edit_url   = $with_edit_urls ? $this->blockEditUrl($tree, $module_name, $block_id, $user_id, $context_tree) : '';
        $xref_html  = ($edit_url ? '<a href="' . e($edit_url) . '">' . e($xref_label) . '</a>' : e($xref_label))
            . '<br><small class="text-muted">'
            . e($tree !== null ? $tree->name() : MoreI18N::xlate('Global'))
            . '</small>';

        $type_html = e(MoreI18N::xlate($block_def['title']));

        $display_title = $title_setting !== '' ? $title_setting : $xref_label;
        $name_html = '<strong>' .  ($edit_url ? '<a href="' . e($edit_url) . '">' . e($display_title) . '</a>' : e($display_title) ) . '</strong>';
        $name_html .= XrefsService::linkInventoryHtml(
            $entries, $max_links, $highlight_xref,
            $this->makeTargetLinker($tree, $file), $highlight_problems,
            $this->uid_active ? $this->makeAmbiguousLinker($tree, $file) : null,
            $show_snippets
        );

        return [
            $xref_html,
            $type_html,
            $name_html,
            XrefsService::linkCountSummary($counts),
        ];
    }

    private function blockEditUrl(?Tree $tree, string $module_name, int $block_id, int|null $user_id, ?Tree $context_tree = null): string
    {
        $url_tree = $tree ?? $context_tree ?? $this->tree_service->all()->first();
        if ($url_tree === null) {
            return '';
        }

        if ($module_name === 'html') {
            $route = ClassName::get(ClassName::TREE_PAGE_BLOCK_EDIT);
            if ($user_id !== null) {
                if ($user_id === Auth::id()) { // also admins are not allowed to edit personal html blocks owned by other users
                    $route = ClassName::get(ClassName::USER_PAGE_BLOCK_EDIT);
                } else {
                    return '';
                }
            }

            return route($route, ['tree' => $url_tree->name(), 'block_id' => $block_id]);
        }

        $action = $module_name === '_vesta_classic_look_and_feel_' ? 'Admin2Edit' : 'AdminEdit';

        return route('module-tree', [
            'module'   => $module_name,
            'action'   => $action,
            'tree'     => $url_tree->name(),
            'block_id' => $block_id,
        ]);
    }

    /**
     * The link inventory of one index row. When the index entry has vanished
     * it falls back to a live scan of the record. Shared by the column builder
     * and the "only broken targets" filter so both see the same links.
     *
     * @return array{entries: array<int, array{path: string, class: string, token: string, snippet: string}>, counts: array<string, int>}
     */
    private function indexInventory(object $row, ?Tree $tree): array
    {
        $links = XrefsService::indexRowLinks((int) $row->file, (string) $row->xref, (string) $row->type);
        if ($links !== []) {
            $counts  = XrefsService::emptyCounts();
            $entries = [];
            foreach ($links as $link) {
                $counts[$link['class']] = ($counts[$link['class']] ?? 0) + 1;
                $entries[] = [
                    'path'    => $link['tag_path'],
                    'class'   => $link['class'],
                    'token'   => $link['token'],
                    'snippet' => $link['snippet'] ?? $link['token'],
                ];
            }

            return ['entries' => $entries, 'counts' => $counts];
        }

        // Index entry vanished - fall back to the live scan of this record.
        $record = null;
        if ($tree !== null) {
            $record = Registry::gedcomRecordFactory()->make((string) $row->xref, $tree);
        }

        return $record instanceof GedcomRecord
            ? XrefsService::classifyRecordLinks($record)
            : ['entries' => [], 'counts' => XrefsService::emptyCounts()];
    }

    /**
     * Index row: links come from the index, the record is fetched per row
     * (a cheap indexed point lookup) for the display name and the URL.
     * $highlight_xref is the active "referencing XREF" filter (empty = none);
     * it is forwarded so the inventory can mark that XREF's occurrence.
     */
    private function indexRowToColumns(object $row, int $max_links, string $highlight_xref = '', bool $highlight_problems = false, bool $show_snippets = true, bool $with_edit_urls = true): array
    {
        $tree = $this->findTree((int) $row->file);

        $record = null;
        if ($tree !== null) {
            $record = Registry::gedcomRecordFactory()->make((string) $row->xref, $tree);
        }

        return $this->inventoryColumns($row, $tree, $record, $this->indexInventory($row, $tree), $max_links, $highlight_xref, $highlight_problems, $show_snippets, $with_edit_urls);
    }

    /**
     * @param object $row        row with xref, file, type
     * @param array{entries: array<int, array{path: string, class: string, token: string, snippet: string}>, counts: array<string, int>} $inventory
     * @param string $highlight_xref active "referencing XREF" filter (index path only; empty = none)
     */
    private function inventoryColumns(object $row, ?Tree $tree, ?GedcomRecord $record, array $inventory, int $max_links, string $highlight_xref = '', bool $highlight_problems = false, bool $show_snippets = true, bool $with_edit_urls = true): array
    {
        $xref = (string) $row->xref;
        $type = (string) $row->type;
        $file = (int) $row->file;

        $url = $record instanceof GedcomRecord ? $record->url() : null;

        // The xref cell shows the tree name as a second muted line; the
        // type stays its own (sortable) column.
        $xref_html = '<a href="' . e($url ?? '#') . '">' . e($xref) . '</a>'
            . '<br><small class="text-muted">'
            . e($tree !== null ? $tree->name() : (I18N::translate('tree') . ' #' . $file))
            . '</small>';

        $name = $record instanceof GedcomRecord ? $record->fullName() : $xref;
        $name_html = '<strong>' . (
                $record instanceof GedcomRecord
                ? '<a href="' . e($url) . '">' . $name . '</a>' // name contains html, so no escape needed
                : e($name)
            ) . '</strong>';
        // Resolve referenced records (xref/classic targets) for the inventory;
        // the source tree is this row's tree, the resolver is cached per request.
        $name_html .= XrefsService::linkInventoryHtml($inventory['entries'], $max_links, $highlight_xref, $this->makeTargetLinker($tree, $file), $highlight_problems, $this->uid_active ? $this->makeAmbiguousLinker($tree, $file) : null, $show_snippets);

        return [
            $xref_html,
            e($type),
            $name_html,
            XrefsService::linkCountSummary($inventory['counts']),
        ];
    }

    private function findTree(int $file): ?Tree
    {
        try {
            return $this->tree_service->find($file);
        } catch (DomainException) {
            // Orphaned row - the tree no longer exists.
            return null;
        }
    }

    /**
     * @return callable(string, ?string): (array{name: string, url: string, tree_label: string}|null)
     */
    private function makeTargetLinker(?Tree $source_tree, int $source_tree_id): callable
    {
        return fn (string $xref, ?string $target_tree_name): ?array => $this->resolveTarget($xref, $target_tree_name, $source_tree, $source_tree_id);
    }

    /**
     * @return callable(string, ?string): array{count: int, hits: array<int, array{name: string, url: string, tree_label: string}>, goto_url: string}|null
     */
    private function makeAmbiguousLinker(?Tree $source_tree, int $source_tree_id): callable
    {
        return fn (string $id, ?string $target_tree_name): ?array => $this->resolveAmbiguousTarget($id, $target_tree_name, $source_tree, $source_tree_id);
    }

    /**
     * Resolve a referenced target (an XREF or a UID, the target of an
     * xref/classic/pic link) to a display label + URL, via the shared
     * IdResolver (length-aware XREF/UID, global fallback for tree-less
     * targets). The target tree is the explicit @tree (a tree NAME carried by
     * the link) when present, else the source record's own tree; a tree-less
     * target is resolved globally (UID only). Also returns the record's actual
     * GEDCOM tag ("actual") so the caller can check a declared wt= type / Media
     * expectation. Cached per request, keyed by resolved tree id (or GLOBAL) +
     * id (so a same-tree target from different source rows does not collide).
     *
     * @return array{name: string, url: string, tree_label: string, actual: string}|null
     */
    private function resolveTarget(string $xref, ?string $target_tree_name, ?Tree $source_tree, int $source_tree_id): ?array
    {
        $tree = ($target_tree_name !== null && $target_tree_name !== '')
            ? $this->tree_service->all()->get($target_tree_name)
            : $source_tree;

        $cache_key = (($tree instanceof Tree) ? (int) $tree->id() : 'GLOBAL') . "\0" . $xref;
        if (array_key_exists($cache_key, $this->target_cache)) {
            return $this->target_cache[$cache_key];
        }

        $candidates = IdResolver::candidates($xref, $tree, $source_tree_id);
        if ($candidates === []) {
            $this->target_cache[$cache_key] = null;
            return null;
        }

        $candidate = $candidates[0];
        $label     = [
            'name'       => $candidate['record']->fullName(),
            'url'        => $candidate['record']->url(),
            'tree_label' => $candidate['tree_label'],
            'actual'     => $candidate['record']->tag(),
        ];
        $this->target_cache[$cache_key] = $label;

        return $label;
    }

    /**
     * D1/D5: resolve a "missing" UID-length target that has NO explicit @tree
     * against the other trees (global UID lookup, visibility-filtered). Returns
     * the match payload (count + up to 3 hits + a goto-id URL) when there is at
     * least one global match, else null (the caller then keeps the "missing"
     * rendering). An explicit @tree (a precise reference) or a short XREF never
     * qualifies. Cached per request.
     *
     * @return array{count: int, hits: array<int, array{name: string, url: string, tree_label: string}>, goto_url: string}|null
     */
    private function resolveAmbiguousTarget(string $id, ?string $target_tree_name, ?Tree $source_tree, int $source_tree_id): ?array
    {
        if ($target_tree_name !== null && $target_tree_name !== '') {
            return null;
        }
        if (strlen($id) < IdResolver::UID_MIN_LENGTH) {
            return null;
        }

        $cache_key = 'GLOBAL\0' . $id;
        if (array_key_exists($cache_key, $this->ambiguous_cache)) {
            return $this->ambiguous_cache[$cache_key];
        }

        $hits = IdResolver::candidates($id, null, $source_tree_id);
        if ($hits === []) {
            $this->ambiguous_cache[$cache_key] = null;
            return null;
        }

        $mapped = array_map(
            static fn (array $hit): array => [
                'name'       => $hit['record']->fullName(),
                'url'        => $hit['record']->url(),
                'tree_label' => $hit['tree_label'],
            ],
            $hits
        );

        $goto_url = '';
        try {
            $goto_url = route('le.goto-id.global', ['id' => $id]);
        } catch (Throwable) {
            // route not registered (PREF_UID_ACTIVE off) - the list still works,
            // only the >3 goto link degrades
        }

        $payload = [
            'count'    => count($hits),
            'hits'     => array_slice($mapped, 0, 3),
            'goto_url' => $goto_url,
        ];
        $this->ambiguous_cache[$cache_key] = $payload;

        return $payload;
    }
}
