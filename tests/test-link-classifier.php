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

require_once __DIR__ . '/../src/Services/XrefsService.php';

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

echo "\n{$total} tests, {$failures} failure(s)\n";
exit($failures === 0 ? 0 : 1);
