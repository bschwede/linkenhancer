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

// Standalone CLI test for XrefsService::classifyTextLinks() - pure function,
// no webtrees bootstrap required.
// Run: php modules_v4/linkenhancer/tests/test-link-classifier.php

// Inline SAPI guard (deliberately without CliBootstrap, to stay standalone):
// modules_v4/ is inside the web root, so this file is reachable by URL.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

// The single vendor helper file (no platform check, unlike vendor/autoload)
// provides e() - used by the inventory/count HTML helpers.
require_once __DIR__ . '/../../../vendor/illuminate/support/helpers.php';
require_once __DIR__ . '/../src/Services/XrefsService.php';
require_once __DIR__ . '/../src/Services/TextTagCollector.php';

use Schwendinger\Webtrees\Module\LinkEnhancer\Services\TextTagCollector;
use Schwendinger\Webtrees\Module\LinkEnhancer\Services\XrefsService;

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

// Expected values are [class, token] pairs in offset order; the snippet
// (display context) is checked separately.
$fixtures = [
    // T1 - the F3 steal regression: short LE text used to be claimed by the
    // malformed-LE alternative of the old single-pass regex
    'T1 short LE link' => [
        'text'     => '[I1](#@I2@)',
        'expected' => [['le', '[I1](#@I2@)']],
    ],
    'T2 long LE link' => [
        'text'     => '[LangerTextHier](#@I2@)',
        'expected' => [['le', '[LangerTextHier](#@I2@)']],
    ],
    'T3 LE pic link' => [
        'text'     => '![Bild](#@M1@)',
        'expected' => [['lepic', '![Bild](#@M1@)']],
    ],
    'T4 classic xref' => [
        'text'     => 'sah @I1@ heute',
        'expected' => [['classic', '@I1@']],
    ],
    // T5 - classic xref directly before an LE link: both counted, old regex
    // swallowed the link into two "classic" matches
    'T5 classic xref adjacent to LE link' => [
        'text'     => '@I1@[x](#@I2@)',
        'expected' => [['classic', '@I1@'], ['le', '[x](#@I2@)']],
    ],
    // T6 - defective LE (missing closing bracket): one "other", the xref
    // inside the remainder must not be counted as classic
    'T6 defective LE without closing bracket' => [
        'text'     => '[t](#@I2@',
        'expected' => [['other', '](#@I2@']],
    ],
    'T7 defective LE without label' => [
        'text'     => '](#@I2@)',
        'expected' => [['other', '](#@I2@']],
    ],
    // T8 - xref inside the link label belongs to the link, no extra classic
    'T8 xref inside LE label' => [
        'text'     => '[see @I1@](#@I2@)',
        'expected' => [['le', '[see @I1@](#@I2@)']],
    ],
    // T9 - enhanced: wt= parameter, target xref not counted additionally
    'T9 enhanced link (wt= first parameter)' => [
        'text'     => '[x](#@wt=i@I2@tree1)',
        'expected' => [['enhanced', '[x](#@wt=i@I2@tree1)']],
    ],
    // T10 - enhanced: wt= does not have to be the first parameter
    'T10 enhanced link (wt= not first)' => [
        'text'     => '[x](#@fsft=123&wt=s@R1@)',
        'expected' => [['enhanced', '[x](#@fsft=123&wt=s@R1@)']],
    ],
    // T11 - enhanced: wt= may occur multiple times per link - still ONE link
    'T11 enhanced link with multiple wt= parameters' => [
        'text'     => '[x](#@wt=i@I1@t&wt=n@N1@)',
        'expected' => [['enhanced', '[x](#@wt=i@I1@t&wt=n@N1@)']],
    ],
    // T12 - external target without wt= stays a plain LE link
    'T12 external target only' => [
        'text'     => '[x](#@wp=de/Artikel)',
        'expected' => [['le', '[x](#@wp=de/Artikel)']],
    ],
    'T13 enhanced pic link' => [
        'text'     => '![p](#@wt=n@N1@)',
        'expected' => [['enhanced', '![p](#@wt=n@N1@)']],
    ],
    // T14 - mixed sentence, offset order
    'T14 mixed sentence' => [
        'text'     => 'Text @I1@ und [I2](#@I3@) Ende',
        'expected' => [['classic', '@I1@'], ['le', '[I2](#@I3@)']],
    ],
    'T15 empty text' => [
        'text'     => '',
        'expected' => [],
    ],
    'T16 empty LE url' => [
        'text'     => '](#@)',
        'expected' => [['other', '](#@']],
    ],
    'T17 plain text without links' => [
        'text'     => 'nur Text, kein Link',
        'expected' => [],
    ],
];

foreach ($fixtures as $name => $fixture) {
    $actual = array_map(
        static fn (array $entry): array => [$entry['class'], $entry['token']],
        XrefsService::classifyTextLinks($fixture['text'])
    );
    check($name, $actual, $fixture['expected']);
}

