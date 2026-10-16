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

use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Schema\MigrationInterface;

/**
 * Upgrade the database schema from version 7 to version 8.
 */
class Migration7 implements MigrationInterface
{

    public function upgrade(): void
    {
        // (1) after fixing MarkdownEditorActivationService::isEditPage and getDefaultRules
        // - default handler strings are now handler_keys -
        // we need to delete persisted MDE_RULES once
        $table = 'module_setting';
        if (DB::schema()->hasTable($table)) {
            DB::table($table)
                ->where('module_name', '=', '_linkenhancer_')
                ->where('setting_name', '=', 'MDE_RULES')
                ->delete();
        }
    }
}
