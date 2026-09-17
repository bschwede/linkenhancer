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
 * Upgrade the database schema from version 5 to version 6.
 *
 * Creates the UID index (see .opencode/plans/linkenhancer-uid-implementation.md):
 *  - le_uid_index: one row per UID tag found in a record - record-level AND
 *    fact-level (_UID v5 / UID v7), per the multi-level decision (D6). Carries
 *    the parsed UID value verbatim (no normalization), the finding location
 *    (tag_path), and an MD5 fingerprint (hash) for incremental change detection
 *    against le_record_scan (D1). No unique constraint: UIDs are not guaranteed
 *    unique (cross-tree duplication, several UIDs per record).
 *  - le_index_meta: gains two columns (uid_last_run, uid_rows) so the UID index
 *    reports its own completeness/freshness on the SAME single row (id = 1) the
 *    link index already uses (D5 meta-reuse). The link-index columns
 *    (last_run, rows) are left untouched.
 *
 * Case-sensitivity (R5, "no normalization"): the uid column is left with the
 * default (portable) collation; the exact, case-preserving match is enforced in
 * the query layer (UidIndexService::lookup) so the schema stays portable across
 * MariaDB/MySQL/PostgreSQL.
 */
class Migration5 implements MigrationInterface
{

    public function upgrade(): void
    {

        $uid_table = 'le_uid_index';

        if (!DB::schema()->hasTable($uid_table)) {
            DB::schema()->create($uid_table, function (Blueprint $table): void {
                $table->integer('id', true);
                $table->integer('file');
                $table->string('xref', 20);
                $table->string('uid', 255);
                $table->string('rectype', 15);
                $table->string('tag_path', 255)->default('');
                $table->string('hash', 32);
                $table->index(['uid']);
                $table->index(['file', 'xref', 'rectype']);
            });
        }

        $meta_table = 'le_index_meta';

        if (DB::schema()->hasTable($meta_table) && !DB::schema()->hasColumn($meta_table, 'uid_last_run')) {
            DB::schema()->table($meta_table, static function (Blueprint $t): void {
                $t->timestamp('uid_last_run', 0)->nullable()->after('rows');
                $t->integer('uid_rows')->default(0)->after('uid_last_run');
            });
        }
    }
}
