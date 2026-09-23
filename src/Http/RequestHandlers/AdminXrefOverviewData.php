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

namespace Schwendinger\Webtrees\Module\LinkEnhancer\Http\RequestHandlers;

use DomainException;
use Fisharebest\Webtrees\Auth;
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
use Schwendinger\Webtrees\Helpers\MoreI18N;
use Schwendinger\Webtrees\Module\LinkEnhancer\Services\XrefOverviewColumns;
use Schwendinger\Webtrees\Module\LinkEnhancer\Services\XrefsService;

use function response;
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
 * otherwise the live regex scan. The column / target-resolution layer is
 * shared with the per-tree non-admin endpoint (XrefOverviewColumns).
 */
final class AdminXrefOverviewData implements RequestHandlerInterface
{
    private DatatablesService $datatables_service;

    private TimeoutService $timeout_service;

    private TreeService $tree_service;

    /** D3: the UID feature pref, forwarded by the page (module is the source of truth). */
    private bool $uid_active = false;

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
            $class = ClassName::get(ClassName::EXCEPTION_HTTP_FORBIDDEN);
            throw new $class(MoreI18N::xlate('Admin only action')); // in ModuleAction without translation
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
        // D3: the page forwards the module's PREF_UID_ACTIVE; default off so a
        // directly-called endpoint (no param) never enables the feature.
        $this->uid_active = $params->boolean('uid_active', false);

        $columns = new XrefOverviewColumns($this->tree_service, $this->uid_active);

        // Phase 2: prefer the link index when it is present and fresh, unless
        // a live scan is explicitly forced. The "referencing XREF" filter
        // narrows the GEDCOM result set at the SQL level in both modes.
        // Blocks are not filtered by XREF (handleQuery architecture); use
        // the rectype filter to exclude them.
        $index_fresh = XrefsService::indexStatus()['fresh'] && !$live;

        $query = null;
        if ($sources['gedcom']) {
            $query = $index_fresh
                ? XrefsService::getIndexQuery($tree, $xref !== '' ? $xref : null, $sources['rectypes'], false)
                : XrefsService::getRecordsQuery($tree, $xref !== '' ? $xref : null, $sources['rectypes'], false);
        }
        if ($sources['blocks']) {
            $block_query = XrefsService::getBlockQuery($tree, $sources['rectypes'], $index_fresh, null, $xref);
            if ($block_query !== null) {
                $query = $query === null ? $block_query : $query->unionAll($block_query);
            }
        }

        if ($only_problems) {
            return $this->problemsResponse($request, $query, $index_fresh, $xref, $max_links, $context_tree, $columns);
        }

        if ($index_fresh) {
            return $this->datatables_service->handleQuery(
                $request,
                $query,
                ['xref', 'type'],
                [0 => 'xref', 1 => 'type'],
                fn (object $row): array => $columns->dispatchRow($row, $max_links, $xref, true, $context_tree)
            );
        }

        return $this->datatables_service->handleQuery(
            $request,
            $query,
            ['xref', 'type'],
            [0 => 'xref', 1 => 'type'],
            fn (object $row): array => $columns->dispatchRow($row, $max_links, '', false, $context_tree)
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
    private function problemsResponse(ServerRequestInterface $request, Builder $query, bool $index_fresh, string $xref, int $max_links, ?Tree $context_tree, XrefOverviewColumns $columns): ResponseInterface
    {
        $context_tree = $context_tree ?? $this->tree_service->all()->first();
        if ($index_fresh) {
            $rows = $query->get()
                ->filter(fn (object $row): bool => $columns->rowHasProblem($row, true))
                ->map(static fn (object $row): array => (array) $row);

            return $this->datatables_service->handleCollection(
                $request,
                $rows,
                ['xref', 'type'],
                [0 => 'xref', 1 => 'type'],
                fn (array $row): array => $columns->dispatchRow((object) $row, $max_links, $xref, true, $context_tree)
            );
        }

        $rows = $query->get()
            ->filter(fn (object $row): bool => $columns->rowHasProblem($row, false))
            ->map(static fn (object $row): array => (array) $row);

        return $this->datatables_service->handleCollection(
            $request,
            $rows,
            ['xref', 'type'],
            [0 => 'xref', 1 => 'type'],
            fn (array $row): array => $columns->dispatchRow((object) $row, $max_links, '', false, $context_tree)
        );
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
