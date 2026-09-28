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
use Fisharebest\Webtrees\FlashMessages;
use Fisharebest\Webtrees\Validator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Schwendinger\Webtrees\Helpers\ClassName;
use Schwendinger\Webtrees\Helpers\MoreI18N;
use Schwendinger\Webtrees\Module\LinkEnhancer\Services\RenumberWithLinksService;

use function redirect;
use function route;

/**
 * Admin action: perform the single-pass renumber (core + le-links + le_* index).
 */
final class RenumberWithLinksAction implements RequestHandlerInterface
{
    public function __construct(
        private readonly RenumberWithLinksService $service,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (!Auth::isAdmin()) {
            $class = ClassName::get(ClassName::EXCEPTION_HTTP_FORBIDDEN);
            throw new $class(MoreI18N::xlate('Admin only action'));
        }

        $tree = Validator::attributes($request)->tree();

        if ($tree->hasPendingEdit()) {
            FlashMessages::addMessage(
                MoreI18N::xlate('You need to accept or reject all pending changes before renumbering.'),
                'danger'
            );

            return redirect(route(RenumberWithLinksPage::class, ['tree' => $tree->name()]));
        }

        $report = $this->service->renumber($tree);

        $type = !empty($report['timed_out']) ? 'warning' : 'success';
        FlashMessages::addMessage($this->summary($report), $type);

        return redirect(route(RenumberWithLinksPage::class, ['tree' => $tree->name()]));
    }

    /**
     * @param array<string, mixed> $report
     */
    private function summary(array $report): string
    {
        if (!empty($report['timed_out'])) {
            return MoreI18N::xlate('The time limit was reached; no changes were made.');
        }

        $message = MoreI18N::xlate(
            'Renumbered %1$d conflicting record(s) and updated %2$d le-link reference(s).',
            (int) $report['count'],
            (int) $report['links']
        );

        $message .= $report['defer_index']
            ? ' ' . MoreI18N::xlate('The le_* index will be refreshed by the cron job.')
            : ' ' . MoreI18N::xlate('The le_* index was updated inline.');

        return $message;
    }
}
