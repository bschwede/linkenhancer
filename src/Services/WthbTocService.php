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
use function is_array;
use function is_dir;
use function is_file;
use function json_decode;
use function json_encode;
use function mkdir;
use function strtolower;
use function substr;
use function time;
use function trim;

/**
 * WTHB TOC multilingual loader — hash-based change detection, download,
 * validation, and language scope resolution.
 *
 * Change detection: version.json (per-file hashes) is re-fetched at most once per
 * VERSION_TTL; a data file is served from disk while its hash matches the feed and
 * re-downloaded when the feed changed. Degrades to a filemtime TTL when version.json
 * is unavailable.
 *
 * Layer 1: Registry::cache()->file() (data/cache/, hash-aware keys, TTL 24h)
 * Layer 2: data/linkenhancer/wthb-toc-lang/ (persistent, offline fallback)
 */
final class WthbTocService
{
    public const BASE_URL = 'https://bschwede.codeberg.page/linkenhancer-wthb-toc-lang/';

    private const DATA_SUBDIR = 'linkenhancer/wthb-toc-lang/';
    private const CACHE_TTL   = 86400;
    private const VERSION_TTL = 86400; // manifest (version.json) re-check cadence
    private const STATE_FILE  = 'feed-state.json'; // per-file applied hashes (in DATA_SUBDIR)

    // ─── Public API (Runtime) ───────────────────────────────────────────────

    /**
     * Load the TOC structure.
     *
     * @return array{version?: string, source?: string, items: array<int, array{num: string, title_de: string, href: string, prefix?: string, prefix_tmpl?: string|null, prefix_arg?: string|null}>}
     */
    public function loadStructure(): array
    {
        $hash = $this->currentFeedVersion()['files']['wthb-toc.json'] ?? '';

        return Registry::cache()->file()->remember(
            'wthb-toc-structure-' . $hash,
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

        $this->syncFeedState($this->download('version.json'));

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
        Registry::cache()->file()->forget('wthb-toc-version');
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
        $hash = $this->currentFeedVersion()['files'][$lang . '.json'] ?? '';

        return Registry::cache()->file()->remember(
            'wthb-toc-lang-' . $lang . '-' . $hash,
            fn (): array => $this->fetchJson($lang . '.json'),
            self::CACHE_TTL
        );
    }

    /**
     * Plain cache: data/ file while fresh (filemtime < CACHE_TTL), else download,
     * falling back to the local file on download failure. No hash awareness.
     *
     * @return array<string, mixed>
     */
    private function _fetchPlain(string $filename): array
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
     * Hash-gated fetch: serve the local file while its per-file hash matches the
     * current feed version; re-download when the feed changed. Degrades to
     * _fetchPlain when version.json (or its "files" map) is unavailable.
     *
     * @return array<string, mixed>
     */
    private function fetchJson(string $filename): array
    {
        if (!$this->autoUpdateEnabled()) {
            return $this->localJson($filename);
        }

        if ($filename === 'version.json') {
            return $this->_fetchPlain($filename);
        }

        $files = $this->currentFeedVersion()['files'] ?? null;
        $state = $this->readFeedState();
        $local = Webtrees::DATA_DIR . self::DATA_SUBDIR . $filename;

        if (self::shouldServeLocal($files, $state, is_file($local), $filename)) {
            return json_decode((string) file_get_contents($local), true) ?? [];
        }

        if ($files === null || !isset($files[$filename])) {
            return $this->_fetchPlain($filename); // no per-file hash → degrade to TTL
        }

        $data = $this->download($filename);
        if ($data !== []) {
            $state[$filename] = $files[$filename];
            $this->writeFeedState($state);
        }

        return $data !== [] ? $data : $this->_fetchPlain($filename);
    }

    /**
     * The current feed manifest (version.json) — re-fetched at most once per
     * VERSION_TTL. Never via the hash-gated fetchJson (avoids recursion).
     *
     * @return array<string, mixed>|null null when unavailable or without a "files" map
     */
    public function currentFeedVersion(): ?array
    {
        $data = Registry::cache()->file()->remember(
            'wthb-toc-version',
            fn (): array => $this->download('version.json'),
            self::VERSION_TTL
        );

        return is_array($data) && isset($data['files']) ? $data : null;
    }

    /**
     * Per-file applied hashes (feed-state.json in DATA_SUBDIR).
     *
     * @return array<string, string>
     */
    private function readFeedState(): array
    {
        $state = $this->localJson(self::STATE_FILE);

        return is_array($state) ? $state : [];
    }

    /**
     * @param array<string, string> $state
     */
    private function writeFeedState(array $state): void
    {
        $dir = Webtrees::DATA_DIR . self::DATA_SUBDIR;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($dir . self::STATE_FILE, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * After a forced refresh: record the feed version's per-file hashes as applied.
     *
     * @param array<string, mixed> $version
     */
    private function syncFeedState(array $version): void
    {
        $files = $version['files'] ?? null;
        if (is_array($files)) {
            $this->writeFeedState($files);
        }
    }

    /**
     * The effective TOC download base URL (module pref; empty = built-in default).
     * Always ends with a trailing slash.
     */
    public function baseUrl(): string
    {
        return self::normalizeBaseUrl($this->readPref(LinkEnhancerModule::PREF_WTHB_TOC_BASE_URL, ''));
    }

    /**
     * Normalize a base URL: empty → built-in default; trim; ensure one trailing slash.
     * Pure (no I/O) — unit-testable without a webtrees bootstrap.
     */
    public static function normalizeBaseUrl(string $url): string
    {
        $url = trim($url);

        return $url === '' ? self::BASE_URL : rtrim($url, '/') . '/';
    }

    /**
     * Decide whether a hash-gated fetch can be served from the local file.
     * Pure — unit-testable. True only when a per-file hash exists for the file,
     * the local file is present, and the applied hash matches the feed hash.
     *
     * @param array<string, string>|null $files  per-file hashes from version.json (null = unavailable)
     * @param array<string, string>      $state  applied hashes (feed-state.json)
     */
    public static function shouldServeLocal(?array $files, array $state, bool $local_exists, string $filename): bool
    {
        if ($files === null || !isset($files[$filename])) {
            return false; // no per-file hash → caller degrades to TTL logic
        }

        return $local_exists && ($state[$filename] ?? null) === $files[$filename];
    }

    /**
     * Download from GitHub Pages → data/ (persisted).
     *
     * @return array<string, mixed>
     */
    private function download(string $filename): array
    {
        $url = $this->baseUrl() . $filename;
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
     * Is the TOC auto-update feature enabled? When disabled, the runtime path
     * serves only locally cached files (no HTTP download). The admin force-refresh
     * button and CLI (with --force) remain unaffected.
     */
    public function autoUpdateEnabled(): bool
    {
        return $this->readPref(LinkEnhancerModule::PREF_WTHB_TOC_AUTO_UPDATE, '1') === '1';
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
