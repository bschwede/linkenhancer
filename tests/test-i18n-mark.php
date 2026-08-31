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

// Standalone test for the linkenhancer i18n pipeline:
//   a) MoreI18N::translate() is a pure identity (extraction marker only),
//   b) MoreI18N::xlate*/() delegate to the matching Fisharebest\Webtrees\I18N
//      method (same runtime behaviour, different name -> invisible to xgettext),
//   c) NO "I18N: webtrees.pot" comment marker remains in the module code
//      (src/ + resources/): those strings are now masked via MoreI18N::xlate(),
//      so they must never be re-extracted into the module POT.
// No webtrees/DB needed; the Fisharebest\Webtrees\I18N below is a stub that
// stands in for the real class so delegation can be counted.
//
// Run: php modules_v4/linkenhancer/tests/test-i18n-mark.php

namespace Fisharebest\Webtrees {
    class I18N {
        public static int $calls = 0;

        public static function translate(string $message, ...$args): string {
            self::$calls++;
            return 'TR:' . $message;
        }

        public static function translateContext(string $context, string $message, ...$args): string {
            self::$calls++;
            return 'TRC:[' . $context . ']' . $message;
        }

        public static function plural(string $singular, string $plural, int $count, ...$args): string {
            self::$calls++;
            return 'TRP:' . $singular;
        }
    }
}

namespace {

    require __DIR__ . '/../src/MoreI18N.php';

    use Schwendinger\Webtrees\Module\LinkEnhancer\MoreI18N;

    $failures = 0;

    function check(string $name, bool $cond): void {
        global $failures;
        if ($cond) {
            echo "ok   - {$name}\n";
        } else {
            $failures++;
            echo "FAIL - {$name}\n";
        }
    }

    // 1. MoreI18N::translate() is a pure identity (extraction marker, no I18N run)
    \Fisharebest\Webtrees\I18N::$calls = 0;
    check('marker identity (plain)', MoreI18N::translate('Update the link index') === 'Update the link index');
    check('marker identity (unicode)', MoreI18N::translate('Überprüfung äöü') === 'Überprüfung äöü');
    check('marker identity (percent)', MoreI18N::translate('100%') === '100%');
    check('marker identity leaves I18N untouched', \Fisharebest\Webtrees\I18N::$calls === 0);

    // 2. xlate*() delegate to the matching I18N method (runtime behaviour kept)
    \Fisharebest\Webtrees\I18N::$calls = 0;
    check('xlate -> I18N::translate', MoreI18N::xlate('Help') === 'TR:Help' && \Fisharebest\Webtrees\I18N::$calls === 1);
    \Fisharebest\Webtrees\I18N::$calls = 0;
    check('xlate forwards args', MoreI18N::xlate('The module “%s” has been disabled.', 'LinkEnhancer') === 'TR:The module “%s” has been disabled.' && \Fisharebest\Webtrees\I18N::$calls === 1);
    \Fisharebest\Webtrees\I18N::$calls = 0;
    check('xlateContext -> I18N::translateContext', MoreI18N::xlateContext('ctx', 'yes') === 'TRC:[ctx]yes' && \Fisharebest\Webtrees\I18N::$calls === 1);
    \Fisharebest\Webtrees\I18N::$calls = 0;
    check('xlatePlural -> I18N::plural', MoreI18N::xlatePlural('link', 'links', 2) === 'TRP:link' && \Fisharebest\Webtrees\I18N::$calls === 1);

    // 3. No "I18N: webtrees.pot" comment marker remains in the module code.
    //    Those strings are masked via MoreI18N::xlate() now (invisible to
    //    xgettext), so the old comment+awk scheme must be gone for good.
    $marker_files = [];
    foreach (['src', 'resources'] as $sub) {
        $base = __DIR__ . '/../' . $sub;
        if (!is_dir($base)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && in_array($f->getExtension(), ['php', 'phtml'], true)) {
                $c = file_get_contents($f->getPathname());
                if ($c !== false && strpos($c, 'I18N: webtrees.pot') !== false) {
                    $marker_files[] = $f->getPathname();
                }
            }
        }
    }
    if ($marker_files !== []) {
        foreach ($marker_files as $mf) {
            echo "  stray marker in {$mf}\n";
        }
    }
    check('no "I18N: webtrees.pot" marker in src/ + resources/ code', $marker_files === []);

    if ($failures > 0) {
        echo "\n{$failures} test(s) FAILED\n";
        exit(1);
    }
    echo "\nAll i18n-mark tests passed.\n";
    exit(0);
}