// T18 - snippet = token plus up to 10 chars of surrounding context
$text = '1234567890@X1@1234567890';
check(
    'T18 snippet context window',
    [XrefsService::classifyTextLinks($text)[0]['snippet']],
    [$text]
);
$text = 'sah @I1@ heute';
check(
    'T18b snippet shorter than window',
    [XrefsService::classifyTextLinks($text)[0]['snippet']],
    ['sah @I1@ heute']
);

// ---------------------------------------------------------------------------
// extractLinkTargets (backlink foundation)
// ---------------------------------------------------------------------------
$target_fixtures = [
    'T19 classic xref'                     => ['@I1@',                    [['xref' => 'I1', 'tree' => null]]],
    'T19b classic xref with dot'           => ['@I1.2@',                  [['xref' => 'I1.2', 'tree' => null]]],
    'T19c LE link without wt='             => ['[x](#@I2@)',              []],
    'T19d LE link external target'         => ['[x](#@wp=de/Artikel)',    []],
    'T19e enhanced single target'          => ['[x](#@wt=i@I2@tree1)',    [['xref' => 'I2', 'tree' => 'tree1']]],
    'T19f enhanced without type letter'    => ['[x](#@wt=@I2@)',          [['xref' => 'I2', 'tree' => null]]],
    'T19g enhanced without tree'           => ['[x](#@wt=i@I2@)',         [['xref' => 'I2', 'tree' => null]]],
    'T19h enhanced multiple targets'       => ['[x](#@wt=i@I1@t&wt=n@N1@)', [['xref' => 'I1', 'tree' => 't'], ['xref' => 'N1', 'tree' => null]]],
    'T19i enhanced wt= not first'          => ['[x](#@fsft=1&wt=s@R1@)',  [['xref' => 'R1', 'tree' => null]]],
    'T19j enhanced pic link'               => ['![p](#@wt=n@N1@)',        [['xref' => 'N1', 'tree' => null]]],
    'T19k defective LE remainder'          => ['](#@I2@',                 []],
];
foreach ($target_fixtures as $name => [$token, $expected]) {
    check($name, XrefsService::extractLinkTargets($token), $expected);
}

// ---------------------------------------------------------------------------
// classifyGedcomText (C4 raw-text entry point, used by the data handler)
// ---------------------------------------------------------------------------
$gedcom = "0 @I1@ INDI\n1 NOTE see [I2](#@wt=i@I3@tree1) and @I4@\n2 CONT plus @I5@";
$inventory = XrefsService::classifyGedcomText($gedcom, TextTagCollector::DEFAULT_TAGS, 'INDI');
check(
    'T20 classifyGedcomText counts',
    $inventory['counts'],
    ['le' => 0, 'lepic' => 0, 'enhanced' => 1, 'classic' => 2, 'other' => 0]
);
check(
    'T20b classifyGedcomText entries',
    array_map(static fn (array $e): array => [$e['path'], $e['class'], $e['token']], $inventory['entries']),
    [
        ['INDI:NOTE', 'enhanced', '[I2](#@wt=i@I3@tree1)'],
        ['INDI:NOTE', 'classic', '@I4@'],
        ['INDI:NOTE', 'classic', '@I5@'],
    ]
);
check('T20c classifyGedcomText without links', XrefsService::classifyGedcomText("0 @I1@ INDI\n1 NAME x")['counts'], XrefsService::emptyCounts());

