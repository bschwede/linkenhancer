<?php
/*
 * webtrees - linkenhancer (custom module)
 *
 * Copyright (C) 2025 Bernd Schwendinger
 *
 * webtrees: online genealogy application
 * Copyright (C) 2025 webtrees development team.
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
 * Upgrade the database schema from version 0 (empty database) to version 1.
 * 
 * Known issue: with MySQL an exception is thrown - "PDO error - There is no active transaction"
 * it happens only once, if the table needs to be created. This operation finishs successfully.
 * see also: "[PHP8] PdoException with Transactions and MySQL implicit commits #3856" https://github.com/fisharebest/webtrees/issues/3856
 */
class Migration0 implements MigrationInterface
{

    public function upgrade(): void
    {

        if (!DB::schema()->hasTable('route_help_map')) {
            DB::schema()->create('route_help_map', function (Blueprint $table): void {
                $table->integer('id', true);
                $table->string('path', 100)->default('');
                $table->string('handler', 150)->default('');
                $table->string('method', 20)->default('');
                $table->string('extras', 60)->default('');
                $table->string('category', 60)->default('');
                $table->integer('order', false)->default(10);
                $table->string('url', 250)->default('');
                $table->index(['path', 'handler']);
                $table->timestamp('updated_at', 0)->nullable();
            });
        }
    }

}
