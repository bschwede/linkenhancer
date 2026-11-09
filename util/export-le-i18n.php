#!/usr/bin/env php
<?php
// Exports the 'le' i18n array from LinkEnhancerUtils::getJsI18N() as JSON.
// Used as a pre-step for generate-link-params.mjs (Phase 2).
//
// Requires PHP 8.3+ (typed constants in LinkEnhancerUtils).
// Output: stdout (JSON)
//
// Run: php util/export-le-i18n.php > util/le-i18n-en.json

declare(strict_types=1);

namespace Fisharebest\Webtrees {
    class I18N
    {
        public static function translate(string $text, ...$args): string
        {
            return $args ? vsprintf($text, $args) : $text;
        }
    }
}

namespace Schwendinger\Webtrees\Helpers {
    class MoreI18N
    {
        public static function xlate(string $text): string
        {
            return $text;
        }
    }
}

namespace Fisharebest\Webtrees\Module {
    abstract class AbstractModule
    {
    }
}

namespace {

    // Minimal stubs for classes referenced in LinkEnhancerUtils but not needed at runtime here.
    if (!class_exists(\Fisharebest\Webtrees\Webtrees::class)) {
        eval('namespace Fisharebest\Webtrees; class Webtrees { public const VERSION = "0"; public const URL = ""; }');
    }
    if (!class_exists(\Fisharebest\Webtrees\Registry::class)) {
        eval('namespace Fisharebest\Webtrees; class Registry { public static function container() { return null; } }');
    }
    if (!class_exists(\Fisharebest\Webtrees\Session::class)) {
        eval('namespace Fisharebest\Webtrees; class Session { public static function get($n, $d = null) { return $d; } }');
    }
    if (!class_exists(\Fisharebest\Webtrees\Validator::class)) {
        eval('namespace Fisharebest\Webtrees; class Validator { public static function attributes($r) { return null; } }');
    }
    if (!interface_exists(\Psr\Http\Message\ResponseInterface::class)) {
        eval('namespace Psr\Http\Message; interface ResponseInterface {}');
    }
    if (!interface_exists(\Psr\Http\Message\ServerRequestInterface::class)) {
        eval('namespace Psr\Http\Message; interface ServerRequestInterface {}');
    }

    require __DIR__ . '/../src/LinkEnhancerUtils.php';

    $module = new class extends \Fisharebest\Webtrees\Module\AbstractModule {};

    $i18n = \Schwendinger\Webtrees\Module\LinkEnhancer\LinkEnhancerUtils::getJsI18N('le', $module);

    echo json_encode($i18n, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
}
