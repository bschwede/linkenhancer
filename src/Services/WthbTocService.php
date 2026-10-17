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

namespace Schwendinger\Webtrees\Module\LinkEnhancer\Services;

use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Module\ModuleLanguageInterface;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\ModuleService;
use Fisharebest\Webtrees\Webtrees;
use Schwendinger\Webtrees\Module\LinkEnhancer\LinkEnhancerModule;

use function array_diff;
use function array_filter;
use function array_intersect;
use function array_keys;
use function array_map;
use function array_unique;
use function array_values;
use function count;
use function dirname;
use function explode;
use function file_get_contents;
use function filemtime;
use function glob;
use function is_dir;
use function is_file;
use function json_decode;
use function mkdir;
use function strtolower;
use function substr;
use function time;
use function trim;

/**
 * WTHB TOC multilingual loader — two-layer cache, download, validation,
 * and language scope resolution.
 *
 * Layer 1: Registry::cache()->file() (data/cache/, TTL 24h)
 * Layer 2: data/linkenhancer/wthb-toc-lang/ (persistent, offline fallback)
 * Download: GitHub Pages (only when Layer 2 > 24h old)
 */
final class WthbTocService
{
    public const BASE_URL = 'https://bschwede.github.io/linkenhancer-wthb-toc-lang/';

    private const DATA_SUBDIR = 'linkenhancer/wthb-toc-lang/';
    private const CACHE_TTL   = 86400;

    // ─── Public API (Runtime) ───────────────────────────────────────────────

    /**
     * Load the TOC structure.
     *
     * @return array{version?: string, source?: string, items: array<int, array{num: string, title_de: string, href: string, prefix?: string, prefix_tmpl?: string|null, prefix_arg?: string|null}>}
     */
    public function loadStructure(): array
    {
        return Registry::cache()->file()->remember(
            'wthb-toc-structure',
            fn (): array => $this->fetchJson('wthb-toc.json'),
            self::CACHE_TTL
        );
    }

    /**
     * Load translated TOC titles for the given (or current) language.
     * Fallback chain: requested → en → [] (original used in view).
     *
     * @return array<string, string>
     */
    public function loadTranslations(?string $lang = null): array
    {
        $lang ??= substr(I18N::languageTag(), 0, 2);
        if ($lang === 'de') {
            return [];
        }

        $en = $this->loadLang('en');
        if ($lang === 'en') {
            return $en;
        }

        $requested = $this->loadLang($lang);
        return $requested + $en;
    }

    /**
     * Available languages from languages.json.
     *
     * @return array{default: string, fallback: string, languages: list<string>}
     */
    public function availableLanguages(): array
    {
        $data = $this->fetchJson('languages.json');

        return [
            'default'   => $data['default'] ?? 'de',
            'fallback'  => $data['fallback'] ?? 'en',
            'languages' => $data['languages'] ?? ['de'],
        ];
    }

    /**
     * All available languages except the default.
     *
     * @return list<string>
     */
    public function allNonDefaultLanguages(): array
    {
        $info = $this->availableLanguages();

        return array_values(array_diff($info['languages'], [$info['default']]));
    }

