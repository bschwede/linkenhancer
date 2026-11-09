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

// Standalone CLI test for the pure-PHP parts of HelpMappingService
// (normalizeBaseUrl) - no webtrees bootstrap, no DB, no HTTP.
// Run: php modules_v4/linkenhancer/tests/test-help-mapping-service.php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once __DIR__ . '/../src/Services/HelpMappingService.php';

use Schwendinger\Webtrees\Module\LinkEnhancer\Services\HelpMappingService;

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

// ─── normalizeBaseUrl ───────────────────────────────────────────────────────

// B1 - empty → built-in default
check('B1 empty → built-in default', HelpMappingService::normalizeBaseUrl(''), HelpMappingService::BASE_URL);

// B2 - no trailing slash → added
check('B2 no trailing slash → added', HelpMappingService::normalizeBaseUrl('http://127.0.0.1:8080/data'), 'http://127.0.0.1:8080/data/');

// B3 - single trailing slash → kept
check('B3 single trailing slash kept', HelpMappingService::normalizeBaseUrl('http://127.0.0.1:8080/data/'), 'http://127.0.0.1:8080/data/');

// B4 - multiple trailing slashes → collapsed to one
check('B4 multiple slashes collapsed', HelpMappingService::normalizeBaseUrl('http://127.0.0.1:8080/data///'), 'http://127.0.0.1:8080/data/');

// B5 - surrounding whitespace trimmed
check('B5 whitespace trimmed', HelpMappingService::normalizeBaseUrl('  http://mock/data  '), 'http://mock/data/');

// B6 - whitespace-only → built-in default
check('B6 whitespace-only → built-in default', HelpMappingService::normalizeBaseUrl('   '), HelpMappingService::BASE_URL);

// B7 - the built-in default ends with a slash
check('B7 built-in default ends with slash', str_ends_with(HelpMappingService::BASE_URL, '/'), true);

// B8 - normalizing the built-in default is a no-op
check('B8 normalizing default is a no-op', HelpMappingService::normalizeBaseUrl(HelpMappingService::BASE_URL), HelpMappingService::BASE_URL);

// ─── BASE_URL ───────────────────────────────────────────────────────────────

// U1 - base URL points to wt-modules
check('U1 base URL is wt-modules', str_contains(HelpMappingService::BASE_URL, 'wt-modules'), true);

// U2 - base URL uses codeberg.page
check('U2 base URL is codeberg.page', str_contains(HelpMappingService::BASE_URL, 'codeberg.page'), true);

// ─── Summary ────────────────────────────────────────────────────────────────

echo "\n{$total} tests, {$failures} failure(s)\n";
exit($failures > 0 ? 1 : 0);
