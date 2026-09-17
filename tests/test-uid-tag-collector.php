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

// Standalone CLI test for UidTagCollector - no webtrees bootstrap required.
// Run: php modules_v4/linkenhancer/tests/test-uid-tag-collector.php

// Inline SAPI guard (deliberately without CliBootstrap, to stay standalone):
// modules_v4/ is inside the web root, so this file is reachable by URL.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once __DIR__ . '/../src/Services/UidTagCollector.php';

use Schwendinger\Webtrees\Module\LinkEnhancer\Services\UidTagCollector;

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
    // T1 - GEDCOM 5.5.1 style: record-level _UID at level 1
    'T1 record-level _UID (v5)' => [
        'gedcom'   => "0 @I1@ INDI\n1 _UID ABC123",
        'expected' => [
            ['tag' => '_UID', 'level' => 1, 'path' => 'INDI:_UID', 'value' => 'ABC123'],
        ],
    ],

    // T2 - GEDCOM 7.0 style: record-level UID (no underscore) at level 1
    'T2 record-level UID (v7)' => [
        'gedcom'   => "0 @I1@ INDI\n1 UID XYZ789",
        'expected' => [
            ['tag' => 'UID', 'level' => 1, 'path' => 'INDI:UID', 'value' => 'XYZ789'],
        ],
    ],

    // T3 - multi-level (D6): record _UID + fact-level _UID under BIRT, both reported
    'T3 multi-level record + fact _UID' => [
        'gedcom'   => "0 @I1@ INDI\n1 _UID ROOT-UID\n1 BIRT\n2 _UID BIRT-UID",
        'expected' => [
            ['tag' => '_UID', 'level' => 1, 'path' => 'INDI:_UID', 'value' => 'ROOT-UID'],
            ['tag' => '_UID', 'level' => 2, 'path' => 'INDI:BIRT:_UID', 'value' => 'BIRT-UID'],
        ],
    ],

    // T4 - deep fact-level UID at level 3 (INDI:SOUR:DATA:UID)
    'T4 fact-level UID at level 3' => [
        'gedcom'   => "0 @I1@ INDI\n1 SOUR @S1@\n2 DATA\n3 UID CITE-UID",
        'expected' => [
            ['tag' => 'UID', 'level' => 3, 'path' => 'INDI:SOUR:DATA:UID', 'value' => 'CITE-UID'],
        ],
    ],

    // T5 - lowercase tags are matched case-insensitively; the value is NOT uppercased
    'T5 lowercase tags, verbatim value' => [
        'gedcom'   => "0 @I1@ INDI\n1 _uid Lower-UID\n1 uid MixedUid",
        'expected' => [
            ['tag' => '_UID', 'level' => 1, 'path' => 'INDI:_UID', 'value' => 'Lower-UID'],
            ['tag' => 'UID', 'level' => 1, 'path' => 'INDI:UID', 'value' => 'MixedUid'],
        ],
    ],

    // T6 - a UID is atomic: a following CONT is NOT merged into the value (no CONC/CONT handling)
    'T6 CONT after UID is ignored' => [
        'gedcom'   => "0 @I1@ INDI\n1 _UID ABC\n2 CONT should-not-merge",
        'expected' => [
            ['tag' => '_UID', 'level' => 1, 'path' => 'INDI:_UID', 'value' => 'ABC'],
        ],
    ],

    // T7 - OTHER record subtype: record-type prefix is OTHER (GEDCOM_TABLES covers it)
    'T7 OTHER record subtype' => [
        'gedcom'   => "0 @O1@ OTHER\n1 _UID OTHER-UID",
        'expected' => [
            ['tag' => '_UID', 'level' => 1, 'path' => 'OTHER:_UID', 'value' => 'OTHER-UID'],
        ],
    ],

    // T8 - no normalization: internal spaces in the value are preserved verbatim
    'T8 verbatim value with internal spaces' => [
        'gedcom'   => "0 @I1@ INDI\n1 _UID ABC 123 DEF",
        'expected' => [
            ['tag' => '_UID', 'level' => 1, 'path' => 'INDI:_UID', 'value' => 'ABC 123 DEF'],
        ],
    ],

    // T9 - explicit $record_type parameter wins over the level-0 line
    'T9 explicit record_type parameter' => [
        'gedcom'      => "1 _UID ORPHAN-UID",
        'record_type' => 'FAM',
        'expected'    => [
            ['tag' => '_UID', 'level' => 1, 'path' => 'FAM:_UID', 'value' => 'ORPHAN-UID'],
        ],
    ],

    // T10 - empty input yields no results
    'T10 empty input' => [
        'gedcom'   => '',
        'expected' => [],
    ],

    // T11 - a long (38 char) UID value is captured verbatim
    'T11 38-char UID value' => [
        'gedcom'   => "0 @I1@ INDI\n1 _UID ABCDEFGHIJKLMNOPQRSTUVWXYZ123456789012",
        'expected' => [
            ['tag' => '_UID', 'level' => 1, 'path' => 'INDI:_UID', 'value' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ123456789012'],
        ],
    ],

    // T12 - a UID tag with no value is still reported (empty value), left to the build step to skip
    'T12 UID tag with empty value' => [
        'gedcom'   => "0 @I1@ INDI\n1 _UID",
        'expected' => [
            ['tag' => '_UID', 'level' => 1, 'path' => 'INDI:_UID', 'value' => ''],
        ],
    ],

    // T13 - CRLF input is handled like LF
    'T13 CRLF input' => [
        'gedcom'   => "0 @I1@ INDI\r\n1 _UID CRLF-UID",
        'expected' => [
            ['tag' => '_UID', 'level' => 1, 'path' => 'INDI:_UID', 'value' => 'CRLF-UID'],
        ],
    ],

    // T14 - non-UID tags and non-targets are ignored
    'T14 non-UID tags ignored' => [
        'gedcom'   => "0 @I1@ INDI\n1 NAME Max\n1 BIRT\n2 DATE 1 JAN 1900",
        'expected' => [],
    ],
];

foreach ($fixtures as $name => $fixture) {
    $record_type = $fixture['record_type'] ?? null;
    $actual      = UidTagCollector::collect($fixture['gedcom'], UidTagCollector::DEFAULT_TAGS, $record_type);
    check($name, $actual, $fixture['expected']);
}

echo "\n{$total} tests, {$failures} failure(s)\n";
exit($failures === 0 ? 0 : 1);
