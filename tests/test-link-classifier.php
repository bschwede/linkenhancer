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

namespace Fisharebest\Webtrees {
    // Stand-in for the core I18N class so the inventory/count helpers (which
    // call I18N::translate for class labels + the "target not found" /
    // "expected %1$s, is %2$s" hints) run in this standalone, bootstrap-free
    // test. Identity when no args; vsprintf when args are passed (the real
    // I18N::translate() applies sprintf to its result).
    class I18N {
        public static function translate(string $message, ...$args): string {
            return $args ? vsprintf($message, $args) : $message;
        }
    }
}

namespace {

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
        'expected' => [['ext', '[I1](#@I2@)']],
    ],
    'T2 long LE link' => [
        'text'     => '[LangerTextHier](#@I2@)',
        'expected' => [['ext', '[LangerTextHier](#@I2@)']],
    ],
    'T3 pic link' => [
        'text'     => '![Bild](#@M1@)',
        'expected' => [['pic', '![Bild](#@M1@)']],
    ],
    'T4 classic xref' => [
        'text'     => 'sah @I1@ heute',
        'expected' => [['classic', '@I1@']],
    ],
    // T5 - classic xref directly before an LE link: both counted, old regex
    // swallowed the link into two "classic" matches
    'T5 classic xref adjacent to LE link' => [
        'text'     => '@I1@[x](#@I2@)',
        'expected' => [['classic', '@I1@'], ['ext', '[x](#@I2@)']],
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
        'expected' => [['ext', '[see @I1@](#@I2@)']],
    ],
    // T9 - xref: wt= parameter, target xref not counted additionally
    'T9 xref link (wt= first parameter)' => [
        'text'     => '[x](#@wt=i@I2@tree1)',
        'expected' => [['xref', '[x](#@wt=i@I2@tree1)']],
    ],
    // T10 - xref: wt= does not have to be the first parameter
    'T10 xref link (wt= not first)' => [
        'text'     => '[x](#@fsft=123&wt=s@R1@)',
        'expected' => [['xref', '[x](#@fsft=123&wt=s@R1@)']],
    ],
    // T11 - xref: wt= may occur multiple times per link - still ONE link
    'T11 xref link with multiple wt= parameters' => [
        'text'     => '[x](#@wt=i@I1@t&wt=n@N1@)',
        'expected' => [['xref', '[x](#@wt=i@I1@t&wt=n@N1@)']],
    ],
    // T12 - external target without wt= stays a plain LE link
    'T12 external target only' => [
        'text'     => '[x](#@wp=de/Artikel)',
        'expected' => [['ext', '[x](#@wp=de/Artikel)']],
    ],
    'T13 xref pic link' => [
        'text'     => '![p](#@wt=n@N1@)',
        'expected' => [['xref', '![p](#@wt=n@N1@)']],
    ],
    // T14 - mixed sentence, offset order
    'T14 mixed sentence' => [
        'text'     => 'Text @I1@ und [I2](#@I3@) Ende',
        'expected' => [['classic', '@I1@'], ['ext', '[I2](#@I3@)']],
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
// T18c - a multi-byte char at the window edge must not be split. 'Ü' (2 bytes)
// is positioned so the window start (byte offset - 10) lands on its second
// byte; the old byte-based substr orphaned that byte and it rendered as "?".
$text = 'Üaaaaaaaaa@X1@1234567890';
check(
    'T18c snippet does not split a multi-byte char at the window start',
    [XrefsService::classifyTextLinks($text)[0]['snippet']],
    ['Üaaaaaaaaa@X1@1234567890']
);

// ---------------------------------------------------------------------------
// extractLinkTargets (backlink foundation)
// ---------------------------------------------------------------------------
$target_fixtures = [
    'T19 classic xref'                     => ['@I1@',                    [['xref' => 'I1', 'tree' => null, 'type' => null]]],
    'T19b classic xref with dot'           => ['@I1.2@',                  [['xref' => 'I1.2', 'tree' => null, 'type' => null]]],
    'T19c LE link without wt='             => ['[x](#@I2@)',              []],
    'T19d LE link external target'         => ['[x](#@wp=de/Artikel)',    []],
    'T19e xref single target'          => ['[x](#@wt=i@I2@tree1)',    [['xref' => 'I2', 'tree' => 'tree1', 'type' => 'i']]],
    'T19f xref without type letter'    => ['[x](#@wt=@I2@)',          [['xref' => 'I2', 'tree' => null, 'type' => null]]],
    'T19g xref without tree'           => ['[x](#@wt=i@I2@)',         [['xref' => 'I2', 'tree' => null, 'type' => 'i']]],
    'T19h xref multiple targets'       => ['[x](#@wt=i@I1@t&wt=n@N1@)', [['xref' => 'I1', 'tree' => 't', 'type' => 'i'], ['xref' => 'N1', 'tree' => null, 'type' => 'n']]],
    'T19i xref wt= not first'          => ['[x](#@fsft=1&wt=s@R1@)',  [['xref' => 'R1', 'tree' => null, 'type' => 's']]],
    'T19j xref pic link'               => ['![p](#@wt=n@N1@)',        [['xref' => 'N1', 'tree' => null, 'type' => 'n']]],
    'T19k defective LE remainder'          => ['](#@I2@',                 []],
    'T31 id= single target'                => ['[x](#@id=@I1@)',          [['xref' => 'I1', 'tree' => null, 'type' => null]]],
    'T32 id= not first parameter'          => ['[x](#@a=1&id=@I1@&b=2)',  [['xref' => 'I1', 'tree' => null, 'type' => null]]],
    'T33 id= combined with wt='            => ['[x](#@wt=i@I2@&id=@M1@)', [['xref' => 'I2', 'tree' => null, 'type' => 'i'], ['xref' => 'M1', 'tree' => null, 'type' => null]]],
    'T34 double id= (spec violation)'      => ['[x](#@id=@I1@&id=@I2@)',  [['xref' => 'I1', 'tree' => null, 'type' => null]]],
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
    ['ext' => 0, 'pic' => 0, 'xref' => 1, 'classic' => 2, 'other' => 0]
);
check(
    'T20b classifyGedcomText entries',
    array_map(static fn (array $e): array => [$e['path'], $e['class'], $e['token']], $inventory['entries']),
    [
        ['INDI:NOTE', 'xref', '[I2](#@wt=i@I3@tree1)'],
        ['INDI:NOTE', 'classic', '@I4@'],
        ['INDI:NOTE', 'classic', '@I5@'],
    ]
);
check('T20c classifyGedcomText without links', XrefsService::classifyGedcomText("0 @I1@ INDI\n1 NAME x")['counts'], XrefsService::emptyCounts());
check(
    'T30 pic link with id= parameter is classified as pic',
    XrefsService::classifyGedcomText("0 @I1@ INDI\n1 NOTE see ![pic](#@id=@M1@)", TextTagCollector::DEFAULT_TAGS, 'INDI')['counts'],
    ['ext' => 0, 'pic' => 1, 'xref' => 0, 'classic' => 0, 'other' => 0]
);

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
    ['ext' => 0, 'pic' => 0, 'xref' => 0, 'classic' => 1, 'other' => 0]
);
check(
    'F7 NOTE with content after the pointer',
    XrefsService::classifyGedcomText("0 @I1@ INDI\n1 NOTE @N5@ and more", TextTagCollector::DEFAULT_TAGS, 'INDI')['counts'],
    ['ext' => 0, 'pic' => 0, 'xref' => 0, 'classic' => 1, 'other' => 0]
);
check(
    'F7 naked TEXT ref stays a link',
    XrefsService::classifyGedcomText("0 @I1@ INDI\n1 TEXT @I2@", TextTagCollector::DEFAULT_TAGS, 'INDI')['counts'],
    ['ext' => 0, 'pic' => 0, 'xref' => 0, 'classic' => 1, 'other' => 0]
);
check(
    'F7 naked _TODO ref stays a link',
    XrefsService::classifyGedcomText("0 @I1@ INDI\n1 _TODO @I2@", TextTagCollector::DEFAULT_TAGS, 'INDI')['counts'],
    ['ext' => 0, 'pic' => 0, 'xref' => 0, 'classic' => 1, 'other' => 0]
);
check(
    'F7 shared-note level-0 text is not a pointer',
    XrefsService::classifyGedcomText("0 @N1@ NOTE @I5@", TextTagCollector::DEFAULT_TAGS, 'NOTE')['counts'],
    ['ext' => 0, 'pic' => 0, 'xref' => 0, 'classic' => 1, 'other' => 0]
);
check(
    'F7 pointer with CONT is classified on the PHP side (known R4 quirk)',
    XrefsService::classifyGedcomText("0 @I1@ INDI\n1 NOTE @N5@\n2 CONT x", TextTagCollector::DEFAULT_TAGS, 'INDI')['counts'],
    ['ext' => 0, 'pic' => 0, 'xref' => 0, 'classic' => 1, 'other' => 0]
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
    [XrefsService::linkCountSummary(['ext' => 2, 'pic' => 0, 'xref' => 1, 'classic' => 0, 'other' => 0])],
    ['<strong>3</strong><div class="text-muted"><small>ext: 2<br/>xref: 1</small></div>']
);

