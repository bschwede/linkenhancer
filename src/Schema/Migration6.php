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

namespace Schwendinger\Webtrees\Module\LinkEnhancer\Schema;

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Schema\Blueprint;
use Fisharebest\Webtrees\Schema\MigrationInterface;

/**
 * Upgrade the database schema from version 6 to version 7.
 *
 * Adds le_link_index.target_uid (see
 * .opencode/plans/linkenhancer-uid-link-target-cross-tree-goto.md): a separate
 * column for UID link targets. The builder (cli/build-link-index.php) fills it
 * via a length heuristic - short XREFs stay in target_xref, UID-length values
 * (>= 18 chars) go here. Kept separate from target_xref so the planned
 * Backlink feature can answer "which records link to UID X?" with a clean,
 * indexed query, while target_xref keeps its XREF-only semantics.
 *
 * Additive and idempotent. Existing rows keep target_uid NULL until the next
 * index build re-writes them.
 */
class Migration6 implements MigrationInterface
{

    public function upgrade(): void
    {
        $link_table = 'le_link_index';

        if (DB::schema()->hasTable($link_table) && !DB::schema()->hasColumn($link_table, 'target_uid')) {
            DB::schema()->table($link_table, static function (Blueprint $table): void {
                $table->string('target_uid', 255)->nullable()->after('target_tree');
                $table->index('target_uid');
            });
        }
    }
}
