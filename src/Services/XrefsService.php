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
use Fisharebest\Webtrees\GedcomRecord;
use Fisharebest\Webtrees\Tree;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;

final class XrefsService { // stuff related with handling cross references

    /**
     * Valid linkenhancer link: [text](#@param1&paramN) / ![pic](#@...)
     * with a non-empty URL part starting at "#@".
     */
    public const RE_LE_LINK = '/(!?\[[^\]]+\]\(#@[^)]+\))/';

    /**
     * Pass 2 residuals: defective LE remainder "](#@" and classic @XREF@.
     */
    public const RE_REMAINDER = '/(\]\(#@[^)]*|@[A-Za-z0-9:_.-]{1,20}@)/';

    /**
     * The "wt" parameter at a parameter boundary - it may occur multiple
     * times per link and does not have to be the first parameter.
     */
    private const RE_WT_PARAM = '/(?:^|&)wt=/';

    
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

    private static function getGedcomRecTypeSubquery(array $params, string $xref = Gedcom::REGEX_XREF, int|null $file = null): Builder {
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

    
    public static function supportedGedcomTableKeys() : array {
        return array_keys(self::GEDCOM_TABLES);
    }

    public static function supportedGedcomRecordKeys(): array
    {
        return array_merge(self::supportedGedcomTableKeys(), self::GEDCOM_OTHER_SUBTYPES);
    }

    /**
     * sql query for records of a specific type which containing a given or any xref
     * @param Tree|null                 $tree
     * @param string|null $xref
     * @param array<string> $rectypes
     *
     * @return Builder
     */
    public static function getRecordsQuery(Tree|null $tree = null, string|null $xref = null, array $rectypes = []): Builder
    {
        $gedcom_table_keys = self::supportedGedcomTableKeys();
        $gedcom_record_keys = self::supportedGedcomRecordKeys();
        $rectypes = array_map('strtoupper', $rectypes);
        $rectypes = array_values(array_filter($rectypes, fn($s) => in_array($s, $gedcom_record_keys)));
        $rectypes = count($rectypes) === 0 ?
            $gedcom_table_keys :
            $rectypes;
        $other_subtypes_filter = in_array('OTHER', $rectypes) ?
            self::GEDCOM_OTHER_SUBTYPES :
            array_values(array_filter($rectypes, fn($s) => in_array($s, self::GEDCOM_OTHER_SUBTYPES)));
        // NOTE/REPO/_LOC live in the "other" table - make sure it is part of the union
        if ($other_subtypes_filter !== [] && !in_array('OTHER', $rectypes)) {
            $rectypes[] = 'OTHER';
        }
        $rectypes_filter = array_values(array_filter($rectypes, fn($s) => in_array($s, $gedcom_table_keys)));

        $xref ??= Gedcom::REGEX_XREF;

        $file = $tree instanceof Tree ? $tree->id() : null;

        $unionQuery = null;
        foreach ($rectypes_filter as $rectype) {
            $params = self::GEDCOM_TABLES[$rectype];
            $subquery = self::getGedcomRecTypeSubquery($params, $xref, $file);

            if ($rectype === 'OTHER') {
                $subquery->whereIn('o_type', $other_subtypes_filter);
            }

            $unionQuery = $unionQuery ? $unionQuery->unionAll($subquery) : $subquery;
        }

        if ($unionQuery === null) {
            throw new InvalidArgumentException('No valid GEDCOM record types given.');
        }

        $query = DB::query()
            ->fromSub($unionQuery, 'u')
            ->select('u.*')
            ->orderBy('u.file')
            ->orderBy('u.xref');

        return $query;
    }

    /**
     * Classify all link tokens in a single text value.
     *
     * Two-pass scan (A2): pass 1 matches the complete valid LE links first
     * and masks them (same length, offsets stay stable), so pass 2 can never
     * steal fragments of an already classified link.
     *
     * Buckets (mutually exclusive, every link is counted exactly once):
     *  - le       [text](#@…) without a wt= parameter
     *  - lepic    ![pic](#@…) without a wt= parameter
     *  - enhanced LE link whose URL part contains at least one wt= parameter
     *  - classic  plain @XREF@ cross-reference
     *  - other    defective LE remainder "](#@"
     *
     * @return array<int, array{class: string, token: string, snippet: string}>
     *         In offset order. snippet = token plus up to 10 chars of surrounding context.
     */
    public static function classifyTextLinks(string $text): array {
        $found  = [];
        $masked = $text;

        // Pass 1: valid LE links.
        if (preg_match_all(self::RE_LE_LINK, $text, $m1, PREG_OFFSET_CAPTURE) !== false) {
            foreach ($m1[0] as $match) {
                $token  = $match[0];
                $offset = $match[1];

                // URL part: after the last "(#@" up to the closing bracket.
                $pos = strrpos($token, '(#@');
                $url = $pos === false ? '' : substr($token, $pos + 3, -1);

                $class = preg_match(self::RE_WT_PARAM, $url) === 1
                    ? 'enhanced'
                    : (str_starts_with($token, '![') ? 'lepic' : 'le');

                $found[$offset] = [
                    'class'   => $class,
                    'token'   => $token,
                    'snippet' => self::snippet($text, $offset, $token),
                ];

                $masked = substr_replace($masked, str_repeat(' ', strlen($token)), $offset, strlen($token));
            }
        }

        // Pass 2: defective LE remainders and classic xrefs in the residual.
        if (preg_match_all(self::RE_REMAINDER, $masked, $m2, PREG_OFFSET_CAPTURE) !== false) {
            foreach ($m2[0] as $match) {
                $found[$match[1]] = [
                    'class'   => str_starts_with($match[0], '](#@') ? 'other' : 'classic',
                    'token'   => $match[0],
                    'snippet' => self::snippet($text, $match[1], $match[0]),
                ];
            }
        }

        ksort($found);

        return array_values($found);
    }

    /**
     * Full link inventory of one record: every TextTagCollector value
     * classified per entry.
     *
     * @param array<int,string> $tags
     *
     * @return array{
     *     entries: array<int, array{path: string, class: string, token: string, snippet: string}>,
     *     counts: array{le: int, lepic: int, enhanced: int, classic: int, other: int}
     * }
     */
    public static function classifyRecordLinks(GedcomRecord $record, array $tags = TextTagCollector::DEFAULT_TAGS): array {
        $counts = ['le' => 0, 'lepic' => 0, 'enhanced' => 0, 'classic' => 0, 'other' => 0];
        $links  = [];
        foreach (TextTagCollector::collectForRecord($record, $tags) as $entry) {
            foreach (self::classifyTextLinks($entry['value']) as $link) {
                $counts[$link['class']]++;
                $links[] = [
                    'path'    => $entry['path'],
                    'class'   => $link['class'],
                    'token'   => $link['token'],
                    'snippet' => $link['snippet'],
                ];
            }
        }

        return ['entries' => $links, 'counts' => $counts];
    }

    /**
     * Display context: up to 10 characters before and after the token.
     */
    private static function snippet(string $text, int $offset, string $token): string {
        return substr($text, max(0, $offset - 10), 20 + strlen($token));
    }

}