// max_links (inventory cap) - semantics: >0 = cap + overflow counter, <=0 = all
$inv_entries = [];
for ($i = 1; $i <= 6; $i++) {
    $inv_entries[] = ['path' => '', 'class' => 'ext', 'token' => '@I' . $i . '@', 'snippet' => 'sn' . $i];
}
$inv_two = array_slice($inv_entries, 0, 2);
check(
    'T23 inventory cap 0 shows all tokens, no overflow',
    [XrefsService::linkInventoryHtml($inv_two, 0)],
    ['<ul class="le-xref-inventory"><li><u>External links (ext: 2)</u><ol><li><code>sn1</code></li><li><code>sn2</code></li></ol></li></ul>']
);
check(
    'T23b inventory negative cap shows all tokens, no overflow',
    [XrefsService::linkInventoryHtml($inv_two, -1)],
    ['<ul class="le-xref-inventory"><li><u>External links (ext: 2)</u><ol><li><code>sn1</code></li><li><code>sn2</code></li></ol></li></ul>']
);
check(
    'T24 inventory cap 1 keeps one token + overflow counter',
    [XrefsService::linkInventoryHtml($inv_two, 1)],
    ['<ul class="le-xref-inventory"><li><u>External links (ext: 2)</u><ol><li><code>sn1</code></li></ol><em>+1</em></li></ul>']
);
check(
    'T25 inventory default cap is 5 (6 tokens -> 5 + "+1")',
    [XrefsService::linkInventoryHtml($inv_entries)],
    ['<ul class="le-xref-inventory"><li><u>External links (ext: 6)</u><ol><li><code>sn1</code></li><li><code>sn2</code></li><li><code>sn3</code></li><li><code>sn4</code></li><li><code>sn5</code></li></ol><em>+1</em></li></ul>']
);
// token highlighting: the token is wrapped in <strong> inside the snippet,
// the surrounding context stays escaped
check(
    'T35 inventory highlights the token in the snippet',
    [XrefsService::linkInventoryHtml([['path' => '', 'class' => 'ext', 'token' => '[x](#@I2@)', 'snippet' => 'see <b>[x](#@I2@)</b> ok']], 0)],
    ['<ul class="le-xref-inventory"><li><u>External links (ext: 1)</u><ol><li><code>see &lt;b&gt;<strong>[x](#@I2@)</strong>&lt;/b&gt; ok</code></li></ol></li></ul>']
);
check(
    'T35b inventory token fallback (snippet = token)',
    [XrefsService::linkInventoryHtml([['path' => '', 'class' => 'pic', 'token' => '![p](#@M1@)', 'snippet' => '![p](#@M1@)']], 0)],
    ['<ul class="le-xref-inventory"><li><u>Pictures (pic: 1)</u><ol><li><code><strong>![p](#@M1@)</strong></code></li></ol></li></ul>']
);
// T36 - with an active "referencing XREF" filter (3rd arg), the XREF is
// additionally wrapped in <mark> inside the token, on top of <strong>
check(
    'T36 inventory marks the referencing XREF when the filter is active',
    [XrefsService::linkInventoryHtml([['path' => '', 'class' => 'classic', 'token' => '@I1@', 'snippet' => 'sah @I1@ heute']], 0, 'I1')],
    ['<ul class="le-xref-inventory"><li><u>Classic cross-references (classic: 1)</u><ol><li><code>sah <strong>@<mark class="le-xref-target">I1</mark>@</strong> heute</code></li></ol></li></ul>']
);
// T36b - the mark is boundary-aware: filter "I1" must not match inside "I12"
check(
    'T36b inventory XREF mark is boundary-aware (I1 does not match I12)',
    [XrefsService::linkInventoryHtml([['path' => '', 'class' => 'classic', 'token' => '@I12@', 'snippet' => 'sah @I12@ heute']], 0, 'I1')],
    ['<ul class="le-xref-inventory"><li><u>Classic cross-references (classic: 1)</u><ol><li><code>sah <strong>@I12@</strong> heute</code></li></ol></li></ul>']
);
// T36c - empty filter (default) = no <mark>, identical to the pre-change output
check(
    'T36c inventory no XREF mark when the filter is empty (default)',
    [XrefsService::linkInventoryHtml([['path' => '', 'class' => 'ext', 'token' => '[x](#@I2@)', 'snippet' => 'see <b>[x](#@I2@)</b> ok']], 0)],
    ['<ul class="le-xref-inventory"><li><u>External links (ext: 1)</u><ol><li><code>see &lt;b&gt;<strong>[x](#@I2@)</strong>&lt;/b&gt; ok</code></li></ol></li></ul>']
);
// T36d - the XREF is marked everywhere it occurs: in the token AND in the
// surrounding snippet context
check(
    'T36d inventory marks the XREF in snippet context too (outside the token)',
    [XrefsService::linkInventoryHtml([['path' => '', 'class' => 'classic', 'token' => '@I1@', 'snippet' => 'von I1 zu @I1@']], 0, 'I1')],
    ['<ul class="le-xref-inventory"><li><u>Classic cross-references (classic: 1)</u><ol><li><code>von <mark class="le-xref-target">I1</mark> zu <strong>@<mark class="le-xref-target">I1</mark>@</strong></code></li></ol></li></ul>']
);
check(
    'T26 normalizeLinksPerClass allowlist passthrough (0,5,10,20)',
    [
        XrefsService::normalizeLinksPerClass(0),
        XrefsService::normalizeLinksPerClass(5),
        XrefsService::normalizeLinksPerClass(10),
        XrefsService::normalizeLinksPerClass(20),
    ],
    [0, 5, 10, 20]
);
check(
    'T26b normalizeLinksPerClass out-of-range falls back to default',
    [
        XrefsService::normalizeLinksPerClass(3),
        XrefsService::normalizeLinksPerClass(-2),
        XrefsService::normalizeLinksPerClass(1000000),
    ],
    [5, 5, 5]
);

