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

declare(strict_types=1);

namespace Schwendinger\Webtrees\Module\LinkEnhancer\Services;

use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Family;
use Fisharebest\Webtrees\Gedcom;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Location;
use Fisharebest\Webtrees\Media;
use Fisharebest\Webtrees\Note;
use Fisharebest\Webtrees\Repository;
use Fisharebest\Webtrees\Source;
use Fisharebest\Webtrees\Tree;
use Illuminate\Database\Query\Builder;


enum RecType {
    case Individual;
    case Family;
    case Note;
    case Source;
    case Repository;
    case Media;
    case Location;
    case Html;
}

class XrefsService { // stuff related with handling cross references

    /**
     * sql query for records of a specific type which containing a given or any xref
     * @param Tree                 $tree
     * @param array<string,string> $params
     *
     * @return Builder
     */
    public function getRecordsQuery(Tree $tree, RecType $rectype, array $params): Builder
    {
        $query = null;
        $xref  = isset($params['xref']) ? $params['xref'] : Gedcom::REGEX_XREF;
        switch ($rectype) {
            case RecType::Html:
            //  SELECT bs.*  FROM `block` AS b INNER JOIN `block_setting` AS bs ON b.`block_id` = bs.`block_id` WHERE b.`gedcom_id` = 1 AND b.`module_name` = 'html' AND bs.`setting_name` = 'html';
                break;

            default:
                [ $table, $type ] = $this->getRecTypeQueryParameter($rectype);
                $prefix = $table[0];
                
                $query = DB::table($table);
                if ($table == 'other') {
                    $query->where('o_type', '=', $type);
                }
                
                $fieldname = $prefix . '_gedcom';
                $query
                    ->where($prefix . '_file', '=', $tree->id())
                    ->where(function ($query2) use ($fieldname, $xref) {
                        $query2
                            ->where($fieldname, DB::regexOperator(), '0 @' . Gedcom::REGEX_XREF . `@ NOTE .*@{$xref}@`)
                            ->orWhere($fieldname, DB::regexOperator(), `[1-9] NOTE .+@{$xref}@`)
                            ->orWhere($fieldname, DB::regexOperator(), `[1-9] NOTE @{$xref}@.+`)
                            ->orWhere($fieldname, DB::regexOperator(), `[1-9] CON[CT] .*@{$xref}@`)
                            ->orWhere($fieldname, DB::regexOperator(), `[1-9] TEXT .*@{$xref}@`)
                            ->orWhere($fieldname, DB::regexOperator(), `[1-9] _TODO .*@{$xref}@`);
                    });



                //if (isset($params['start'], $params['end'])) {
                //    $query->whereBetween($prefix . '_id', [$params['start'], $params['end']]);
                //}
                break;
            }

        return $query;
    }

    private function getRecTypeQueryParameter(RecType $rectype): array {
        
        return match ($rectype) { // table, type (only for 'other')
            RecType::Individual => [ 'individuals', '' ],
            RecType::Family     => [ 'families',    '' ],
            RecType::Note       => [ 'other',       Note::RECORD_TYPE ],
            RecType::Source     => [ 'sources',     '' ],
            RecType::Repository => [ 'other',       Repository::RECORD_TYPE ],
            RecType::Media      => [ 'media',       '' ],
            RecType::Location   => [ 'other',       Location::RECORD_TYPE ],
            default             => [ '', '']
        };
    }
}