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
use Schwendinger\Webtrees\Module\LinkEnhancer\Services\XrefOverviewColumns;
use Schwendinger\Webtrees\Module\LinkEnhancer\Services\XrefsService;

use function response;
use function trim;

/**
 * Server-side DataTables data endpoint for the per-tree non-admin
 * cross-reference overview.
 *
 * Privacy rules (D1/D5):
 *  - no raw GEDCOM snippets ($show_snippets = false)
 *  - personal blocks visible only to their owner ($user_id filter)
 *  - no edit URLs ($with_edit_urls = false)
 *
 * D6: result set is capped at MAX_ROWS.
 */
final class XrefOverviewListData implements RequestHandlerInterface
{
    private const MAX_ROWS = 10000;

    private DatatablesService $datatables_service;

    private TimeoutService $timeout_service;

    private TreeService $tree_service;

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
        if ($this->timeout_service->isTimeNearlyUp()) {
            return $this->emptyResponse($request);
        }

        $params    = Validator::queryParams($request);
        $xref      = trim((string) $params->string('xref', ''));
        $rectype   = (string) $params->string('rectype', '');
        $tree_id   = (int) $params->integer('tree', 0);
        $max_links = XrefsService::normalizeLinksPerClass((int) $params->integer('max_links', XrefsService::LINKS_PER_CLASS_DEFAULT));
        $live      = $params->boolean('live', false);
        $only_problems = $params->string('target', '') === 'problems';
        $this->uid_active = $params->boolean('uid_active', false);

        $tree = null;
        if ($tree_id > 0) {
            $tree = $this->tree_service->find($tree_id);
        }
        $context_tree = $tree ?? $this->tree_service->all()->first();
        $user_id      = Auth::id();

        $sources = XrefsService::rectypeSources($rectype);
        $index_fresh = XrefsService::indexStatus()['fresh'] && !$live;

        $query = null;
        if ($sources['gedcom']) {
            $query = $index_fresh
                ? XrefsService::getIndexQuery($tree, $xref !== '' ? $xref : null, $sources['rectypes'], false)
                : XrefsService::getRecordsQuery($tree, null, $sources['rectypes'], false);
        }
        if ($sources['blocks']) {
            $block_query = XrefsService::getBlockQuery($tree, $sources['rectypes'], $index_fresh, $user_id);
            if ($block_query !== null) {
                $query = $query === null ? $block_query : $query->unionAll($block_query);
            }
        }

        if ($query === null) {
            return $this->emptyResponse($request);
        }

        $columns = new XrefOverviewColumns($this->tree_service, $this->uid_active);

        if ($only_problems) {
            return $this->problemsResponse($request, $query, $index_fresh, $xref, $max_links, $context_tree, $columns);
        }

        if ($index_fresh) {
            return $this->datatables_service->handleQuery(
                $request,
                $query->limit(self::MAX_ROWS),
                ['xref', 'type'],
                [0 => 'xref', 1 => 'type'],
                fn (object $row): array => $columns->dispatchRow($row, $max_links, $xref, true, $context_tree, false, false)
            );
        }

        return $this->datatables_service->handleQuery(
            $request,
            $query->limit(self::MAX_ROWS),
            ['xref', 'type'],
            [0 => 'xref', 1 => 'type'],
            fn (object $row): array => $columns->dispatchRow($row, $max_links, '', false, $context_tree, false, false)
        );
    }

    private function problemsResponse(ServerRequestInterface $request, Builder $query, bool $index_fresh, string $xref, int $max_links, ?Tree $context_tree, XrefOverviewColumns $columns): ResponseInterface
    {
        $context_tree = $context_tree ?? $this->tree_service->all()->first();
        $rows = $query->limit(self::MAX_ROWS)->get();

        if ($index_fresh) {
            $rows = $rows
                ->filter(fn (object $row): bool => $columns->rowHasProblem($row, true))
                ->map(static fn (object $row): array => (array) $row);

            return $this->datatables_service->handleCollection(
                $request,
                $rows,
                ['xref', 'type'],
                [0 => 'xref', 1 => 'type'],
                fn (array $row): array => $columns->dispatchRow((object) $row, $max_links, $xref, true, $context_tree, false, false)
            );
        }

        $rows = $rows
            ->filter(fn (object $row): bool => $columns->rowHasProblem($row, false))
            ->map(static fn (object $row): array => (array) $row);

        return $this->datatables_service->handleCollection(
            $request,
            $rows,
            ['xref', 'type'],
            [0 => 'xref', 1 => 'type'],
            fn (array $row): array => $columns->dispatchRow((object) $row, $max_links, '', false, $context_tree, false, false)
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