// T37 - reference links below the snippet for xref/classic/pic tokens (4th
// arg = the target resolver, fn($xref, ?$target_tree) => ?{name,url,tree_label,actual}).
$target_linker = static function (string $xref, ?string $tree): ?array {
    $known = [
        'I2' => ['name' => 'Max Mustermann', 'url' => '/tree/t/individual/I2', 'tree_label' => '', 'actual' => 'INDI'],
        'F3' => ['name' => 'Fam XY', 'url' => '/tree/t/family/F3', 'tree_label' => '', 'actual' => 'FAM'],
        'I5' => ['name' => 'Cross Person', 'url' => '/tree/t2/individual/I5', 'tree_label' => 'other-tree', 'actual' => 'INDI'],
        'I9' => ['name' => 'Max', 'url' => '/tree/t/individual/I9', 'tree_label' => '', 'actual' => 'FAM'],
        'M1' => ['name' => 'Foto', 'url' => '/tree/t/media/M1', 'tree_label' => '', 'actual' => 'OBJE'],
    ];
    return $known[$xref] ?? null;
};
check(
    'T37 inventory shows a reference link for an xref token (same tree)',
    [XrefsService::linkInventoryHtml([['path' => '', 'class' => 'xref', 'token' => '[see](#@wt=i@I2@)', 'snippet' => '[see](#@wt=i@I2@)']], 0, '', $target_linker)],
    ['<ul class="le-xref-inventory"><li><u>Cross-references (xref: 1)</u><ol><li><code><strong>[see](#@wt=i@I2@)</strong></code><div class="le-target-links"><span class="le-cross-ref" title="Cross-reference">↪</span> <a href="/tree/t/individual/I2">Max Mustermann</a></div></li></ol></li></ul>']
);
check(
    'T37b inventory shows all targets of a multi-target LE token',
    [XrefsService::linkInventoryHtml([['path' => '', 'class' => 'xref', 'token' => '[see](#@wt=i@I2@&wt=f@F3@)', 'snippet' => '[see](#@wt=i@I2@&wt=f@F3@)']], 0, '', $target_linker)],
    ['<ul class="le-xref-inventory"><li><u>Cross-references (xref: 1)</u><ol><li><code><strong>[see](#@wt=i@I2@&amp;wt=f@F3@)</strong></code><div class="le-target-links"><span class="le-cross-ref" title="Cross-reference">↪</span> <a href="/tree/t/individual/I2">Max Mustermann</a><br><span class="le-cross-ref" title="Cross-reference">↪</span> <a href="/tree/t/family/F3">Fam XY</a></div></li></ol></li></ul>']
);
check(
    'T37c inventory shows a reference link for a classic xref token',
    [XrefsService::linkInventoryHtml([['path' => '', 'class' => 'classic', 'token' => '@I2@', 'snippet' => 'sah @I2@']], 0, '', $target_linker)],
    ['<ul class="le-xref-inventory"><li><u>Classic cross-references (classic: 1)</u><ol><li><code>sah <strong>@I2@</strong></code><div class="le-target-links"><span class="le-cross-ref" title="Cross-reference">↪</span> <a href="/tree/t/individual/I2">Max Mustermann</a></div></li></ol></li></ul>']
);
check(
    'T37d inventory shows no reference link for a non-xref/classic class (ext)',
    [XrefsService::linkInventoryHtml([['path' => '', 'class' => 'ext', 'token' => '[x](#@I2@)', 'snippet' => '[x](#@I2@)']], 0, '', $target_linker)],
    ['<ul class="le-xref-inventory"><li><u>External links (ext: 1)</u><ol><li><code><strong>[x](#@I2@)</strong></code></li></ol></li></ul>']
);
check(
    'T37e inventory marks an unresolvable target with a findable broken marker',
    [XrefsService::linkInventoryHtml([['path' => '', 'class' => 'xref', 'token' => '[see](#@wt=i@I404@)', 'snippet' => '[see](#@wt=i@I404@)']], 0, '', $target_linker)],
    ['<ul class="le-xref-inventory"><li><u>Cross-references (xref: 1)</u><ol><li><code><strong>[see](#@wt=i@I404@)</strong></code><div class="le-target-links"><span class="le-target-missing" title="target not found">✗ @I404@</span></div></li></ol></li></ul>']
);
check(
    'T37f inventory prefixes the tree name for a cross-tree target',
    [XrefsService::linkInventoryHtml([['path' => '', 'class' => 'xref', 'token' => '[see](#@wt=i@I5@other-tree)', 'snippet' => '[see](#@wt=i@I5@other-tree)']], 0, '', $target_linker)],
    ['<ul class="le-xref-inventory"><li><u>Cross-references (xref: 1)</u><ol><li><code><strong>[see](#@wt=i@I5@other-tree)</strong></code><div class="le-target-links"><span class="le-cross-ref" title="Cross-reference">↪</span> <a href="/tree/t2/individual/I5">other-tree: Cross Person</a></div></li></ol></li></ul>']
);
check(
    'T37g inventory no reference links when the linker is null (default)',
    [XrefsService::linkInventoryHtml([['path' => '', 'class' => 'xref', 'token' => '[see](#@wt=i@I2@)', 'snippet' => '[see](#@wt=i@I2@)']], 0)],
    ['<ul class="le-xref-inventory"><li><u>Cross-references (xref: 1)</u><ol><li><code><strong>[see](#@wt=i@I2@)</strong></code></li></ol></li></ul>']
);
// T38 - pic links (id=@XREF@ media) also get a reference link; a media target
// (actual=OBJE) matches the expected OBJE, so no type hint.
check(
    'T38 inventory shows a reference link for a pic (media) token',
    [XrefsService::linkInventoryHtml([['path' => '', 'class' => 'pic', 'token' => '![p](#@id=@M1@)', 'snippet' => '![p](#@id=@M1@)']], 0, '', $target_linker)],
    ['<ul class="le-xref-inventory"><li><u>Pictures (pic: 1)</u><ol><li><code><strong>![p](#@id=@M1@)</strong></code><div class="le-target-links"><span class="le-cross-ref" title="Cross-reference">↪</span> <a href="/tree/t/media/M1">Foto</a></div></li></ol></li></ul>']
);
// T39 - xref type mismatch: wt=i (expected INDI) but the record is a FAM ->
// the link stays, plus a findable "⚠" hint with a tooltip.
check(
    'T39 inventory keeps the link and adds a hint on a wt= type mismatch',
    [XrefsService::linkInventoryHtml([['path' => '', 'class' => 'xref', 'token' => '[see](#@wt=i@I9@)', 'snippet' => '[see](#@wt=i@I9@)']], 0, '', $target_linker)],
    ['<ul class="le-xref-inventory"><li><u>Cross-references (xref: 1)</u><ol><li><code><strong>[see](#@wt=i@I9@)</strong></code><div class="le-target-links"><span class="le-cross-ref" title="Cross-reference">↪</span> <a href="/tree/t/individual/I9">Max</a> <span class="le-target-type-mismatch" title="expected INDI, is FAM">⚠</span></div></li></ol></li></ul>']
);
// T40 - xref without a type letter (wt=@XREF@) is not type-checked, even when
// the resolved record type differs.
check(
    'T40 inventory does not type-check an xref without a wt= letter',
    [XrefsService::linkInventoryHtml([['path' => '', 'class' => 'xref', 'token' => '[see](#@wt=@I2@)', 'snippet' => '[see](#@wt=@I2@)']], 0, '', $target_linker)],
    ['<ul class="le-xref-inventory"><li><u>Cross-references (xref: 1)</u><ol><li><code><strong>[see](#@wt=@I2@)</strong></code><div class="le-target-links"><span class="le-cross-ref" title="Cross-reference">↪</span> <a href="/tree/t/individual/I2">Max Mustermann</a></div></li></ol></li></ul>']
);
// T41 - pic target that is not a media (id= points to an INDI) -> mismatch
// hint (expected OBJE, is INDI), the link is still shown.
check(
    'T41 inventory hints when a pic target is not a media',
    [XrefsService::linkInventoryHtml([['path' => '', 'class' => 'pic', 'token' => '![p](#@id=@I2@)', 'snippet' => '![p](#@id=@I2@)']], 0, '', $target_linker)],
    ['<ul class="le-xref-inventory"><li><u>Pictures (pic: 1)</u><ol><li><code><strong>![p](#@id=@I2@)</strong></code><div class="le-target-links"><span class="le-cross-ref" title="Cross-reference">↪</span> <a href="/tree/t/individual/I2">Max Mustermann</a> <span class="le-target-type-mismatch" title="expected OBJE, is INDI">⚠</span></div></li></ol></li></ul>']
);
// T42 - "only broken targets" highlight: a missing target is wrapped in a
// <mark> when $highlight_problems (5th arg) is set.
check(
    'T42 mark wraps a missing target when highlight_problems is set',
    [XrefsService::linkInventoryHtml([['path' => '', 'class' => 'xref', 'token' => '[see](#@wt=i@I404@)', 'snippet' => '[see](#@wt=i@I404@)']], 0, '', $target_linker, true)],
    ['<ul class="le-xref-inventory"><li><u>Cross-references (xref: 1)</u><ol><li><code><strong>[see](#@wt=i@I404@)</strong></code><div class="le-target-links"><mark class="le-problem-mark"><span class="le-target-missing" title="target not found">✗ @I404@</span></mark></div></li></ol></li></ul>']
);
// T43 - a type-mismatch target is wrapped in a <mark> (the link stays).
check(
    'T43 mark wraps a mismatch target when highlight_problems is set',
    [XrefsService::linkInventoryHtml([['path' => '', 'class' => 'xref', 'token' => '[see](#@wt=i@I9@)', 'snippet' => '[see](#@wt=i@I9@)']], 0, '', $target_linker, true)],
    ['<ul class="le-xref-inventory"><li><u>Cross-references (xref: 1)</u><ol><li><code><strong>[see](#@wt=i@I9@)</strong></code><div class="le-target-links"><span class="le-cross-ref" title="Cross-reference">↪</span> <a href="/tree/t/individual/I9">Max</a> <mark class="le-problem-mark"><span class="le-target-type-mismatch" title="expected INDI, is FAM">⚠</span></mark></div></li></ol></li></ul>']
);
// T44 - a healthy target is NOT marked, even with highlight_problems set.
check(
    'T44 a healthy target is not marked when highlight_problems is set',
    [XrefsService::linkInventoryHtml([['path' => '', 'class' => 'xref', 'token' => '[see](#@wt=i@I2@)', 'snippet' => '[see](#@wt=i@I2@)']], 0, '', $target_linker, true)],
    ['<ul class="le-xref-inventory"><li><u>Cross-references (xref: 1)</u><ol><li><code><strong>[see](#@wt=i@I2@)</strong></code><div class="le-target-links"><span class="le-cross-ref" title="Cross-reference">↪</span> <a href="/tree/t/individual/I2">Max Mustermann</a></div></li></ol></li></ul>']
);
// T45 - inventoryHasProblem(): the "only broken targets" filter predicate.
check('T45a inventoryHasProblem true for a missing target', [XrefsService::inventoryHasProblem([['path' => '', 'class' => 'xref', 'token' => '[see](#@wt=i@I404@)', 'snippet' => '']], $target_linker)], [true]);
check('T45b inventoryHasProblem true for a type mismatch', [XrefsService::inventoryHasProblem([['path' => '', 'class' => 'xref', 'token' => '[see](#@wt=i@I9@)', 'snippet' => '']], $target_linker)], [true]);
check('T45c inventoryHasProblem false for a healthy target', [XrefsService::inventoryHasProblem([['path' => '', 'class' => 'xref', 'token' => '[see](#@wt=i@I2@)', 'snippet' => '']], $target_linker)], [false]);
check('T45d inventoryHasProblem false when no wt= letter (not type-checked)', [XrefsService::inventoryHasProblem([['path' => '', 'class' => 'xref', 'token' => '[see](#@wt=@I2@)', 'snippet' => '']], $target_linker)], [false]);
check('T45e inventoryHasProblem false for a non-xref/classic/pic class', [XrefsService::inventoryHasProblem([['path' => '', 'class' => 'ext', 'token' => '[x](#@I2@)', 'snippet' => '']], $target_linker)], [false]);
check('T45f inventoryHasProblem false when the target linker is null', [XrefsService::inventoryHasProblem([['path' => '', 'class' => 'xref', 'token' => '[see](#@wt=i@I404@)', 'snippet' => '']], null)], [false]);

