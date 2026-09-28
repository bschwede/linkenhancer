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

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Http\ViewResponseTrait;
use Fisharebest\Webtrees\Services\AdminService;
use Fisharebest\Webtrees\Validator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Schwendinger\Webtrees\Helpers\ClassName;
use Schwendinger\Webtrees\Helpers\MoreI18N;
use Schwendinger\Webtrees\Module\LinkEnhancer\Services\IndexRebuildScheduler;

use function e;

/**
 * Admin page: preview the cross-tree XREF collisions of a tree and offer to
 * renumber them (keeping le-links and the le_* index consistent).
 */
final class RenumberWithLinksPage implements RequestHandlerInterface
{
    use ViewResponseTrait;

    public function __construct(
        private readonly AdminService $admin_service,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (!Auth::isAdmin()) {
            $class = ClassName::get(ClassName::EXCEPTION_HTTP_FORBIDDEN);
            throw new $class(MoreI18N::xlate('Admin only action'));
        }

        $this->layout = 'layouts/administration';

        $tree  = Validator::attributes($request)->tree();
        $xrefs = $this->admin_service->duplicateXrefs($tree);

        $title = MoreI18N::xlate('Renumber XREFs (with links)') . ' — ' . e($tree->title());
        $plan  = IndexRebuildScheduler::deferPlan();

        return $this->viewResponse('_linkenhancer_::renumber-with-links', [
            'title'       => $title,
            'tree'        => $tree,
            'xrefs'       => $xrefs,
            'defer_index' => $plan['link'] || $plan['uid'],
        ]);
    }
}
