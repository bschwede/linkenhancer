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
use Fisharebest\Webtrees\GedcomRecord;
use Fisharebest\Webtrees\Http\Exceptions\HttpAccessDeniedException;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\DatatablesService;
use Fisharebest\Webtrees\Services\TimeoutService;
use Fisharebest\Webtrees\Services\TreeService;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\Validator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Schwendinger\Webtrees\Module\LinkEnhancer\Services\TextTagCollector;
use Schwendinger\Webtrees\Module\LinkEnhancer\Services\XrefsService;

use function e;
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
            throw new HttpAccessDeniedException(/*I18N: webtrees.pot*/ I18N::translate('Admin only.'));
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

        $rectypes = $rectype !== '' ? [$rectype] : [];

        // Phase 2: prefer the link index when it is present and fresh.
        if (XrefsService::indexStatus()['fresh']) {
            // Qualified column names: the plain names exist in both joined
            // tables and would be ambiguous in search/sort.
            return $this->datatables_service->handleQuery(
                $request,
                XrefsService::getIndexQuery($tree, $xref !== '' ? $xref : null, $rectypes, false),
                ['s.xref', 's.rectype'],
                [0 => 's.xref', 1 => 's.rectype'],
                fn (object $row): array => $this->indexRowToColumns($row, $max_links)
            );
        }

        // Live scan (Phase 1 behaviour). The query is unordered on purpose:
        // datatables applies the ordering itself. The xref filter is
        // index-only - in live mode it would be a coarse gate, not a filter.
        return $this->datatables_service->handleQuery(
            $request,
            XrefsService::getRecordsQuery($tree, null, $rectypes, false),
            ['xref', 'type'],
            [0 => 'xref', 1 => 'type'],
            fn (object $row): array => $this->liveRowToColumns($row, $max_links)
        );
    }

    /**
     * Live scan row: the record text is already part of the row (C4).
     */
    private function liveRowToColumns(object $row, int $max_links): array
    {
        $gedcom = (string) $row->gedcom;
        $tree   = $this->findTree((int) $row->file);

        $record = null;
        if ($tree !== null) {
            $record = Registry::gedcomRecordFactory()->make((string) $row->xref, $tree, $gedcom);
        }

        $inventory = XrefsService::classifyGedcomText($gedcom, TextTagCollector::DEFAULT_TAGS, (string) $row->type);

        return $this->inventoryColumns($row, $tree, $record, $inventory, $max_links);
    }

    /**
     * Index row: links come from the index, the record is fetched per row
     * (a cheap indexed point lookup) for the display name and the URL.
     */
    private function indexRowToColumns(object $row, int $max_links): array
    {
        $tree = $this->findTree((int) $row->file);

        $record = null;
        if ($tree !== null) {
            $record = Registry::gedcomRecordFactory()->make((string) $row->xref, $tree);
        }

        $links = XrefsService::indexRowLinks((int) $row->file, (string) $row->xref, (string) $row->type);
        if ($links === []) {
            // Index entry vanished - fall back to the live scan of this record.
            $inventory = $record instanceof GedcomRecord
                ? XrefsService::classifyRecordLinks($record)
                : ['entries' => [], 'counts' => XrefsService::emptyCounts()];
        } else {
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
            $inventory = ['entries' => $entries, 'counts' => $counts];
        }

        return $this->inventoryColumns($row, $tree, $record, $inventory, $max_links);
    }

    /**
     * @param object $row        row with xref, file, type
     * @param array{entries: array<int, array{path: string, class: string, token: string, snippet: string}>, counts: array<string, int>} $inventory
     */
    private function inventoryColumns(object $row, ?Tree $tree, ?GedcomRecord $record, array $inventory, int $max_links): array
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
        $name_html = $record instanceof GedcomRecord
            ? '<a href="' . e($url) . '">' . $name . '</a>' // name contains html, so no escape needed
            : e($name);
        $name_html .= XrefsService::linkInventoryHtml($inventory['entries'], $max_links);

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
