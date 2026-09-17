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

use Fisharebest\Webtrees\GedcomRecord;

use function array_keys;
use function array_merge;
use function array_pop;
use function end;
use function implode;
use function preg_match;
use function preg_split;
use function strtoupper;
use function trim;

/**
 * Collects the values of UID tags (_UID in GEDCOM 5.5.1 / UID in GEDCOM 7.0)
 * at every level of a single raw GEDCOM record.
 *
 * UID is an identifier of the record's superstructure (GEDCOM 5.5.1/7.0): it
 * sits at level 1 for the record itself, but GEDCOM 7 also allows it on nested
 * fact structures (e.g. INDI:*:_UID). This collector is therefore multi-level
 * (D6): it walks every level and reports each UID it finds, tagged with its
 * webtrees-style hierarchy path (record-type prefix + ancestor facts), e.g.
 * "INDI:_UID" or "INDI:BIRT:_UID".
 *
 * Unlike TextTagCollector it has NO CONC/CONT handling: a UID is an atomic,
 * single-line value and is never continued. The value is captured verbatim -
 * no trimming, no letter-case or format normalization (R5 / no-normalization).
 * Tag matching is case-insensitive.
 *
 * Works on raw record text - no privacy filtering. Both DB-style and raw-file
 * style input is parsed.
 */
final class UidTagCollector
{
    public const DEFAULT_TAGS = ['_UID', 'UID'];

    /**
     * Find every UID tag in a raw record's GEDCOM text and return its value,
     * level and webtrees-style hierarchy path.
     *
     * @param array<int, string> $tags
     *
     * @return array<int, array{tag: string, level: int, path: string, value: string}>
     */
    public static function collect(string $gedcom, array $tags = self::DEFAULT_TAGS, ?string $record_type = null): array
    {
        $lines = preg_split('/[\r\n]+/', $gedcom);
        if ($lines === false) {
            $lines = [];
        }
        if ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }

        if ($record_type === null) {
            $record_type = '';
            if (isset($lines[0]) && preg_match('/^0(?: @[^@]+@)? ([_A-Za-z0-9]+)/', $lines[0], $match) === 1) {
                $record_type = $match[1];
            }
        }
        $record_type = strtoupper($record_type);

        $targets = [];
        foreach ($tags as $tag) {
            $targets[strtoupper($tag)] = true;
        }

        $results = [];
        $level_stack = [];

        foreach ($lines as $line) {
            if (preg_match('/^(\d)[ \t]*(?:@[^@\s]+@[ \t]*)?([_A-Za-z0-9]+)(?:[ \t](.*))?$/u', $line, $match) !== 1) {
                continue;
            }

            $lvl  = (int) $match[1];
            $tag  = strtoupper($match[2]);
            $data = $match[3] ?? '';

            if ($lvl === 0) {
                $level_stack = [];
                continue;
            }

            // Maintain the ancestor stack for path building.
            foreach (array_keys($level_stack) as $k) {
                if ($k > $lvl) {
                    unset($level_stack[$k]);
                }
            }
            $level_stack[$lvl] = $tag;

            if (isset($targets[$tag])) {
                $ancestors = [];
                for ($k = 1; $k < $lvl; $k++) {
                    if (isset($level_stack[$k])) {
                        $ancestors[] = $level_stack[$k];
                    }
                }

                $results[] = [
                    'tag'   => $tag,
                    'level' => $lvl,
                    'path'  => trim($record_type . ':' . implode(':', array_merge($ancestors, [$tag])), ':'),
                    'value' => $data,
                ];
            }
        }

        return $results;
    }

    /**
     * Collect UIDs from a single record's raw GEDCOM text.
     *
     * @param array<int, string> $tags
     *
     * @return array<int, array{tag: string, level: int, path: string, value: string}>
     */
    public static function collectForRecord(GedcomRecord $record, array $tags = self::DEFAULT_TAGS): array
    {
        return self::collect($record->gedcom(), $tags, $record->tag());
    }
}
