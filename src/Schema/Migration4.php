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
use Schwendinger\Webtrees\Helpers\Functions;

/**
 * Upgrade the database schema from version 4 to version 5.
 *
 * Adds the version-neutral `handler_key` column to route_help_map and
 * backfills it from the existing `handler` column via
 * Functions::canonicalHandlerKey(). This lets the context-help query match
 * across the 2.2.6 (RequestHandlers\ + Page/Action suffix) and 2.3
 * (Controllers\, suffix dropped) handler naming with a single table.
 */
class Migration4 implements MigrationInterface
{

    public function upgrade(): void
    {

        $table = 'route_help_map';

        if (DB::schema()->hasTable($table) && !DB::schema()->hasColumn($table, 'handler_key')) {
            DB::schema()->table($table, static function (Blueprint $t): void {
                $t->string('handler_key', 150)->default('')->after('handler');
                $t->index('handler_key');
            });

            // Backfill: handler_key from the existing handler column (idempotent).
            foreach (DB::table($table)->pluck('handler', 'id') as $id => $handler) {
                DB::table($table)->where('id', $id)
                    ->update(['handler_key' => Functions::canonicalHandlerKey((string) $handler)]);
            }
        }
    }
}
