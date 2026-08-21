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

namespace Schwendinger\Webtrees\Module\LinkEnhancer\Services;

use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Gedcom;
use Fisharebest\Webtrees\Tree;
use Illuminate\Database\Query\Builder;

class XrefsService { // stuff related with handling cross references

    
    public const GEDCOM_TABLES = [
        'INDI' => [
            'table'      => 'individuals',
            'prefix'     => 'i',
            'typestr'    => "'INDI'",
        ],
        'FAM' => [
            'table'      => 'families',
            'prefix'     => 'f',
            'typestr'    => "'FAM'",
        ],
        'MEDIA' => [
            'table'      => 'media',
            'prefix'     => 'm',
            'typestr'    => "'MEDIA'",
        ],
        'SOUR' => [
            'table'      => 'sources',
            'prefix'     => 's',
            'typestr'    => "'SOUR'",
        ],
        'OTHER' => [ // NOTE, REPO, _LOC
            'table'      => 'other',
            'prefix'     => 'o',
            'typestr'    => '`o_type`',
        ]
    ];

    public const GEDCOM_OTHER_SUBTYPES = [ "NOTE", "REPO", "_LOC" ];

    private function getGedcomRecTypeSubquery(array $params, string $xref = Gedcom::REGEX_XREF, int|null $file = null): Builder {
        $query = DB::table($params['table'])
            ->select(
                DB::raw("`{$params['prefix']}_id` AS xref"),
                DB::raw("`{$params['prefix']}_file` AS file"), 
                DB::raw("{$params['typestr']} AS type"), 
                DB::raw("`{$params['prefix']}_gedcom` AS gedcom")
            );

        if ($file !== null) {
            $query->where("`{$params['prefix']}_file`", "=", $file);
        }

        $re_pattern = [];
        if ($params['table'] === 'other') { // makes only sense in other table for shared notes
            $re_pattern[] = "0 @" . Gedcom::REGEX_XREF . "@ NOTE .*@{$xref}@";
            $re_pattern[] = "0 @" . Gedcom::REGEX_XREF . "@ NOTE .+\\]\\(#@";
        }
        array_push($re_pattern, ...[
            // search for @XREF@ - so also classic cross-references supported by webtrees are covered
            "[1-9] NOTE .+@{$xref}@",
            "[1-9] NOTE @{$xref}@.+",
            "[1-9] CON[CT] .*@{$xref}@",
            "[1-9] TEXT .*@{$xref}@",
            "[1-9] _TODO .*@{$xref}@",
            // search for linkenhancer syntax "](#@"
            "[1-9] NOTE .+\\]\\(#@",
            "[1-9] CON[CT] .+\\]\\(#@",
            "[1-9] TEXT .+\\]\\(#@",
            "[1-9] _TODO .+\\]\\(#@"            
        ]);

        $field = "{$params['prefix']}_gedcom";
        $query->where(function ($q) use ($re_pattern, $field) {
            foreach ($re_pattern as $pattern) {
                $q->orWhere($field, DB::regexOperator(), $pattern);
            }
        });

        return $query;
    }

    
    public function supportedGedcomTableKeys() : array {
        return array_keys(self::GEDCOM_TABLES);
    }

    public function supportedGedcomRecordKeys(): array
    {
        return array_merge($this->supportedGedcomTableKeys(), self::GEDCOM_OTHER_SUBTYPES);
    }

    /**
     * sql query for records of a specific type which containing a given or any xref
     * @param Tree|null                 $tree
     * @param string|null $xref
     * @param array<string> $rectypes
     *
     * @return Builder
     */
    public function getRecordsQuery(Tree|null $tree = null, string|null $xref = null, array $rectypes = []): Builder
    {
        $gedcom_table_keys = $this->supportedGedcomTableKeys();
        $gedcom_record_keys = $this->supportedGedcomRecordKeys();
        $rectypes = array_map('strtoupper', $rectypes);
        $rectypes = array_filter($rectypes, fn($s) => in_array($s, $gedcom_record_keys));
        $rectypes = count($rectypes) === 0 ?
            $gedcom_table_keys :
            $rectypes;
        $other_subtypes_filter = in_array('OTHER', $rectypes) ? 
            self::GEDCOM_OTHER_SUBTYPES : 
            array_filter($rectypes, fn($s) => in_array($s, self::GEDCOM_OTHER_SUBTYPES));
        $rectypes_filter = array_filter($rectypes, fn($s) => in_array($s, $gedcom_table_keys));

        $xref ??= Gedcom::REGEX_XREF;

        $file = $tree instanceof Tree ? $tree->id() : null;

        $unionQuery = null;
        foreach ($rectypes_filter as $rectype) {
            $params = self::GEDCOM_TABLES[$rectype];
            $subquery = $this->getGedcomRecTypeSubquery($params, $xref, $file);

            if ($rectype == 'OTHER') {
                $subquery->whereIn('o_type', $other_subtypes_filter);
            }

            $unionQuery = $unionQuery ? $unionQuery->unionAll($subquery) : $subquery;
        }

        $query = DB::query()
            ->fromSub($unionQuery, 'u')
            ->select('u.*')
            ->orderBy('u.file')
            ->orderBy('u.xref');

        return $query;
    }

}