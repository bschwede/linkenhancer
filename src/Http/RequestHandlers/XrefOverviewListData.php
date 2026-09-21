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
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Module\ModuleListInterface;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\DatatablesService;
use Fisharebest\Webtrees\Services\ModuleService;
use Fisharebest\Webtrees\Services\TimeoutService;
use Fisharebest\Webtrees\Services\TreeService;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\Validator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Schwendinger\Webtrees\Helpers\ClassName;
use Schwendinger\Webtrees\Module\LinkEnhancer\LinkEnhancerModule;
use Schwendinger\Webtrees\Module\LinkEnhancer\Services\XrefOverviewColumns;
use Schwendinger\Webtrees\Module\LinkEnhancer\Services\XrefsService;

use function property_exists;
use function response;
use function trim;

/**
 * Server-side DataTables data endpoint for the per-tree non-admin
 * cross-reference overview.
 *
 * Privacy model:
 *  - GEDCOM records: tokens are extracted from the user's privatized view
 *    (privatizeGedcom). Invisible records are skipped entirely.
 *  - Blocks: filtered by user_id (personal blocks only for owner).
 *  - Target links: webtrees core handles visibility on the target page.
 *
 * The result set is capped by the module setting PREF_LINKSPP_OVERVIEW_MAX_ROWS.
 */
final class XrefOverviewListData implements RequestHandlerInterface
{
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

        $params        = Validator::queryParams($request);
        $xref          = trim((string) $params->string('xref', ''));
        $rectype       = (string) $params->string('rectype', '');
        $tree_id       = (int) $params->integer('tree', 0);
        $max_links     = XrefsService::normalizeLinksPerClass((int) $params->integer('max_links', XrefsService::LINKS_PER_CLASS_DEFAULT));
        $live          = $params->boolean('live', false);
        $only_problems = $params->string('target', '') === 'problems';
        $max_rows      = max(1, (int) $params->integer('max_rows', 10000));
        $this->uid_active = $params->boolean('uid_active', false);

        $tree = null;
        if ($tree_id > 0) {
            try {
                $tree = $this->tree_service->find($tree_id);
            } catch (\DomainException) {
                // unknown tree
            }
        }
        $context_tree = $tree ?? $this->tree_service->all()->first();
        $user_id      = Auth::id();

        $module = Registry::container()->get(ModuleService::class)->findByName(LinkEnhancerModule::MODULE_NAME);
        $denied = ClassName::get(ClassName::EXCEPTION_HTTP_FORBIDDEN);
        if ($module === null
            || $module->accessLevel($context_tree, ModuleListInterface::class) < Auth::accessLevel($context_tree)) {
            throw new $denied();
        }

        $sources = XrefsService::rectypeSources($rectype);
        $index_fresh = XrefsService::indexStatus()['fresh'] && !$live;

        $query = null;
        if ($sources['gedcom']) {
            $query = $index_fresh
                ? XrefsService::getIndexQuery($tree, $xref !== '' ? $xref : null, $sources['rectypes'], false)
                : XrefsService::getRecordsQuery($tree, $xref !== '' ? $xref : null, $sources['rectypes'], false);
        }
        if ($sources['blocks']) {
            $block_query = XrefsService::getBlockQuery($tree, $sources['rectypes'], $index_fresh, $user_id, $xref);
            if ($block_query !== null) {
                $query = $query === null ? $block_query : $query->unionAll($block_query);
            }
        }

        if ($query === null) {
            return $this->emptyResponse($request);
        }

        $rows = $query->limit($max_rows)->get();

        $columns = new XrefOverviewColumns($this->tree_service, $this->uid_active);

        $collection = [];
        foreach ($rows as $row) {
            $block_id = property_exists($row, 'block_id') ? (int) $row->block_id : 0;

            if ($block_id > 0) {
                if ($only_problems && !$columns->rowHasProblem($row, false)) {
                    continue;
                }
                $cols = $columns->blockRowToColumns($row, $max_links, $xref, false, $context_tree, true, false, $xref);
            } else {
                if ($xref !== '' && !$columns->privatizedRowReferencesXref(
                    (int) $row->file, (string) $row->xref, (string) $row->type, $xref, $context_tree
                )) {
                    continue;
                }
                if ($only_problems && !$columns->privatizedRowHasProblem(
                    (int) $row->file, (string) $row->xref, (string) $row->type, $context_tree
                )) {
                    continue;
                }
                $cols = $columns->privatizedRowToColumns(
                    (int) $row->file,
                    (string) $row->xref,
                    (string) $row->type,
                    $max_links,
                    $xref,
                    $context_tree
                );
            }

            if ($cols === null) {
                continue;
            }

            $collection[] = [
                'xref' => (string) $row->xref,
                'type' => (string) $row->type,
                'cols' => $cols,
            ];
        }

        return $this->datatables_service->handleCollection(
            $request,
            collect($collection),
            ['xref', 'type'],
            [0 => 'xref', 1 => 'type'],
            fn (array $row): array => $row['cols']
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
