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

namespace Schwendinger\Webtrees\Module\LinkEnhancer\DataFix;

use Fisharebest\Webtrees\GedcomRecord;
use Fisharebest\Webtrees\Tree;
use Illuminate\Support\Collection;
use Schwendinger\Webtrees\Module\LinkEnhancer\DataFix\Handlers\XrefUidSwapFix;

use function array_keys;
use function view;

class DataFixDispatcher
{
    private const DEFAULT_FIX = 'xref_uid_swap';

    /** @var array<string, FixHandlerInterface> */
    private array $handlers;

    public function __construct()
    {
        $this->handlers = [
            XrefUidSwapFix::ID => new XrefUidSwapFix(),
        ];
    }

    /** @return array<string, FixHandlerInterface> */
    public function handlers(): array
    {
        return $this->handlers;
    }

    private function resolve(array $params): FixHandlerInterface
    {
        $id = (string) ($params['fix_type'] ?? self::DEFAULT_FIX);
        return $this->handlers[$id] ?? $this->handlers[self::DEFAULT_FIX];
    }

    public function optionsHtml(Tree $tree, array $params): string
    {
        return view('_linkenhancer_::datafix-options', [
            'tree'     => $tree,
            'params'   => $params,
            'handlers' => $this->handlers,
            'active'   => $this->resolve($params),
        ]);
    }

    public function recordsToFix(Tree $tree, array $params): Collection
    {
        return $this->resolve($params)->recordsToFix($tree, $params);
    }

    public function needsUpdate(GedcomRecord $record, array $params): bool
    {
        return $this->resolve($params)->needsUpdate($record, $params);
    }

    public function preview(GedcomRecord $record, array $params): string
    {
        return $this->resolve($params)->preview($record, $params);
    }

    public function apply(GedcomRecord $record, array $params): void
    {
        $this->resolve($params)->apply($record, $params);
    }
}
