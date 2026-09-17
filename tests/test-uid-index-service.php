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

// Standalone CLI test for the pure-PHP parts of UidIndexService
// (uidPrefilterPatterns / hasUidCandidate) - no webtrees bootstrap, no DB.
// Run: php modules_v4/linkenhancer/tests/test-uid-index-service.php

// Inline SAPI guard (deliberately without CliBootstrap, to stay standalone):
// modules_v4/ is inside the web root, so this file is reachable by URL.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once __DIR__ . '/../src/Services/UidIndexService.php';

use Schwendinger\Webtrees\Module\LinkEnhancer\Services\UidIndexService;

$failures = 0;
$total    = 0;

function check(string $name, bool|array $actual, bool|array $expected): void {
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

// P1 - the pre-filter pattern (single source, DB + PHP)
check('P1 pre-filter pattern', UidIndexService::uidPrefilterPatterns(), ['[1-9] _?UID ']);

// P2 - record-level _UID (v5)
check('P2 record-level _UID', UidIndexService::hasUidCandidate("0 @I1@ INDI\n1 _UID ABC", 'INDI'), true);

// P3 - record-level UID (v7, no underscore)
check('P3 record-level UID', UidIndexService::hasUidCandidate("0 @I1@ INDI\n1 UID XYZ", 'INDI'), true);

// P4 - fact-level _UID (level 2, D6)
check('P4 fact-level _UID', UidIndexService::hasUidCandidate("0 @I1@ INDI\n1 BIRT\n2 _UID BUID", 'INDI'), true);

// P5 - no UID at all
check('P5 no UID', UidIndexService::hasUidCandidate("0 @I1@ INDI\n1 NAME Max\n1 BIRT", 'INDI'), false);

// P6 - "_UID" occurring inside a note's text is NOT a candidate (anchored to level+space)
check('P6 _UID in note text', UidIndexService::hasUidCandidate("0 @I1@ INDI\n1 NOTE this mentions _UID inside", 'INDI'), false);

// P7 - a different tag "_UIDX" is NOT a candidate
check('P7 _UIDX tag', UidIndexService::hasUidCandidate("0 @I1@ INDI\n1 _UIDX ABC", 'INDI'), false);

// P8 - a tag ending in UID ("SUID") is NOT a candidate
check('P8 SUID tag', UidIndexService::hasUidCandidate("0 @I1@ INDI\n1 SUID ABC", 'INDI'), false);

echo "\n{$total} tests, {$failures} failure(s)\n";
exit($failures === 0 ? 0 : 1);
