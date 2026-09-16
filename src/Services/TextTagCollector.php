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
use function in_array;
use function preg_match;
use function preg_split;
use function strtoupper;
use function trim;

/**
 * Collects the values of free-text tags (default: TEXT, NOTE, _TODO) at all
 * levels of a single raw GEDCOM record, including CONC and CONT continuation
 * lines.
 *
 * Each result carries the finding location as a webtrees-style tag hierarchy
 * with record-type prefix, e.g. "INDI:SOUR:DATA:NOTE". For a shared NOTE
 * record the level-0 text IS the record, so its path is just "NOTE".
 *
 * Note: works on raw record text - no privacy filtering. Both DB-style
 * (CONC already merged at import, CONT for line breaks) and raw-file style
 * (explicit CONC lines) input is parsed.
 */
final class TextTagCollector {

    /** Default target tags. @var array<int,string> */
    public const DEFAULT_TAGS = ['NOTE', 'TEXT', '_TODO'];

    /**
     * Collect target-tag values from a raw GEDCOM record text.
     *
     * @param string           $gedcom      raw record text (e.g. $record->gedcom() or a *_gedcom DB column)
     * @param array<int,string> $tags        target tags (case-insensitive)
     * @param string|null      $record_type record-type prefix for the paths; null = derived from the level-0 line
     *
     * @return array<int, array{tag: string, level: int, path: string, value: string}>
     */
    public static function collect(string $gedcom, array $tags = self::DEFAULT_TAGS, string|null $record_type = null): array {
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

        $results     = [];
        $capturing   = false;
        $cap_level   = 0;
        $cap_entry   = null;
        $level_stack = [];

        // Close the currently open capture (if any) and append it to the results.
        $close = function () use (&$capturing, &$cap_level, &$cap_entry, &$results): void {
            if ($capturing && $cap_entry !== null) {
                $results[] = $cap_entry;
                $capturing = false;
                $cap_level = 0;
                $cap_entry = null;
            }
        };

        foreach ($lines as $line) {
            // level + optional xref + tag + optional value (same anatomy as GedcomImportService line parsing)
            if (preg_match('/^(\d)[ \t]*(?:@[^@\s]+@[ \t]*)?([_A-Za-z0-9]+)(?:[ \t](.*))?$/u', $line, $match) !== 1) {
                continue;
            }
            $lvl  = (int) $match[1];
            $tag  = strtoupper($match[2]);
            $data = $match[3] ?? '';

            if ($lvl === 0) {
                // A new record starts: close any open capture and reset the stack.
                $close();
                $level_stack = [];
                if ($tag === 'NOTE' && isset($targets['NOTE'])) {
                    // A shared note stores its text on the level-0 line itself.
                    $capturing = true;
                    $cap_level = 0;
                    $cap_entry = [
                        'tag'   => 'NOTE',
                        'level' => 0,
                        'path'  => $record_type,
                        'value' => $data,
                    ];
                }
                continue;
            }

            if ($capturing) {
                // CONC continues the SAME logical line - no separator, mirroring the
                // import merge in GedcomImportService::reformatRecord(). Spec level is
                // the same as the value line; for level-0 note text (shared notes) it
                // is level 1. We also accept one level deeper to be lenient with
                // malformed files (the import merge is level-agnostic as well).
                if ($tag === 'CONC' && in_array($lvl, [$cap_level, $cap_level + 1], true)) {
                    $cap_entry['value'] .= $data;
                    continue;
                }
                // CONT is a child line (level + 1) and starts a new paragraph.
                if ($tag === 'CONT' && $lvl === $cap_level + 1) {
                    $cap_entry['value'] .= "\n" . $data;
                    continue;
                }
                if ($lvl <= $cap_level) {
                    // The captured tag ends here; this line may open a new capture.
                    $close();
                } else {
                    // A subtag of the captured tag - not part of the value.
                    $level_stack[$lvl] = $tag;
                    foreach (array_keys($level_stack) as $k) {
                        if ($k > $lvl) {
                            unset($level_stack[$k]);
                        }
                    }
                    continue;
                }
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
                $capturing = true;
                $cap_level = $lvl;
                $cap_entry = [
                    'tag'   => $tag,
                    'level' => $lvl,
                    'path'  => trim($record_type . ':' . implode(':', array_merge($ancestors, [$tag])), ':'),
                    'value' => $data,
                ];
            }
        }

        $close();

        return $results;
    }

    /**
     * Convenience wrapper around the standard access: uses the raw record
     * text ($record->gedcom()) and the record type ($record->tag()).
     *
     * @param array<int,string> $tags
     *
     * @return array<int, array{tag: string, level: int, path: string, value: string}>
     */
    public static function collectForRecord(GedcomRecord $record, array $tags = self::DEFAULT_TAGS): array {
        return self::collect($record->gedcom(), $tags, $record->tag());
    }
}