// T46+: Block module support
check('T46a blockTextSettings faq returns only text settings', XrefsService::blockTextSettings('faq'), ['faqbody']);
check('T46b blockTextSettings html returns only text settings', XrefsService::blockTextSettings('html'), ['html']);
check('T46c blockTextSettings stories returns only text settings', XrefsService::blockTextSettings('stories'), ['story_body']);
check('T46d blockTextSettings vesta returns only text settings', XrefsService::blockTextSettings('_vesta_classic_look_and_feel_'), ['snippet']);
check('T46e blockTextSettings unknown module returns empty', XrefsService::blockTextSettings('nonexistent'), []);
check('T46f blockModuleNames returns all 4 modules', XrefsService::blockModuleNames(), ['html', 'faq', 'stories', '_vesta_classic_look_and_feel_']);
check('T47a BLOCK_LE_PREFILTER matches HTML href LE link (double quotes)', [preg_match('/' . XrefsService::BLOCK_LE_PREFILTER . '/', '<a href="#@wt=i@I123@">person</a>')], [1]);
check('T47b BLOCK_LE_PREFILTER matches HTML href LE link (single quotes)', [preg_match('/' . XrefsService::BLOCK_LE_PREFILTER . '/', "<a href='#@wt=f@F456'>fam</a>")], [1]);
check('T47c BLOCK_LE_PREFILTER does not match plain text', [preg_match('/' . XrefsService::BLOCK_LE_PREFILTER . '/', 'No links here at all')], [0]);
check('T47d BLOCK_LE_PREFILTER does not match classic xref', [preg_match('/' . XrefsService::BLOCK_LE_PREFILTER . '/', 'See @I42@ for info')], [0]);
check('T48a classifyHtmlLinks xref link', XrefsService::classifyHtmlLinks('<p>See <a href="#@wt=i@I123@">person</a> here</p>'), [['class' => 'xref', 'token' => '<a href="#@wt=i@I123@">person</a>', 'snippet' => '<p>See <a href="#@wt=i@I123@">person</a> here</p>']]);
check('T48b classifyHtmlLinks without LE links', XrefsService::classifyHtmlLinks('<p>Just a <strong>bold</strong> paragraph</p>'), []);
check('T48c classifyHtmlLinks ext link (no wt= param)', XrefsService::classifyHtmlLinks('<a href="#@ext_url">ext</a>'), [['class' => 'ext', 'token' => '<a href="#@ext_url">ext</a>', 'snippet' => '<a href="#@ext_url">ext</a>']]);
check('T48d classifyHtmlLinks multiple links', XrefsService::classifyHtmlLinks('<a href="#@wt=i@I1@">a</a> and <a href="#@other">b</a>'), [['class' => 'xref', 'token' => '<a href="#@wt=i@I1@">a</a>', 'snippet' => '<a href="#@wt=i@I1@">a</a> and <a hr'], ['class' => 'ext', 'token' => '<a href="#@other">b</a>', 'snippet' => 'a</a> and <a href="#@other">b</a>']]);

