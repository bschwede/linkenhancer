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
use Fisharebest\Webtrees\Schema\MigrationInterface;

use function in_array;

/**
 * Upgrade the database schema from version 6 to version 7.
 *
 * (1) Renames route_help_map → le_route_help_map (module-prefixed naming
 *     convention, consistent with all other le_* tables).
 *
 * (2) Removes stale rectype='MEDIA' rows from the index tables. The code
 *     uses 'OBJE' (the actual GEDCOM tag) since a recent change; these
 *     leftover rows are data corpses that would confuse queries filtering
 *     by rectype.
 *
 * Idempotent: the rename is guarded by hasTable checks on both names;
 * the DELETEs are no-ops when no matching rows exist.
 */
class Migration6 implements MigrationInterface
{

    public function upgrade(): void
    {
        // (1) Table rename
        if (DB::schema()->hasTable('route_help_map') && !DB::schema()->hasTable('le_route_help_map')) {
            $prefix = DB::prefix();
            $old    = $prefix . 'route_help_map';
            $new    = $prefix . 'le_route_help_map';
            $driver = DB::connection()->getDriverName();

            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                DB::statement("RENAME TABLE {$old} TO {$new}");
            } else {
                DB::statement("ALTER TABLE {$old} RENAME TO {$new}");
            }
        }

        // (2) Remove stale rectype='MEDIA' rows (code uses 'OBJE' now)
        foreach (['le_link_index', 'le_record_scan', 'le_uid_index'] as $tbl) {
            if (DB::schema()->hasTable($tbl)) {
                DB::table($tbl)->where('rectype', '=', 'MEDIA')->delete();
            }
        }
    }
}
