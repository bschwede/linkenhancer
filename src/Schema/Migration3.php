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
 * Upgrade the database schema from version 3 to version 4.
 *
 * Creates the link index tables (P1 Phase 2):
 *  - le_record_scan: one row per scanned record, with an MD5 fingerprint
 *    of the record text so the CLI index script can diff incrementally
 *  - le_link_index:  one row per (link, target) found in the record -
 *    the foundation for the planned Backlink feature
 *  - le_index_meta:  single row (id = 1) with the state of the last
 *    COMPLETE index run - the source of the "fresh" flag on the admin
 *    page (MAX(scanned_at) would go stale on a quiet database)
 */
class Migration3 implements MigrationInterface
{

    public function upgrade(): void
    {

        $scan_table = 'le_record_scan';

        if (!DB::schema()->hasTable($scan_table)) {
            DB::schema()->create($scan_table, function (Blueprint $table): void {
                $table->integer('file');
                $table->string('xref', 20);
                $table->string('rectype', 15);
                $table->string('hash', 32);
                $table->timestamp('scanned_at', 0)->nullable();
                $table->primary(['file', 'xref', 'rectype']);
            });
        }

        $link_table = 'le_link_index';

        if (!DB::schema()->hasTable($link_table)) {
            DB::schema()->create($link_table, function (Blueprint $table): void {
                $table->integer('id', true);
                $table->integer('file');
                $table->string('xref', 20);
                $table->string('rectype', 15);
                $table->string('tag_path', 255)->default('');
                $table->string('link_class', 10);
                $table->text('token');
                $table->text('snippet')->nullable();
                $table->string('target_xref', 20)->nullable();
                $table->string('target_tree', 255)->nullable();
                $table->index(['file', 'xref', 'rectype']);
                $table->index(['target_xref']);
            });
        }

        $meta_table = 'le_index_meta';

        if (!DB::schema()->hasTable($meta_table)) {
            DB::schema()->create($meta_table, function (Blueprint $table): void {
                $table->integer('id')->default(1)->primary();
                $table->timestamp('last_run', 0)->nullable();
                $table->integer('rows')->default(0);
            });
        }
    }
}
