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

namespace Schwendinger\Webtrees\Module\LinkEnhancer\Http\RequestHandlers;

use DomainException;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\GedcomRecord;
use Fisharebest\Webtrees\Http\Exceptions\HttpAccessDeniedException;
use Fisharebest\Webtrees\I18N;
use Schwendinger\Webtrees\Helpers\MoreI18N;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\DatatablesService;
use Fisharebest\Webtrees\Services\TimeoutService;
use Fisharebest\Webtrees\Services\TreeService;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\Validator;
use Illuminate\Database\Query\Builder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Schwendinger\Webtrees\Helpers\ClassName;
use Schwendinger\Webtrees\Module\LinkEnhancer\Services\TextTagCollector;
use Schwendinger\Webtrees\Module\LinkEnhancer\Services\XrefsService;

use function array_key_exists;
use function e;
use function route;
use function trim;

/**
 * Server-side DataTables data endpoint for the XREF overview admin page.
 *
 * Optional query parameters (all filters optional, U2: no forced tree):
 *   xref    only records referencing this XREF
 *   rectype one of XrefsService::supportedGedcomRecordKeys()
 *   tree    only this tree (gedcom id)
 *
 * Data source: the link index (Phase 2) when present and fresh,
 * otherwise the live regex scan.
 */
final class AdminXrefOverviewData implements RequestHandlerInterface
{
    private DatatablesService $datatables_service;

    private TimeoutService $timeout_service;

    private TreeService $tree_service;

    /** Per-request cache for referenced-record lookups: "tree_id\0xref" => label|null. */
    private array $target_cache = [];

    public function __construct(
        DatatablesService $datatables_service,
        TimeoutService $timeout_service,
        TreeService $tree_service
    ) {
        $this->datatables_service = $datatables_service;
        $this->timeout_service    = $timeout_service;
        $this->tree_service       = $tree_service;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (!Auth::isAdmin()) {
            throw new HttpAccessDeniedException(MoreI18N::xlate('Admin only.'));
        }

        // D1: do not stack a heavy scan onto a request that is already
        // close to its execution-time budget.
        if ($this->timeout_service->isTimeNearlyUp()) {
            return $this->emptyResponse($request);
        }

        $params    = Validator::queryParams($request);
        $xref      = trim((string) $params->string('xref', ''));
        $rectype   = (string) $params->string('rectype', '');
        $tree_id   = (int) $params->integer('tree', 0);
        // The endpoint is directly callable - normalize against the
        // allowlist here, not only on the page (max_links=999999 must not
        // dump an uncapped token list per row).
        $max_links = XrefsService::normalizeLinksPerClass((int) $params->integer('max_links', XrefsService::LINKS_PER_CLASS_DEFAULT));

        $tree = null;
        if ($tree_id > 0) {
            try {
                $tree = $this->tree_service->find($tree_id);
            } catch (DomainException) {
                // unknown tree - treat as "all trees"
            }
        }
        $context_tree = $tree ?? $this->tree_service->all()->first();

        // Which data sources back the record-type filter, and with which type
        // list (sentinels select a whole category - see rectypeSources()).
        $sources = XrefsService::rectypeSources($rectype);
        // live=1 = "force live scan" checkbox: a deliberate index bypass for
        // comparison/debugging, only offered while a fresh index exists.
        $live = $params->boolean('live', false);
        // target=problems = "only broken targets": keep only rows that carry a
        // missing or type-mismatch target. That state is derived by resolving
        // every target (not a plain column), so it runs as a PHP-side
        // collection filter with correct count/pagination, not a SQL LIKE.
        $only_problems = $params->string('target', '') === 'problems';

        // Phase 2: prefer the link index when it is present and fresh, unless
        // a live scan is explicitly forced. In live mode the "referencing
        // XREF" filter is index-only and would be a coarse gate, so it is not
        // applied there.
        $index_fresh = XrefsService::indexStatus()['fresh'] && !$live;

        $query = null;
        if ($sources['gedcom']) {
            $query = $index_fresh
                ? XrefsService::getIndexQuery($tree, $xref !== '' ? $xref : null, $sources['rectypes'], false)
                : XrefsService::getRecordsQuery($tree, null, $sources['rectypes'], false);
        }
        if ($sources['blocks']) {
            $block_query = XrefsService::getBlockQuery($tree, $sources['rectypes'], $index_fresh);
            if ($block_query !== null) {
                $query = $query === null ? $block_query : $query->unionAll($block_query);
            }
        }

        if ($only_problems) {
            return $this->problemsResponse($request, $query, $index_fresh, $xref, $max_links, $context_tree);
        }

        if ($index_fresh) {
            return $this->datatables_service->handleQuery(
                $request,
                $query,
                ['xref', 'type'],
                [0 => 'xref', 1 => 'type'],
                fn (object $row): array => $this->dispatchRow($row, $max_links, $xref, true, $context_tree)
            );
        }

        return $this->datatables_service->handleQuery(
            $request,
            $query,
            ['xref', 'type'],
            [0 => 'xref', 1 => 'type'],
            fn (object $row): array => $this->dispatchRow($row, $max_links, '', false, $context_tree)
        );
    }

