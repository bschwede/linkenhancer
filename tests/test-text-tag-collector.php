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

// Standalone CLI test for TextTagCollector - no webtrees bootstrap required.
// Run: php modules_v4/linkenhancer/tests/test-text-tag-collector.php

// Inline SAPI guard (deliberately without CliBootstrap, to stay standalone):
// modules_v4/ is inside the web root, so this file is reachable by URL.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once __DIR__ . '/../src/Services/TextTagCollector.php';

use Schwendinger\Webtrees\Module\LinkEnhancer\Services\TextTagCollector;

$failures = 0;
$total    = 0;

function check(string $name, array $actual, array $expected): void {
    global $failures, $total;
    $total++;
    if ($actual === $expected) {
        echo "PASS  {$name}\n";
    } else {
        $failures++;
        echo "FAIL  {$name}\n";
        echo '  expected: ' . var_export($expected, true) . "\n";
        echo '  actual:   ' . var_export($actual, true) . "\n";
    }
}

$fixtures = [
    // T1 - webtrees style: level-1 note, line break via 2 CONT (CreateNoteAction pattern)
    'T1 INDI note with CONT' => [
        'gedcom'   => "0 @I1@ INDI\n1 NOTE Zeile 1\n2 CONT Zeile 2",
        'expected' => [
            ['tag' => 'NOTE', 'level' => 1, 'path' => 'INDI:NOTE', 'value' => "Zeile 1\nZeile 2"],
        ],
    ],

    // T2 - shared note: text on level 0, continuation via 1 CONT (Note::getNote pattern)
    'T2 shared note level-0 text with CONT' => [
        'gedcom'   => "0 @N1@ NOTE text\n1 CONT weiter",
        'expected' => [
            ['tag' => 'NOTE', 'level' => 0, 'path' => 'NOTE', 'value' => "text\nweiter"],
        ],
    ],

    // T3 - shared note with 1 CONC (GedcomEditService pattern): CONC merged WITHOUT separator,
    // non-text subfacts (CHAN/DATE) are ignored
    'T3 shared note with CONC' => [
        'gedcom'   => "0 @N1@ NOTE erst\n1 CONC zweitens\n1 CHAN\n2 DATE 1 JAN 2020",
        'expected' => [
            ['tag' => 'NOTE', 'level' => 0, 'path' => 'NOTE', 'value' => 'erstzweitens'],
        ],
    ],

    // T4 - raw .ged file style: multiple same-level CONC lines, not merged
    'T4 raw file with multiple CONC lines' => [
        'gedcom'   => "0 @I9@ INDI\n1 NOTE Teil A\n1 CONC Teil B\n1 CONC Teil C\n2 DATE 1 JAN 2000",
        'expected' => [
            ['tag' => 'NOTE', 'level' => 1, 'path' => 'INDI:NOTE', 'value' => 'Teil ATeil BTeil C'],
        ],
    ],

    // T4b - an enhanced link split across a CONC boundary is reassembled
    'T4b enhanced link reassembled across CONC boundary' => [
        'gedcom'   => "0 @I9@ INDI\n1 NOTE Link #@wt=i@I1@\n1 CONC @tree+dia Ende",
        'expected' => [
            ['tag' => 'NOTE', 'level' => 1, 'path' => 'INDI:NOTE', 'value' => 'Link #@wt=i@I1@@tree+dia Ende'],
        ],
    ],

    // T5 - source record: level-1 TEXT + level-1 NOTE
    'T5 source with TEXT and NOTE' => [
        'gedcom'   => "0 @S1@ SOUR\n1 TITL Quelle\n1 TEXT Auszug\n2 CONT mehr\n1 NOTE Quellen-Note",
        'expected' => [
            ['tag' => 'TEXT', 'level' => 1, 'path' => 'SOUR:TEXT', 'value' => "Auszug\nmehr"],
            ['tag' => 'NOTE', 'level' => 1, 'path' => 'SOUR:NOTE', 'value' => 'Quellen-Note'],
        ],
    ],

    // T5b - citation on an individual: level-3 TEXT with CONC
    'T5b citation 3 TEXT with CONC on INDI' => [
        'gedcom'   => "0 @I1@ INDI\n1 SOUR @S9@\n2 DATA\n3 TEXT Zitattext\n3 CONC Teil2\n2 EVEN",
        'expected' => [
            ['tag' => 'TEXT', 'level' => 3, 'path' => 'INDI:SOUR:DATA:TEXT', 'value' => 'ZitattextTeil2'],
        ],
    ],

    // T6 - _TODO as level-1 fact
    'T6 _TODO fact' => [
        'gedcom'   => "0 @I1@ INDI\n1 _TODO Prüfen",
        'expected' => [
            ['tag' => '_TODO', 'level' => 1, 'path' => 'INDI:_TODO', 'value' => 'Prüfen'],
        ],
    ],

    // T7 - level-2 note under an event
    'T7 level-2 note under event' => [
        'gedcom'   => "0 @I1@ INDI\n1 BIRT\n2 DATE 1 JAN 1900\n2 NOTE Geburtsnote",
        'expected' => [
            ['tag' => 'NOTE', 'level' => 2, 'path' => 'INDI:BIRT:NOTE', 'value' => 'Geburtsnote'],
        ],
    ],

    // T8 - broken hierarchy: CONC at the wrong (deeper) level is ignored
    'T8 CONC at wrong level is ignored' => [
        'gedcom'   => "0 @I1@ INDI\n1 NOTE Text\n3 CONC zu tief\n1 BIRT",
        'expected' => [
            ['tag' => 'NOTE', 'level' => 1, 'path' => 'INDI:NOTE', 'value' => 'Text'],
        ],
    ],

    // T9 - negative control: a shared-note XREF reference is delivered as value, BIRT/PLAC are not
    'T9 XREF note reference' => [
        'gedcom'   => "0 @I1@ INDI\n1 BIRT\n2 PLAC Berlin\n1 NOTE @N5@",
        'expected' => [
            ['tag' => 'NOTE', 'level' => 1, 'path' => 'INDI:NOTE', 'value' => '@N5@'],
        ],
    ],

    // T10 - CRLF input is handled like LF
    'T10 CRLF input' => [
        'gedcom'   => "0 @I1@ INDI\r\n1 NOTE Zeile 1\r\n2 CONT Zeile 2",
        'expected' => [
            ['tag' => 'NOTE', 'level' => 1, 'path' => 'INDI:NOTE', 'value' => "Zeile 1\nZeile 2"],
        ],
    ],

    // T11 - the user reference example: full hierarchy INDI:SOUR:DATA:NOTE
    'T11 user reference example INDI:SOUR:DATA:NOTE' => [
        'gedcom'   => "0 @I1@ INDI\n1 NAME Max Mustermann\n1 SOUR @S9@\n2 DATA\n3 NOTE Zitat-Note",
        'expected' => [
            ['tag' => 'NOTE', 'level' => 3, 'path' => 'INDI:SOUR:DATA:NOTE', 'value' => 'Zitat-Note'],
        ],
    ],

    // T12 - empty input yields no results
    'T12 empty input' => [
        'gedcom'   => '',
        'expected' => [],
    ],

    // T13 - explicit $record_type parameter wins over the level-0 line
    'T13 explicit record_type parameter' => [
        'gedcom'      => "1 NOTE Hallo",
        'record_type' => 'FAM',
        'expected'    => [
            ['tag' => 'NOTE', 'level' => 1, 'path' => 'FAM:NOTE', 'value' => 'Hallo'],
        ],
    ],

    // T14 - raw file with lowercase tags (case-insensitive handling)
    'T14 lowercase tags from raw file' => [
        'gedcom'   => "0 @I1@ indi\n1 note hello\n1 _todo check",
        'expected' => [
            ['tag' => 'NOTE', 'level' => 1, 'path' => 'INDI:NOTE', 'value' => 'hello'],
            ['tag' => '_TODO', 'level' => 1, 'path' => 'INDI:_TODO', 'value' => 'check'],
        ],
    ],
];

foreach ($fixtures as $name => $fixture) {
    $record_type = $fixture['record_type'] ?? null;
    $actual      = TextTagCollector::collect($fixture['gedcom'], TextTagCollector::DEFAULT_TAGS, $record_type);
    check($name, $actual, $fixture['expected']);
}

// Extra: the reassembled T4b value must match the enhanced-link regex (XrefOverviewService pattern)
$reassembled = TextTagCollector::collect("0 @I9@ INDI\n1 NOTE Link #@wt=i@I1@\n1 CONC @tree+dia Ende")[0]['value'];
check(
    'T4b enhanced-link regex matches reassembled value',
    [preg_match('/#@wt=([ifsnrl])?@([A-Za-z0-9:_.-]+)@([^@\s&]*)/', $reassembled)],
    [1]
);

echo "\n{$total} tests, {$failures} failure(s)\n";
exit($failures === 0 ? 0 : 1);
