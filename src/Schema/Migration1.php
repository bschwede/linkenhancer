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
 * Upgrade the database schema from version 1 to version 2.
 * 
 * Known issue: with MySQL an exception is thrown - "PDO error - There is no active transaction"
 * it happens only once, if the table needs to be created. This operation finishs successfully.
 * see also: "[PHP8] PdoException with Transactions and MySQL implicit commits #3856" https://github.com/fisharebest/webtrees/issues/3856
 */
class Migration1 implements MigrationInterface
{

    public function upgrade(): void
    {

        $tablename = 'route_help_map';

        // add 'context' column
        if (!DB::schema()->hasColumn($tablename, 'subcontext')) {
            DB::schema()->table($tablename, static function (Blueprint $table): void {
                $table->string('subcontext', 250)->default('')->after('extras');
            });
        }
    }
}
