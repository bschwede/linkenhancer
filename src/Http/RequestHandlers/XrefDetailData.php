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
use Fisharebest\Webtrees\Module\ModuleTabInterface;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Validator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Schwendinger\Webtrees\Helpers\ClassName;
use Schwendinger\Webtrees\Module\LinkEnhancer\LinkEnhancerModule;
use Schwendinger\Webtrees\Module\LinkEnhancer\Services\XrefDetailService;

use function response;
use function view;

/**
 * AJAX endpoint for the cross-reference detail tab on non-INDI record pages
 * (NOTE, MEDIA, SOUR, REPO, custom records) and the FAM modal.
 *
 * Route: /tree/{tree}/le-xref-detail/{xref}
 *
 * Returns HTML content (wrapped in the ajax layout) containing the outgoing
 * and incoming cross-references for the given record.
 */
final class XrefDetailData implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $tree   = Validator::attributes($request)->tree();
        $xref   = Validator::attributes($request)->isXref()->string('xref');
        $user   = Validator::attributes($request)->user();

        $module = Registry::container()->get(LinkEnhancerModule::class);
        $denied = ClassName::get(ClassName::EXCEPTION_HTTP_FORBIDDEN);
        if (!$module instanceof LinkEnhancerModule
            || $module->accessLevel($tree, ModuleTabInterface::class) < Auth::accessLevel($tree, $user)) {
            throw new $denied();
        }

        $record = Registry::gedcomRecordFactory()->make($xref, $tree);
        if ($record === null || !$record->canShow()) {
            throw new $denied();
        }

        $service       = new XrefDetailService();
        $outgoing_html = $service->outgoingLinksHtml($record, 0);
        $incoming      = $service->incomingReferences($tree, $xref);
        $total_count   = count($service->outgoingLinks($record)['entries'])
            + array_sum(array_column($incoming, 'link_count'));

        $content = view($module->name() . '::xref-detail-tab', [
            'record'        => $record,
            'outgoing_html' => $outgoing_html,
            'incoming'      => $incoming,
            'total_count'   => $total_count,
        ]);

        if (Validator::queryParams($request)->boolean('modal', false)) {
            return response(view('modals/help', [
                'title' => $module->tabTitle(),
                'text'  => $content,
            ]));
        }

        return response(view('layouts/ajax', [
            'content' => $content,
        ]));
    }
}
