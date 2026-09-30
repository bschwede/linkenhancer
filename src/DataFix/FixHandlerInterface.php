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

interface FixHandlerInterface
{ // extended ModuleDataFixInterface for usage with multiple data fixes in one custom module
    public function id(): string;

    public function label(): string;

    /**
     * Options form.
     * => ModuleDataFixInterface::fixOptions() plus $params
     *
     * @param Tree $tree
     * @param array<string,string> $params
     * 
     * @return string
     */
    public function optionsHtml(Tree $tree, array $params): string;

    /**
     * A combined list of all records that might need fixing.
     * => ModuleDataFixInterface::recordsToFix()
     *
     * @param Tree                 $tree
     * @param array<string,string> $params
     *
     * @return Collection<int,object{xref:string,type:string}>
     */
    public function recordsToFix(Tree $tree, array $params): Collection;

    /**
     * Does a record need updating?
     * => ModuleDataFixInterface::doesRecordNeedUpdate()
     *
     * @param GedcomRecord         $record
     * @param array<string,string> $params
     *
     * @return bool
     */
    public function needsUpdate(GedcomRecord $record, array $params): bool;

    /**
     * Show the changes we would make
     * => ModuleDataFixInterface::previewUpdate()
     *
     * @param GedcomRecord         $record
     * @param array<string,string> $params
     *
     * @return string
     */
    public function preview(GedcomRecord $record, array $params): string;

    /**
     * Fix a record
     * => ModuleDataFixInterface::updateRecord()
     *
     * @param GedcomRecord         $record
     * @param array<string,string> $params
     *
     * @return void
     */
    public function apply(GedcomRecord $record, array $params): void;
}