// T49: extractLinkTargets with HTML tokens
check('T49a extractLinkTargets HTML xref link', XrefsService::extractLinkTargets('<a href="#@wt=i@I123@">person</a>'), [['xref' => 'I123', 'tree' => null, 'type' => 'i']]);
check('T49b extractLinkTargets HTML with type letter', XrefsService::extractLinkTargets('<a href="#@wt=f@F45@&l=marriage">x</a>'), [['xref' => 'F45', 'tree' => null, 'type' => 'f']]);
check('T49c extractLinkTargets HTML ext link (no wt=)', XrefsService::extractLinkTargets('<a href="#@some_ext">x</a>'), []);
check('T49d extractLinkTargets HTML multiple wt= params', XrefsService::extractLinkTargets('<a href="#@wt=i@I1@&wt=f@F2@">x</a>'), [['xref' => 'I1', 'tree' => null, 'type' => 'i'], ['xref' => 'F2', 'tree' => null, 'type' => 'f']]);
check('T49e extractLinkTargets Markdown still works', XrefsService::extractLinkTargets('[text](#@wt=i@I99@)'), [['xref' => 'I99', 'tree' => null, 'type' => 'i']]);
check('T49f extractLinkTargets classic still works', XrefsService::extractLinkTargets('@I42@'), [['xref' => 'I42', 'tree' => null, 'type' => null]]);