    /**
     * All active webtrees language codes (2-letter, unique, lowercase).
     *
     * @return list<string>
     */
    public function activeWebtreesLanguages(): array
    {
        try {
            $module_service = Registry::container()->get(ModuleService::class);

            return $module_service->findByInterface(ModuleLanguageInterface::class, true)
                ->map(fn (ModuleLanguageInterface $m): string => strtolower(substr($m->locale()->languageTag(), 0, 2)))
                ->unique()
                ->values()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Resolve the final language list based on module pref + optional CLI override.
     *
     * @param string|null $override 'all' | comma-separated list | null (use pref)
     *
     * @return list<string>
     */
    public function resolveLanguages(?string $override = null): array
    {
        $trimMap = static fn (array $parts): array => array_values(array_filter(array_map(static fn (string $s): string => trim($s), $parts)));

        if ($override !== null) {
            return $override === 'all'
                ? $this->allNonDefaultLanguages()
                : $trimMap(explode(',', $override));
        }

        $scope     = $this->readPref(LinkEnhancerModule::PREF_WTHB_TOC_DL_SCOPE, 'all');
        $available = $this->allNonDefaultLanguages();

        if ($scope === 'active') {
            return array_values(array_intersect($this->activeWebtreesLanguages(), $available));
        }
        if ($scope === 'custom') {
            return $trimMap(explode(',', $this->readPref(LinkEnhancerModule::PREF_WTHB_TOC_DL_LANGS, '')));
        }

        return $available;
    }

    // ─── Public API (CLI) ───────────────────────────────────────────────────

    /**
     * Forced download (primary update path).
     *
     * @param list<string>|null $langs null = all from languages.json
     *
     * @return array<string, int> filename → item count
     */
    public function refresh(?array $langs = null): array
    {
        $this->clearCache();
        $results = [];

        $structure = $this->download('wthb-toc.json');
        $results['wthb-toc.json'] = count($structure['items'] ?? []);

        $this->download('languages.json');

        if ($langs === null) {
            $info  = $this->availableLanguages();
            $langs = array_values(array_diff($info['languages'], [$info['default']]));
        }
        foreach ($langs as $lang) {
            if ($lang === 'de') {
                continue;
            }
            $data = $this->download($lang . '.json');
            $results[$lang . '.json'] = count($data);
        }

        return $results;
    }

    /**
     * Validation: schema check + coverage report.
     *
     * @return array<string, mixed>
     */
    public function validate(): array
    {
        $report    = [];
        $structure = $this->localJson('wthb-toc.json');
        $report['structure'] = [
            'items'   => count($structure['items'] ?? []),
            'version' => $structure['version'] ?? 'missing',
            'valid'   => !empty($structure['items']),
        ];

        $info         = $this->availableLanguages();
        $total_titles = count($this->uniqueTitles());

        $report['languages'] = [];
        foreach (array_values(array_diff($info['languages'], [$info['default']])) as $lang) {
            $data       = $this->localJson($lang . '.json');
            $translated = count(array_intersect(array_keys($data), $this->uniqueTitles()));
            $report['languages'][$lang] = [
                'translated' => $translated,
                'total'      => $total_titles,
                'pct'        => $total_titles > 0 ? (int) round(100 * $translated / $total_titles) : 0,
            ];
        }

        $version = $this->localJson('version.json');
        $report['version'] = [
            'hash'    => $version['hash'] ?? 'missing',
            'updated' => $version['updated'] ?? 'missing',
        ];

        return $report;
    }

    /**
     * Clear all cache layers (Registry + data/).
     */
    public function clearCache(): void
    {
        Registry::cache()->file()->forget('wthb-toc-structure');

        $info = $this->availableLanguages();
        foreach ($info['languages'] as $lang) {
            Registry::cache()->file()->forget('wthb-toc-lang-' . $lang);
        }

        $dir = Webtrees::DATA_DIR . self::DATA_SUBDIR;
        if (is_dir($dir)) {
            foreach (glob($dir . '/*.json') ?: [] as $file) {
                @unlink($file);
            }
        }
    }

    // ─── Intern ─────────────────────────────────────────────────────────────

    /**
     * Load a language file (with two-layer cache).
     *
     * @return array<string, string>
     */
    private function loadLang(string $lang): array
    {
        return Registry::cache()->file()->remember(
            'wthb-toc-lang-' . $lang,
            fn (): array => $this->fetchJson($lang . '.json'),
            self::CACHE_TTL
        );
    }

    /**
     * Two-layer cache: Registry (TTL) + data/ (persistent).
     *
     * @return array<string, mixed>
     */
    private function fetchJson(string $filename): array
    {
        $local = Webtrees::DATA_DIR . self::DATA_SUBDIR . $filename;

        if (is_file($local) && (time() - (int) filemtime($local)) < self::CACHE_TTL) {
            return json_decode((string) file_get_contents($local), true) ?? [];
        }

        $data = $this->download($filename);

        if ($data === [] && is_file($local)) {
            return json_decode((string) file_get_contents($local), true) ?? [];
        }

        return $data;
    }

    /**
     * Download from GitHub Pages → data/ (persisted).
     *
     * @return array<string, mixed>
     */
    private function download(string $filename): array
    {
        $url = self::BASE_URL . $filename;
        $ctx = stream_context_create([
            'http' => ['timeout' => 10, 'header' => "User-Agent: webtrees-linkenhancer/1.x\n"],
        ]);
        $json = @file_get_contents($url, false, $ctx);

        if ($json !== false && $json !== '') {
            $local = Webtrees::DATA_DIR . self::DATA_SUBDIR . $filename;
            $dir   = dirname($local);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($local, $json);

            return json_decode($json, true) ?? [];
        }

        $local = Webtrees::DATA_DIR . self::DATA_SUBDIR . $filename;
        if (is_file($local)) {
            return json_decode((string) file_get_contents($local), true) ?? [];
        }

        return [];
    }

    /**
     * Read a local file only (no download) — for CLI validation.
     *
     * @return array<string, mixed>
     */
    private function localJson(string $filename): array
    {
        $local = Webtrees::DATA_DIR . self::DATA_SUBDIR . $filename;
        if (!is_file($local)) {
            return [];
        }

        return json_decode((string) file_get_contents($local), true) ?? [];
    }

    /**
     * All unique titles from the structure.
     *
     * @return list<string>
     */
    private function uniqueTitles(): array
    {
        $structure = $this->localJson('wthb-toc.json');
        $titles    = [];
        foreach ($structure['items'] ?? [] as $item) {
            $titles[$item['title_de']] = true;
        }

        return array_keys($titles);
    }

    /**
     * Read a module preference (works in web + CLI context after boot).
     */
    private function readPref(string $key, string $default): string
    {
        try {
            $module = Registry::container()->get(LinkEnhancerModule::class);
            $value  = $module->getPref($key, true);

            return ($value !== '' && $value !== null) ? (string) $value : $default;
        } catch (\Throwable) {
            return $default;
        }
    }
}