    /**
     * "Only broken targets": load the coarse-filtered rows, keep only those
     * with a missing/mismatch target, then let handleCollection() count, sort
     * and paginate the filtered set. Target resolution is memoised per request
     * (resolveTarget cache), so the column build below re-resolves cheaply.
     *
     * handleCollection() sorts/filters through closures typed (array $row), so
     * the object rows from the query are cast to arrays before it runs; the
     * column builders still expect objects, so the callback casts back. The
     * rows only carry scalar properties (file/xref/type[/gedcom]), so the
     * (array)/(object) round-trip is lossless.
     */
    private function problemsResponse(ServerRequestInterface $request, Builder $query, bool $index_fresh, string $xref, int $max_links, ?Tree $context_tree = null): ResponseInterface
    {
        $context_tree = $context_tree ?? $this->tree_service->all()->first();
        if ($index_fresh) {
            $rows = $query->get()
                ->filter(fn (object $row): bool => $this->indexRowHasProblem($row))
                ->map(static fn (object $row): array => (array) $row);

            return $this->datatables_service->handleCollection(
                $request,
                $rows,
                ['xref', 'type'],
                [0 => 'xref', 1 => 'type'],
                fn (array $row): array => $this->dispatchRow((object) $row, $max_links, $xref, true, $context_tree)
            );
        }

        $rows = $query->get()
            ->filter(fn (object $row): bool => $this->liveRowHasProblem($row))
            ->map(static fn (object $row): array => (array) $row);

        return $this->datatables_service->handleCollection(
            $request,
            $rows,
            ['xref', 'type'],
            [0 => 'xref', 1 => 'type'],
            fn (array $row): array => $this->dispatchRow((object) $row, $max_links, '', false, $context_tree)
        );
    }

    /**
     * True when this index row references at least one missing/mismatch target.
     */
    private function indexRowHasProblem(object $row): bool
    {
        $block_id = property_exists($row, 'block_id') ? (int) $row->block_id : 0;
        if ($block_id > 0) {
            return $this->blockRowHasProblem($row);
        }

        $tree = $this->findTree((int) $row->file);

        return XrefsService::inventoryHasProblem($this->indexInventory($row, $tree)['entries'], $this->makeTargetLinker($tree, (int) $row->file));
    }

