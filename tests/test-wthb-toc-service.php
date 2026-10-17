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

// Standalone CLI test for the pure-PHP parts of WthbTocService
// (normalizeBaseUrl) - no webtrees bootstrap, no DB, no HTTP.
// Run: php modules_v4/linkenhancer/tests/test-wthb-toc-service.php

// Inline SAPI guard (deliberately without CliBootstrap, to stay standalone):
// modules_v4/ is inside the web root, so this file is reachable by URL.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once __DIR__ . '/../src/Services/WthbTocService.php';

use Schwendinger\Webtrees\Module\LinkEnhancer\Services\WthbTocService;

$failures = 0;
$total    = 0;

function check(string $name, bool|array|string $actual, bool|array|string $expected): void {
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

// B1 - empty → built-in default
check('B1 empty → built-in default', WthbTocService::normalizeBaseUrl(''), WthbTocService::BASE_URL);

// B2 - no trailing slash → added
check('B2 no trailing slash → added', WthbTocService::normalizeBaseUrl('http://127.0.0.1:8080/toc'), 'http://127.0.0.1:8080/toc/');

// B3 - single trailing slash → kept
check('B3 single trailing slash kept', WthbTocService::normalizeBaseUrl('http://127.0.0.1:8080/toc/'), 'http://127.0.0.1:8080/toc/');

// B4 - multiple trailing slashes → collapsed to one
check('B4 multiple slashes collapsed', WthbTocService::normalizeBaseUrl('http://127.0.0.1:8080/toc///'), 'http://127.0.0.1:8080/toc/');

// B5 - surrounding whitespace trimmed
check('B5 whitespace trimmed', WthbTocService::normalizeBaseUrl('  http://mock/toc  '), 'http://mock/toc/');

// B6 - whitespace-only → built-in default
check('B6 whitespace-only → built-in default', WthbTocService::normalizeBaseUrl('   '), WthbTocService::BASE_URL);

// B7 - the built-in default itself ends with a slash (idempotent)
check('B7 built-in default ends with slash', str_ends_with(WthbTocService::BASE_URL, '/'), true);

// B8 - normalizing the built-in default is a no-op
check('B8 normalizing default is a no-op', WthbTocService::normalizeBaseUrl(WthbTocService::BASE_URL), WthbTocService::BASE_URL);

echo "\n{$total} checks, {$failures} failure(s)\n";
exit($failures === 0 ? 0 : 1);