// T50: record-type filter routing (all types / all GEDCOM / all Blocks / single)
check('T50a rectypeSources empty = all GEDCOM + all Blocks',
    [XrefsService::rectypeSources('')],
    [['gedcom' => true, 'blocks' => true, 'rectypes' => []]]);
check('T50b rectypeSources single GEDCOM type',
    [XrefsService::rectypeSources('INDI')],
    [['gedcom' => true, 'blocks' => false, 'rectypes' => ['INDI']]]);
check('T50c rectypeSources single block module',
    [XrefsService::rectypeSources('html')],
    [['gedcom' => false, 'blocks' => true, 'rectypes' => ['html']]]);
check('T50d rectypeSources all-GEDCOM sentinel',
    [XrefsService::rectypeSources(XrefsService::RECTYPE_ALL_GEDCOM)],
    [['gedcom' => true, 'blocks' => false, 'rectypes' => []]]);
check('T50e rectypeSources all-Blocks sentinel',
    [XrefsService::rectypeSources(XrefsService::RECTYPE_ALL_BLOCKS)],
    [['gedcom' => false, 'blocks' => true, 'rectypes' => XrefsService::blockModuleNames()]]);
// A sentinel must never match a real GEDCOM type or block module name, or the
// routing above would silently misroute that category.
check('T50f sentinels do not collide with GEDCOM record keys',
    array_values(array_intersect([XrefsService::RECTYPE_ALL_GEDCOM, XrefsService::RECTYPE_ALL_BLOCKS], XrefsService::supportedGedcomRecordKeys())),
    []);
check('T50g sentinels do not collide with block module names',
    array_values(array_intersect([XrefsService::RECTYPE_ALL_GEDCOM, XrefsService::RECTYPE_ALL_BLOCKS], XrefsService::blockModuleNames())),
    []);

echo "\n{$total} tests, {$failures} failure(s)\n";
exit($failures === 0 ? 0 : 1);
}