// ---------------------------------------------------------------------------
// F7: shared-note pointer rule (classifyGedcomText)
// a NOTE tag (level >= 1) whose value is exactly one xref is a structural
// shared-note pointer - silently skipped, not a link.
// ---------------------------------------------------------------------------
check(
    'F7 naked NOTE pointer (level 1) is not a link',
    XrefsService::classifyGedcomText("0 @I1@ INDI\n1 NOTE @N5@", TextTagCollector::DEFAULT_TAGS, 'INDI')['counts'],
    XrefsService::emptyCounts()
);
check(
    'F7 NOTE with content before the pointer',
    XrefsService::classifyGedcomText("0 @I1@ INDI\n1 NOTE see @N5@", TextTagCollector::DEFAULT_TAGS, 'INDI')['counts'],
    ['le' => 0, 'lepic' => 0, 'enhanced' => 0, 'classic' => 1, 'other' => 0]
);
check(
    'F7 NOTE with content after the pointer',
    XrefsService::classifyGedcomText("0 @I1@ INDI\n1 NOTE @N5@ and more", TextTagCollector::DEFAULT_TAGS, 'INDI')['counts'],
    ['le' => 0, 'lepic' => 0, 'enhanced' => 0, 'classic' => 1, 'other' => 0]
);
check(
    'F7 naked TEXT ref stays a link',
    XrefsService::classifyGedcomText("0 @I1@ INDI\n1 TEXT @I2@", TextTagCollector::DEFAULT_TAGS, 'INDI')['counts'],
    ['le' => 0, 'lepic' => 0, 'enhanced' => 0, 'classic' => 1, 'other' => 0]
);
check(
    'F7 naked _TODO ref stays a link',
    XrefsService::classifyGedcomText("0 @I1@ INDI\n1 _TODO @I2@", TextTagCollector::DEFAULT_TAGS, 'INDI')['counts'],
    ['le' => 0, 'lepic' => 0, 'enhanced' => 0, 'classic' => 1, 'other' => 0]
);
check(
    'F7 shared-note level-0 text is not a pointer',
    XrefsService::classifyGedcomText("0 @N1@ NOTE @I5@", TextTagCollector::DEFAULT_TAGS, 'NOTE')['counts'],
    ['le' => 0, 'lepic' => 0, 'enhanced' => 0, 'classic' => 1, 'other' => 0]
);
check(
    'F7 pointer with CONT is classified on the PHP side (known R4 quirk)',
    XrefsService::classifyGedcomText("0 @I1@ INDI\n1 NOTE @N5@\n2 CONT x", TextTagCollector::DEFAULT_TAGS, 'INDI')['counts'],
    ['le' => 0, 'lepic' => 0, 'enhanced' => 0, 'classic' => 1, 'other' => 0]
);

// ---------------------------------------------------------------------------
// F6: strict pre-filter patterns - single source for the SQL gate (live
// overview, CLI initial build) and the PHP gate (CLI incremental runs).
// Conservative regex core only: plain groups, [^\n], no (?:...).
// ---------------------------------------------------------------------------
$test_xref = '([A-Za-z]{1,3}[0-9]+)';
$base_patterns = [
    "[1-9] NOTE ([^\n]+@{$test_xref}@|@{$test_xref}@[^\n]+)",
    "[1-9] (CON[CT]|TEXT|_TODO) [^\n]*@{$test_xref}@",
    "[1-9] (NOTE|CON[CT]|TEXT|_TODO) [^\n]+\\]\\(#@",
];
check('F6 patterns without shared-note table', XrefsService::linkPrefilterPatterns($test_xref, false), $base_patterns);
// the "no cross-line" class carries a literal newline inside the bracket
// expression (dialect-proof; see linkPrefilterPatterns docblock)
$nl = "\n";
check('F6 patterns with shared-note table (5 total)', XrefsService::linkPrefilterPatterns($test_xref, true), array_merge($base_patterns, [
    "0 @" . XrefsService::RE_XREF_CLASS . "@ NOTE [^{$nl}]*@{$test_xref}@",
    "0 @" . XrefsService::RE_XREF_CLASS . "@ NOTE [^{$nl}]+\\]\\(#@",
]));

// hasLinkCandidate: the PHP twin of the SQL gate
check('F6b candidate NOTE xref', [XrefsService::hasLinkCandidate("0 @I1@ INDI\n1 NOTE see @I2@", 'INDI', $test_xref)], [true]);
check('F6b candidate naked NOTE pointer', [XrefsService::hasLinkCandidate("0 @I1@ INDI\n1 NOTE @N5@", 'INDI', $test_xref)], [false]);
check('F6b candidate naked TEXT xref', [XrefsService::hasLinkCandidate("0 @I1@ INDI\n1 TEXT @I2@", 'INDI', $test_xref)], [true]);
check('F6b candidate LE link', [XrefsService::hasLinkCandidate("0 @I1@ INDI\n1 NOTE see [x](#@I2@)", 'INDI', $test_xref)], [true]);
check('F6b content on the NEXT line does not count (no cross-line matches)', [XrefsService::hasLinkCandidate("0 @I1@ INDI\n1 NOTE @I2@\n1 TEXT x", 'INDI', $test_xref)], [false]);
check('F6b candidate shared-note level-0 text', [XrefsService::hasLinkCandidate("0 @N1@ NOTE @I5@", 'NOTE', $test_xref)], [true]);
check('F6b candidate without links', [XrefsService::hasLinkCandidate("0 @I1@ INDI\n1 NAME x", 'INDI', $test_xref)], [false]);

// ---------------------------------------------------------------------------
// inventory / count HTML helpers
// ---------------------------------------------------------------------------
check('T21 empty inventory html', [XrefsService::linkInventoryHtml([])], ['']);
check(
    'T21b count summary html',
    [XrefsService::linkCountSummary(['le' => 2, 'lepic' => 0, 'enhanced' => 1, 'classic' => 0, 'other' => 0])],
    ['<strong>3</strong> <small>le: 2 / enhanced: 1</small>']
);

echo "\n{$total} tests, {$failures} failure(s)\n";
exit($failures === 0 ? 0 : 1);