    /**
     * True when this live-scan row references at least one missing/mismatch target.
     */
    private function liveRowHasProblem(object $row): bool
    {
        $block_id = property_exists($row, 'block_id') ? (int) $row->block_id : 0;
        if ($block_id > 0) {
            return $this->blockRowHasProblem($row);
        }

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
    private function liveRowToColumns(object $row, int $max_links, bool $highlight_problems = false): array
    {
        $gedcom = (string) $row->gedcom;
        $tree   = $this->findTree((int) $row->file);

        $record = null;
        if ($tree !== null) {
            $record = Registry::gedcomRecordFactory()->make((string) $row->xref, $tree, $gedcom);
        }

        $inventory = XrefsService::classifyGedcomText($gedcom, TextTagCollector::DEFAULT_TAGS, (string) $row->type);

        return $this->inventoryColumns($row, $tree, $record, $inventory, $max_links, '', $highlight_problems);
    }

    private function dispatchRow(object $row, int $max_links, string $highlight_xref, bool $index_fresh, ?Tree $context_tree = null): array
    {
        $block_id = property_exists($row, 'block_id') ? (int) $row->block_id : 0;
        if ($block_id > 0) {
            return $this->blockRowToColumns($row, $max_links, $highlight_xref, false, $context_tree);
        }
        if ($index_fresh) {
            return $this->indexRowToColumns($row, $max_links, $highlight_xref);
        }

        return $this->liveRowToColumns($row, $max_links);
    }

    /**
     * Block row: fetch settings (PK point lookup), classify "text" settings
     * for LE links, build the same 4-column output as GEDCOM rows.
     */
    private function blockRowToColumns(object $row, int $max_links, string $highlight_xref = '', bool $highlight_problems = false, ?Tree $context_tree = null): array
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

        $xref_label = (string) $row->xref;
        $edit_url   = $this->blockEditUrl($tree, $module_name, $block_id, $user_id, $context_tree);
        $xref_html  = ($edit_url ? '<a href="' . e($edit_url) . '">' . e($xref_label) . '</a>' : e($xref_label))
            . '<br><small class="text-muted">'
            . e($tree !== null ? $tree->name() : MoreI18N::xlate('Global'))
            . '</small>';

        $type_html = e(MoreI18N::xlate($block_def['title']));

        $display_title = $title_setting !== '' ? $title_setting : $xref_label;
        $name_html = '<strong>' .  ($edit_url ? '<a href="' . e($edit_url) . '">' . e($display_title) . '</a>' : e($display_title) ) . '</strong>';
        $name_html .= XrefsService::linkInventoryHtml(
            $entries, $max_links, $highlight_xref,
            $this->makeTargetLinker($tree, $file), $highlight_problems
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
    private function indexRowToColumns(object $row, int $max_links, string $highlight_xref = '', bool $highlight_problems = false): array
    {
        $tree = $this->findTree((int) $row->file);

        $record = null;
        if ($tree !== null) {
            $record = Registry::gedcomRecordFactory()->make((string) $row->xref, $tree);
        }

        return $this->inventoryColumns($row, $tree, $record, $this->indexInventory($row, $tree), $max_links, $highlight_xref, $highlight_problems);
    }

    /**
     * @param object $row        row with xref, file, type
     * @param array{entries: array<int, array{path: string, class: string, token: string, snippet: string}>, counts: array<string, int>} $inventory
     * @param string $highlight_xref active "referencing XREF" filter (index path only; empty = none)
     */
    private function inventoryColumns(object $row, ?Tree $tree, ?GedcomRecord $record, array $inventory, int $max_links, string $highlight_xref = '', bool $highlight_problems = false): array
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
        $name_html .= XrefsService::linkInventoryHtml($inventory['entries'], $max_links, $highlight_xref, $this->makeTargetLinker($tree, $file), $highlight_problems);

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
     * Resolve a referenced record (the target of an xref/classic/pic link) to
     * a display label + URL. The target tree is the explicit @tree (a tree
     * NAME carried by the link) when present, else the source record's own
     * tree. Also returns the record's actual GEDCOM tag ("actual") so the
     * caller can check a declared wt= type / Media expectation. Cached per
     * request, keyed by resolved tree id + xref (so a same-tree target from
     * different source rows does not collide).
     *
     * @return array{name: string, url: string, tree_label: string, actual: string}|null
     */
    private function resolveTarget(string $xref, ?string $target_tree_name, ?Tree $source_tree, int $source_tree_id): ?array
    {
        $tree = ($target_tree_name !== null && $target_tree_name !== '')
            ? $this->tree_service->all()->get($target_tree_name)
            : $source_tree;
        if (!$tree instanceof Tree) {
            return null;
        }

        $tree_id   = (int) $tree->id();
        $cache_key = $tree_id . "\0" . $xref;
        if (array_key_exists($cache_key, $this->target_cache)) {
            return $this->target_cache[$cache_key];
        }

        $record = Registry::gedcomRecordFactory()->make($xref, $tree);
        if (!$record instanceof GedcomRecord) {
            $this->target_cache[$cache_key] = null;
            return null;
        }

        $label = [
            'name'       => $record->fullName(),
            'url'        => $record->url(),
            'tree_label' => ($tree_id !== $source_tree_id) ? $tree->name() : '',
            'actual'     => $record->tag(),
        ];
        $this->target_cache[$cache_key] = $label;

        return $label;
    }

    private function emptyResponse(ServerRequestInterface $request): ResponseInterface
    {
        $draw = (int) Validator::queryParams($request)->integer('draw', 0);

        return response([
            'draw'            => $draw,
            'recordsTotal'    => 0,
            'recordsFiltered' => 0,
            'data'            => [],
            'leTimeoutGuard'  => true,
        ]);
    }
}
