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

use Fisharebest\Webtrees\Registry;
use Schwendinger\Webtrees\Module\LinkEnhancer\LinkEnhancerModule;
use Schwendinger\Webtrees\Module\LinkEnhancer\Schema\SeedHelpTable;

use function dirname;
use function file_get_contents;
use function file_put_contents;
use function glob;
use function hash;
use function is_dir;
use function is_file;
use function json_decode;
use function json_encode;
use function mkdir;
use function rtrim;
use function substr;
use function trim;

/**
 * Help-mapping remote update — hash-based change detection, download,
 * and DB import. Analogous to WthbTocService but for a single CSV file.
 *
 * The CSV (route → help-URL mapping) is hosted on the wt-modules docs site
 * (Codeberg Pages) as a committed artifact, synced via the §2 pipeline
 * (linkenhancer CI → wt-modules push).
 *
 * Change detection: help-mapping.version.json (`{"hash":"…","updated":"…"}`)
 * is compared against the locally applied hash. On mismatch the CSV is
 * downloaded and imported into the DB.
 *
 * D2=B baseline: no initial version.json shipped in the archive. The baseline
 * hash is derived at runtime from the bundled CSV (`HELP_CSV`), so a fresh
 * installation that has never fetched remote data correctly reports "up to
 * date" on the first check.
 */
final class HelpMappingService
{
    public const BASE_URL = 'https://bschwede.codeberg.page/wt-modules/data/';

    private const DATA_SUBDIR    = 'help-mapping/';
    private const REMOTE_CSV     = 'help-mapping.csv';
    private const REMOTE_VERSION = 'help-mapping.version.json';

    /**
     * Download the latest help-mapping CSV if the remote hash differs from
     * the locally applied hash. Import into DB on change.
     *
     * @return bool true if data was updated, false if unchanged or download failed
     */
    public function refresh(): bool
    {
        $remote_version = $this->downloadJson(self::REMOTE_VERSION);
        if ($remote_version === []) {
            return false;
        }

        $local_version = $this->localJson(self::REMOTE_VERSION);
        $local_hash    = $local_version['hash'] ?? $this->bundledHash();
        if (($remote_version['hash'] ?? '') === $local_hash) {
            return false;
        }

        $csv = $this->downloadFile(self::REMOTE_CSV);
        if ($csv === null) {
            return false;
        }

        $this->saveLocal(self::REMOTE_CSV, $csv);
        $this->saveLocalJson(self::REMOTE_VERSION, $remote_version);

        $this->importToDb($csv);

        return true;
    }

    /**
     * Remove all locally cached files (CSV + version.json).
     */
    public function clearCache(): void
    {
        $dir = DataFiles::dir() . self::DATA_SUBDIR;
        if (is_dir($dir)) {
            foreach (glob($dir . '/*') ?: [] as $file) {
                @unlink($file);
            }
        }
    }

    /**
     * Is the help-mapping auto-update feature enabled?
     */
    public function autoUpdateEnabled(): bool
    {
        return $this->readPref(LinkEnhancerModule::PREF_HELP_MAPPING_AUTO_UPDATE, '1') === '1';
    }

    /**
     * The effective download base URL (module pref; empty = built-in default).
     * Always ends with a trailing slash.
     */
    public function baseUrl(): string
    {
        return self::normalizeBaseUrl($this->readPref(LinkEnhancerModule::PREF_HELP_MAPPING_BASE_URL, ''));
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
     * D2=B: derive the baseline hash from the bundled CSV at runtime.
     * No initial version.json is shipped in the archive — the hash of the
     * bundled `HELP_CSV` serves as the "applied" baseline for a fresh install.
     */
    public function bundledHash(): string
    {
        $csv = LinkEnhancerModule::HELP_CSV;
        if (!is_file($csv)) {
            return '';
        }

        return substr(hash('sha256', (string) file_get_contents($csv)), 0, 12);
    }

    /**
     * The hash of the currently applied version (local cache or bundled fallback).
     */
    public function currentAppliedHash(): string
    {
        $version = $this->localJson(self::REMOTE_VERSION);

        return $version['hash'] ?? $this->bundledHash();
    }

    // ─── Intern ─────────────────────────────────────────────────────────────

    private function importToDb(string $csv_content): void
    {
        $data = $this->parseCsv($csv_content);
        $seeder = new SeedHelpTable($data, true);
        $seeder->run(PHP_SAPI === 'cli');
    }

    /**
     * Parse CSV content (semicolon-separated) into rows for SeedHelpTable.
     *
     * @return array<int, array<string, string|null>>
     */
    private function parseCsv(string $content): array
    {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $content);
        rewind($stream);

        $header = fgetcsv($stream, null, ';', '"', '\\');
        if ($header === false) {
            fclose($stream);

            return [];
        }

        $data = [];
        while (($row = fgetcsv($stream, null, ';', '"', '\\')) !== false) {
            if (count($row) === count($header)) {
                $data[] = array_combine($header, $row);
            }
        }
        fclose($stream);

        return $data;
    }

    private function downloadFile(string $filename): ?string
    {
        $url = $this->baseUrl() . $filename;
        $ctx = stream_context_create([
            'http' => ['timeout' => 10, 'header' => "User-Agent: webtrees-linkenhancer/1.x\n"],
        ]);
        $content = @file_get_contents($url, false, $ctx);

        return ($content !== false && $content !== '') ? $content : null;
    }

    private function downloadJson(string $filename): array
    {
        $content = $this->downloadFile($filename);
        if ($content === null) {
            return [];
        }

        return json_decode($content, true) ?? [];
    }

    private function saveLocal(string $filename, string $content): void
    {
        $local = DataFiles::dir() . self::DATA_SUBDIR . $filename;
        $dir   = dirname($local);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($local, $content);
    }

    private function saveLocalJson(string $filename, array $data): void
    {
        $this->saveLocal($filename, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array<string, mixed>
     */
    private function localJson(string $filename): array
    {
        $local = DataFiles::dir() . self::DATA_SUBDIR . $filename;
        if (!is_file($local)) {
            return [];
        }

        return json_decode((string) file_get_contents($local), true) ?? [];
    }

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
