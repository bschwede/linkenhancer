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

use DomainException;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Gedcom;
use Fisharebest\Webtrees\GedcomRecord;
use Fisharebest\Webtrees\Tree;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;
use Throwable;

use function e;
use function strtotime;
use function time;

final class XrefsService { // stuff related with handling cross references

    /**
     * Valid linkenhancer link: [text](#@param1&paramN) / ![pic](#@...)
     * with a non-empty URL part starting at "#@".
     */
    public const RE_LE_LINK = '/(!?\[[^\]]+\]\(#@[^)]+\))/';

    /**
     * Pass 2 residuals: defective LE remainder "](#@" and classic @XREF@.
     */
    public const RE_REMAINDER = '/(\]\(#@[^)]*|@[A-Za-z0-9:_.-]{1,20}@)/';

    /**
     * The "wt" parameter at a parameter boundary - it may occur multiple
     * times per link and does not have to be the first parameter.
     */
    private const RE_WT_PARAM = '/(?:^|&)wt=/';

    /**
     * A classic cross-reference: @XREF@
     */
    private const RE_CLASSIC_XREF = '/^@([A-Za-z0-9][A-Za-z0-9:_.-]{0,19})@$/';

    /**
     * One "wt" parameter value: wt=[type]@XREF@[tree] - the optional type
     * letter may be absent, the "@tree" part may be empty (same tree).
     */
    private const RE_WT_TARGET = '/(?:^|[?&])wt=(?P<type>[a-z])?@(?P<xref>[A-Za-z0-9][A-Za-z0-9:_.-]{0,19})@(?P<tree>[^&\s]*)/';

    /**
     * The optional "id" parameter: id=@XREF@ - at most one per link, the
     * position among the parameters does not matter, no tree part.
     */
    private const RE_ID_TARGET = '/(?:^|[?&])id=@([A-Za-z0-9][A-Za-z0-9:_.-]{0,19})@/';

    /**
     * The "wt" type letter (README "available record types") mapped to the
     * GEDCOM record tag that GedcomRecord::tag() returns for that type. Used
     * to flag a wt= target whose declared type does not match the resolved
     * record. All six are verified against the record classes' raw tags
     * (Location/RECORD_TYPE = '_LOC', Media/RECORD_TYPE = 'OBJE', ...).
     *
     * @var array<string,string>
     */
    public const WT_TYPE_TAGS = [
        'i' => 'INDI',
        'f' => 'FAM',
        's' => 'SOUR',
        'r' => 'REPO',
        'n' => 'NOTE',
        'l' => '_LOC',
    ];

    /**
     * Link index tables (Phase 2) and the default freshness threshold.
     * le_index_meta holds the state of the last COMPLETE index run
     * (single row, id = 1) and is what makes the index "fresh".
     */
    public const INDEX_SCAN_TABLE    = 'le_record_scan';
    public const INDEX_LINK_TABLE    = 'le_link_index';
    public const INDEX_META_TABLE    = 'le_index_meta';
    public const INDEX_FRESH_SECONDS = 7200;

    /**
     * Engines with a regex operator that works for the link pre-filter.
     * SQLite never registers a REGEXP user function and SQL Server's
     * T-SQL has no REGEXP operator at all - on those (and any future
     * driver) the live overview runs in limited LIKE mode.
     *
     * @var array<int,string>
     */
    public const REGEXP_DRIVERS = [DB::MARIADB, DB::MYSQL, DB::POSTGRESQL];

    /**
     * Limited mode (no REGEXP support): coarse LIKE over-approximation of
     * "the text contains an @xref-like pair". The PHP link classifier
     * stays the source of truth - this only decides which records are
     * fetched as candidates.
     */
    public const LIKE_XREF_PREDICATE = '%@%@';

    /**
     * Mirror of Fisharebest\Webtrees\Gedcom::REGEX_XREF - the XREF format
     * is part of webtrees' data contract and stable. A local mirror keeps
     * the pure pattern helpers testable in the standalone test harness
     * (the core class uses PHP 8.3-only syntax and is not loadable there).
     */
    public const RE_XREF_CLASS = '[A-Za-z0-9:_.-]{1,20}';

    /**
     * The link buckets, in display order.
     *
     * @var array<int,string>
     */
    public const LINK_CLASSES = ['xref', 'ext', 'pic', 'classic', 'other'];

    /**
     * Default cap of tokens shown per class in the link inventory cell.
     *
     * @var int
     */
    public const LINKS_PER_CLASS_DEFAULT = 5;

    /**
     * The selectable caps (0 = show all tokens).
     *
     * @var array<int,int>
     */
    public const LINKS_PER_CLASS_OPTIONS = [0, 5, 10, 20];


    public const TARGET_NOT_FOUND_GLYPH = "✗";
    public const TARGET_TYPE_MISMATCH_GLYPH = "⚠";

    /**
     * Clamp an arbitrary input to the selectable caps - the single source
     * of truth for the allowlist (page select AND data endpoint policy).
     */
    public static function normalizeLinksPerClass(int $value): int
    {
        return in_array($value, self::LINKS_PER_CLASS_OPTIONS, true)
            ? $value
            : self::LINKS_PER_CLASS_DEFAULT;
    }

    
    public const GEDCOM_TABLES = [
        'INDI' => [
            'table'      => 'individuals',
            'prefix'     => 'i',
            'typestr'    => "'INDI'",
        ],
        'FAM' => [
            'table'      => 'families',
            'prefix'     => 'f',
            'typestr'    => "'FAM'",
        ],
        'MEDIA' => [
            'table'      => 'media',
            'prefix'     => 'm',
            'typestr'    => "'MEDIA'",
        ],
        'SOUR' => [
            'table'      => 'sources',
            'prefix'     => 's',
            'typestr'    => "'SOUR'",
        ],
        'OTHER' => [ // NOTE, REPO, _LOC
            'table'      => 'other',
            'prefix'     => 'o',
            'typestr'    => '`o_type`',
        ]
    ];

    public const GEDCOM_OTHER_SUBTYPES = [ "NOTE", "REPO", "_LOC" ];

    private static function getGedcomRecTypeSubquery(array $params, string $xref = Gedcom::REGEX_XREF, int|null $file = null): Builder {
        // Identifiers are wrapped per grammar (backticks on M/M, double
        // quotes on PG/SQLite, [] on SQL Server) - the live overview must
        // work on every engine, also in limited LIKE mode.
        $grammar = DB::connection()->getQueryGrammar();

        $query = DB::table($params['table'])
            ->select(
                DB::raw($grammar->wrap($params['prefix'] . '_id') . ' AS xref'),
                DB::raw($grammar->wrap($params['prefix'] . '_file') . ' AS file'),
                $params['table'] === 'other'
                    ? DB::raw($grammar->wrap('o_type') . ' AS type')
                    : DB::raw($params['typestr'] . ' AS type'),
                DB::raw($grammar->wrap($params['prefix'] . '_gedcom') . ' AS gedcom')
            );

        if ($file !== null) {
            $query->where($params['prefix'] . '_file', '=', $file);
        }

        $field = "{$params['prefix']}_gedcom";
        if (self::supportsRegexp()) {
            $patterns = self::linkPrefilterPatterns($xref, $params['table'] === 'other');
            $query->where(function ($q) use ($patterns, $field) {
                foreach ($patterns as $pattern) {
                    $q->orWhere($field, DB::regexOperator(), $pattern);
                }
            });
        } else {
            // Limited mode (F9): no working REGEXP operator on this engine.
            // Coarse over-approximation - the PHP classifier is the source
            // of truth, this only decides which records are fetched.
            $query->where($field, 'like', self::LIKE_XREF_PREDICATE);
        }

        return $query;
    }

    /**
     * The strict link pre-filter patterns - single source for the live
     * overview gate (SQL) and the CLI index gate (PHP/SQL).
     *
     * Consolidated to three (five for the shared-note table) patterns with
     * the exact semantics of the former ten:
     *  - NOTE requires content before OR after the xref on the same line -
     *    a naked "1 NOTE @X@" is a GEDCOM shared-note pointer, not a link
     *  - CON[CT]/TEXT/_TODO: a naked xref IS a link
     *  - linkenhancer syntax "](#@" requires preceding content
     *  - a shared note's own level-0 text is matched explicitly
     *
     * Deliberately the most conservative regex core (plain groups,
     * no anchors/modifiers) so the same strings behave identically on
     * MariaDB/MySQL (PCRE) and PostgreSQL (ARE) and across engine
     * versions.
     *
     * The "no cross-line" class is written with a LITERAL newline inside
     * the bracket expression (PHP "\n" in the double-quoted strings
     * below), not the two-character escape: a literal newline as a
     * bracket-expression member is unambiguous in every dialect, while
     * escape handling inside bracket expressions differs (ARE vs PCRE).
     *
     * @return array<int,string>
     */
    public static function linkPrefilterPatterns(string $xref, bool $shared_note): array
    {
        $patterns = [
            "[1-9] NOTE ([^\n]+@{$xref}@|@{$xref}@[^\n]+)",
            "[1-9] (CON[CT]|TEXT|_TODO) [^\n]*@{$xref}@",
            "[1-9] (NOTE|CON[CT]|TEXT|_TODO) [^\n]+\\]\\(#@",
        ];
        if ($shared_note) { // makes only sense in other table for shared notes
            $patterns[] = "0 @" . self::RE_XREF_CLASS . "@ NOTE [^\n]*@{$xref}@";
            $patterns[] = "0 @" . self::RE_XREF_CLASS . "@ NOTE [^\n]+\\]\\(#@";
        }

        return $patterns;
    }

    /**
     * Does this engine have a regex operator that works for the pre-filter?
     * (SQLite: REGEXP function never registered; SQL Server: no operator.)
     */
    public static function supportsRegexp(): bool
    {
        try {
            return in_array(DB::driverName(), self::REGEXP_DRIVERS, true);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The PHP twin of the SQL link pre-filter (same pattern source): the
     * index contains exactly the records the live overview shows, also on
     * incremental runs where changed records are decided in PHP.
     */
    public static function hasLinkCandidate(string $gedcom, string $rectype, string $xref = self::RE_XREF_CLASS): bool
    {
        foreach (self::linkPrefilterPatterns($xref, $rectype === 'NOTE') as $pattern) {
            if (@preg_match('/' . $pattern . '/', $gedcom) === 1) {
                return true;
            }
        }

        return false;
    }

    
    public static function supportedGedcomTableKeys() : array {
        return array_keys(self::GEDCOM_TABLES);
    }

    public static function supportedGedcomRecordKeys(): array
    {
        return array_merge(self::supportedGedcomTableKeys(), self::GEDCOM_OTHER_SUBTYPES);
    }

    /**
     * sql query for records of a specific type which containing a given or any xref
     * @param Tree|null                 $tree
     * @param string|null $xref
     * @param array<string> $rectypes
     * @param bool $ordered add the default file/xref ordering (disable for
     *                      datatables, which applies its own ordering)
     *
     * @return Builder
     */
    public static function getRecordsQuery(Tree|null $tree = null, string|null $xref = null, array $rectypes = [], bool $ordered = true): Builder
    {
        $gedcom_table_keys = self::supportedGedcomTableKeys();
        $gedcom_record_keys = self::supportedGedcomRecordKeys();
        $rectypes = array_map('strtoupper', $rectypes);
        $rectypes = array_values(array_filter($rectypes, fn($s) => in_array($s, $gedcom_record_keys)));
        $rectypes = count($rectypes) === 0 ?
            $gedcom_table_keys :
            $rectypes;
        $other_subtypes_filter = in_array('OTHER', $rectypes) ?
            self::GEDCOM_OTHER_SUBTYPES :
            array_values(array_filter($rectypes, fn($s) => in_array($s, self::GEDCOM_OTHER_SUBTYPES)));
        // NOTE/REPO/_LOC live in the "other" table - make sure it is part of the union
        if ($other_subtypes_filter !== [] && !in_array('OTHER', $rectypes)) {
            $rectypes[] = 'OTHER';
        }
        $rectypes_filter = array_values(array_filter($rectypes, fn($s) => in_array($s, $gedcom_table_keys)));

        $xref ??= Gedcom::REGEX_XREF;

        $file = $tree instanceof Tree ? $tree->id() : null;

        $unionQuery = null;
        foreach ($rectypes_filter as $rectype) {
            $params = self::GEDCOM_TABLES[$rectype];
            $subquery = self::getGedcomRecTypeSubquery($params, $xref, $file);

            if ($rectype === 'OTHER') {
                $subquery->whereIn('o_type', $other_subtypes_filter);
            }

            $unionQuery = $unionQuery ? $unionQuery->unionAll($subquery) : $subquery;
        }

        if ($unionQuery === null) {
            throw new InvalidArgumentException('No valid GEDCOM record types given.');
        }

        $query = DB::query()
            ->fromSub($unionQuery, 'u')
            ->select('u.*');

        if ($ordered) {
            $query->orderBy('u.file')->orderBy('u.xref');
        }

        return $query;
    }

    /**
     * Classify all link tokens in a single text value.
     *
     * Two-pass scan (A2): pass 1 matches the complete valid LE links first
     * and masks them (same length, offsets stay stable), so pass 2 can never
     * steal fragments of an already classified link.
     *
     * Buckets (mutually exclusive, every link is counted exactly once):
     *  - ext      [text](#@…) without a wt= parameter
     *  - pic      ![pic](#@…) without a wt= parameter
     *  - xref     LE link whose URL part contains at least one wt= parameter
     *  - classic  plain @XREF@ cross-reference
     *  - other    defective LE remainder "](#@"
     *
     * @return array<int, array{class: string, token: string, snippet: string}>
     *         In offset order. snippet = token plus up to 10 chars of surrounding context.
     */
    public static function classifyTextLinks(string $text): array {
        $found  = [];
        $masked = $text;

        // Pass 1: valid LE links.
        if (preg_match_all(self::RE_LE_LINK, $text, $m1, PREG_OFFSET_CAPTURE) !== false) {
            foreach ($m1[0] as $match) {
                $token  = $match[0];
                $offset = $match[1];

                // URL part: after the last "(#@" up to the closing bracket.
                $pos = strrpos($token, '(#@');
                $url = $pos === false ? '' : substr($token, $pos + 3, -1);

                $class = preg_match(self::RE_WT_PARAM, $url) === 1
                    ? 'xref'
                    : (str_starts_with($token, '![') ? 'pic' : 'ext');

                $found[$offset] = [
                    'class'   => $class,
                    'token'   => $token,
                    'snippet' => self::snippet($text, $offset, $token),
                ];

                $masked = substr_replace($masked, str_repeat(' ', strlen($token)), $offset, strlen($token));
            }
        }

        // Pass 2: defective LE remainders and classic xrefs in the residual.
        if (preg_match_all(self::RE_REMAINDER, $masked, $m2, PREG_OFFSET_CAPTURE) !== false) {
            foreach ($m2[0] as $match) {
                $found[$match[1]] = [
                    'class'   => str_starts_with($match[0], '](#@') ? 'other' : 'classic',
                    'token'   => $match[0],
                    'snippet' => self::snippet($text, $match[1], $match[0]),
                ];
            }
        }

        ksort($found);

        return array_values($found);
    }

    /**
     * Full link inventory of one record: every TextTagCollector value
     * classified per entry.
     *
     * @param array<int,string> $tags
     *
     * @return array{
     *     entries: array<int, array{path: string, class: string, token: string, snippet: string}>,
     *     counts: array{ext: int, pic: int, xref: int, classic: int, other: int}
     * }
     */
    public static function classifyRecordLinks(GedcomRecord $record, array $tags = TextTagCollector::DEFAULT_TAGS): array {
        return self::classifyGedcomText($record->gedcom(), $tags, $record->tag());
    }

    /**
     * Link inventory of a raw GEDCOM record text - no GedcomRecord needed.
     *
     * C4: used by the paginated data handler, where the record text is
     * already part of the query row and building a full record object
     * would mean a second fetch.
     *
     * @param array<int,string> $tags
     *
     * @return array{
     *     entries: array<int, array{path: string, class: string, token: string, snippet: string}>,
     *     counts: array{ext: int, pic: int, xref: int, classic: int, other: int}
     * }
     */
    public static function classifyGedcomText(string $gedcom, array $tags = TextTagCollector::DEFAULT_TAGS, ?string $record_type = null): array {
        $counts  = self::emptyCounts();
        $entries = [];
        foreach (TextTagCollector::collect($gedcom, $tags, $record_type) as $entry) {
            if (self::isSharedNotePointer($entry)) {
                continue; // structural reference, not a text link
            }
            foreach (self::classifyTextLinks($entry['value']) as $link) {
                $counts[$link['class']]++;
                $entries[] = [
                    'path'    => $entry['path'],
                    'class'   => $link['class'],
                    'token'   => $link['token'],
                    'snippet' => $link['snippet'],
                ];
            }
        }

        return ['entries' => $entries, 'counts' => $counts];
    }

    /**
     * @return array{ext: int, pic: int, xref: int, classic: int, other: int}
     */
    public static function emptyCounts(): array {
        return array_fill_keys(self::LINK_CLASSES, 0);
    }

    /**
     * F7: a NOTE tag (level >= 1) whose value is exactly one classic
     * reference is a GEDCOM shared-note pointer - a structural reference,
     * not a text link. Silently skipped (no bucket). The level-0 text of
     * a shared-note record itself (level 0) is NOT a pointer and stays
     * classified.
     */
    private static function isSharedNotePointer(array $entry): bool
    {
        return $entry['tag'] === 'NOTE'
            && $entry['level'] >= 1
            && preg_match(self::RE_CLASSIC_XREF, trim($entry['value'])) === 1;
    }

    /**
     * HTML for the "link inventory" cell of the XREF overview: up to
     * $max_per_class tokens per class, then an overflow counter.
     *
     * $max_per_class > 0 = cap per class with an overflow counter;
     * $max_per_class <= 0 = show every token, no overflow counter.
     *
     * $highlight_xref: when non-empty (the active "referencing XREF" filter),
     * the XREF is additionally wrapped in a <mark> inside token/snippet so its
     * occurrence stands out from the generic token <strong>. Boundary-aware
     * (I1 must not match I12) and case-sensitive (matches the utf8mb4_bin SQL
     * filter). Empty = no extra highlight (default).
     *
     * $target_linker: when non-null, each xref/classic token additionally gets
     * a reference link below its snippet - the referenced record, labelled with
     * its full name (a cross-tree target is prefixed with its tree name).
     * Signature: fn(string $xref, ?string $target_tree_name):
     * ?array{name: string, url: string, tree_label: string}. null (default) =
     * no reference links.
     *
     * @param array<int, array{path: string, class: string, token: string, snippet: string}> $entries
     * @param callable(string, ?string): (array{name: string, url: string, tree_label: string}|null)|null $target_linker
     */
    public static function linkInventoryHtml(array $entries, int $max_per_class = self::LINKS_PER_CLASS_DEFAULT, string $highlight_xref = '', ?callable $target_linker = null): string {
        $items = [];
        foreach ($entries as $entry) {
            $items[$entry['class']][] = $entry;
        }

        $has_any = false;
        foreach ($items as $class_items) {
            if ($class_items !== []) {
                $has_any = true;
                break;
            }
        }
        if (!$has_any) {
            return '';
        }

        $html = '<ul class="le-xref-inventory">';
        foreach (self::LINK_CLASSES as $class) {
            $class_items = $items[$class] ?? [];
            if ($class_items === []) {
                continue;
            }
            $shown = ($max_per_class > 0) ? array_slice($class_items, 0, $max_per_class) : $class_items;

            $html .= '<li>' . e($class) . ' (' . count($class_items) . ')<ol>';
            foreach ($shown as $entry) {
                $prefix = ($entry['path'] !== '' && $entry['path'] !== 'NOTE') ? e($entry['path']) . ': ' : '';
                // Highlight the token inside the snippet: the snippet is
                // built around the token (token fallback: snippet = token),
                // so every part around the token(s) is escaped separately.
                $parts = explode($entry['token'], $entry['snippet']);
                $hl    = e(array_shift($parts));
                foreach ($parts as $part) {
                    $hl .= '<strong>' . e($entry['token']) . '</strong>' . e($part);
                }
                // When the "referencing XREF" filter is active, additionally
                // mark the XREF itself so its occurrence stands out. Boundary-
                // aware (I1 must not match I12) and case-sensitive, matching
                // the utf8mb4_bin target_xref SQL filter.
                if ($highlight_xref !== '') {
                    $pattern = '/(?<![A-Za-z0-9])' . preg_quote($highlight_xref, '/') . '(?![A-Za-z0-9])/';
                    $hl      = (string) preg_replace($pattern, '<mark class="le-xref-target">$0</mark>', $hl);
                }
                $target_html = self::targetLinksHtml($entry, $target_linker);
                $html  .= '<li>' . $prefix . '<code>' . $hl . '</code>' . $target_html . '</li>';
            }
            $html .= '</ol>';

            if ($max_per_class > 0 && count($class_items) > $max_per_class) {
                $html .= '<em>+' . (count($class_items) - $max_per_class) . '</em>';
            }
            $html .= '</li>';
        }
        $html .= '</ul>';

        return $html;
    }

    /**
     * The reference link(s) shown below the snippet of an xref/classic/pic
     * token: the referenced record(s), labelled with their full name (a
     * cross-tree target is prefixed with its tree name). For an xref target
     * the declared "wt" type letter is checked against the resolved record's
     * tag (a pic target must be a Media); a mismatch keeps the link and adds
     * a findable "⚠" hint. Unresolvable targets render as "✗" + the raw XREF
     * (findable via the browser's search). $target_linker = null (or a
     * non-xref/classic/pic class) yields no links. The full name is trusted
     * HTML (privacy-aware, may hold markup) and is embedded unescaped,
     * matching the record-name column.
     *
     * @param array{class: string, token: string} $entry
     * @param callable(string, ?string): (array{name: string, url: string, tree_label: string, actual: string}|null)|null $target_linker
     */
    private static function targetLinksHtml(array $entry, ?callable $target_linker): string {
        if ($target_linker === null || !in_array($entry['class'], ['xref', 'classic', 'pic'], true)) {
            return '';
        }

        $links = [];
        foreach (self::extractLinkTargets($entry['token']) as $target) {
            // Declared target type: the wt= letter (xref), or Media for a pic
            // link's id= target. classic / unknown letter = nothing to check.
            $expected_tag = $target['type'] !== null
                ? (self::WT_TYPE_TAGS[$target['type']] ?? null)
                : ($entry['class'] === 'pic' ? 'OBJE' : null);

            $resolved = $target_linker($target['xref'], $target['tree']);
            if ($resolved === null) {
                $links[] = '<span class="le-target-missing" title="' . e(I18N::translate("target not found")). '">' . self::TARGET_NOT_FOUND_GLYPH . ' @' . e($target['xref']) . '@</span>';
                continue;
            }

            $label  = ($resolved['tree_label'] !== '')
                ? e($resolved['tree_label']) . ': ' . $resolved['name']
                : $resolved['name'];
            $anchor = '<span class="le-cross-ref" title="' . e(I18N::translate('cross reference')) . '">↪</span> <a href="' . e($resolved['url']) . '">' . $label . '</a>';
            if ($expected_tag !== null && $resolved['actual'] !== $expected_tag) {
                $hint = I18N::translate('expected %1$s, is %2$s', $expected_tag, $resolved['actual']);
                $anchor .= ' <span class="le-target-type-mismatch" title="' . e($hint) . '">' . self::TARGET_TYPE_MISMATCH_GLYPH . '</span>';
            }
            $links[] = $anchor;
        }
        if ($links === []) {
            return '';
        }

        return '<div class="le-target-links">' . implode('<br>', $links) . '</div>';
    }

    /**
     * HTML for the "count" column: total plus the per-class breakdown.
     *
     * @param array<string, int> $counts
     */
    public static function linkCountSummary(array $counts): string {
        $total = 0;
        $parts = [];
        foreach (self::LINK_CLASSES as $class) {
            $n = $counts[$class] ?? 0;
            $total += $n;
            if ($n > 0) {
                $parts[] = $class . ': ' . $n;
            }
        }
        if ($total === 0) {
            return '0';
        }

        return '<strong>' . $total . '</strong><div class="text-muted"><small>' . implode('<br/>', $parts) . '</small></div>';
    }

    /**
     * Best-effort extraction of the target(s) a link token points to -
     * the foundation for the planned Backlink feature.
     *
     *  - classic:  @XREF@                 → one target, same tree
     *  - LE links: every "wt" parameter in the URL part
     *              (wt=[type]@XREF@[tree]) → one target each
     *  - LE links: the optional "id" parameter (id=@XREF@, at most one,
     *              position among the parameters does not matter, no tree
     *              part) → one target, same tree - checked after the "wt"
     *              parameters
     *
     * Anything else (external URL without wt= or id=, classic in a URL, ...)
     * yields no target. Each target carries its declared "wt" type letter
     * (null when absent / for id= and classic targets).
     *
     * @return array<int, array{xref: string, tree: string|null, type: string|null}>
     */
    public static function extractLinkTargets(string $token): array {
        if (preg_match(self::RE_CLASSIC_XREF, $token, $match) === 1) {
            return [['xref' => $match[1], 'tree' => null, 'type' => null]];
        }

        $targets = [];
        $pos     = strrpos($token, '(#@');
        if ($pos !== false) {
            // The URL part ends at the closing bracket of the token.
            $url = substr($token, $pos + 3, -1);
            if (preg_match_all(self::RE_WT_TARGET, $url, $matches, PREG_SET_ORDER) !== false) {
                foreach ($matches as $m) {
                    $targets[] = [
                        'xref' => $m['xref'],
                        'tree' => $m['tree'] !== '' ? $m['tree'] : null,
                        'type' => $m['type'] !== '' ? $m['type'] : null,
                    ];
                }
            }
            // The optional "id" parameter - at most one, any position.
            if (preg_match(self::RE_ID_TARGET, $url, $m) === 1) {
                $targets[] = [
                    'xref' => $m[1],
                    'tree' => null,
                    'type' => null,
                ];
            }
        }

        return $targets;
    }

    /**
     * Status of the link index (Phase 2): row count, last COMPLETE
     * verification and whether the index is considered fresh.
     *
     * Freshness comes from le_index_meta (written by the CLI at the end of
     * a full, untruncated run) - not from MAX(scanned_at), which would
     * go stale on a quiet database where changed records are re-scanned
     * but no new rows appear.
     *
     * @return array{rows: int, scanned_at: string|null, fresh: bool}
     */
    public static function indexStatus(int $fresh_seconds = self::INDEX_FRESH_SECONDS): array {
        try {
            if (!DB::schema()->hasTable(self::INDEX_META_TABLE)
                || !DB::schema()->hasTable(self::INDEX_SCAN_TABLE)
            ) {
                return ['rows' => 0, 'scanned_at' => null, 'fresh' => false];
            }
            $meta = DB::table(self::INDEX_META_TABLE)->first(['last_run', 'rows']);

            $rows       = (int) ($meta->rows ?? 0);
            $scanned_at = $meta->last_run !== null ? (string) $meta->last_run : null;
            $fresh      = $scanned_at !== null
                && strtotime($scanned_at) > time() - $fresh_seconds;

            return ['rows' => $rows, 'scanned_at' => $scanned_at, 'fresh' => $fresh];
        } catch (Throwable) {
            return ['rows' => 0, 'scanned_at' => null, 'fresh' => false];
        }
    }

    /**
     * The XREF-overview query backed by the link index (Phase 2) instead
     * of a live regex scan. One row per (file, xref, rectype) that has at
     * least one indexed link.
     *
     * @param string|null      $target_xref limit to records referencing this XREF
     * @param array<int,string> $rectypes    record types (OTHER = all subtypes)
     * @param bool             $ordered     default order (file, xref) - false when
     *                                      the caller (datatables) applies its own
     */
    public static function getIndexQuery(Tree|null $tree = null, ?string $target_xref = null, array $rectypes = [], bool $ordered = true): Builder {
        // Deduplicated link rows as a subquery: the join becomes at most
        // 1:1 on (file, xref, rectype), so the plain count(*) the datatables
        // service issues (it drops the outer DISTINCT) already equals the
        // number of source records - not of links.
        $links = DB::table(self::INDEX_LINK_TABLE)
            ->distinct()
            ->select('file', 'xref', 'rectype');
        if ($target_xref !== null && $target_xref !== '') {
            $links->where('target_xref', '=', $target_xref);
        }

        $query = DB::table(self::INDEX_SCAN_TABLE . ' AS s')
            ->joinSub($links, 'l', static function ($join): void {
                $join->on('l.file', '=', 's.file')
                    ->on('l.xref', '=', 's.xref')
                    ->on('l.rectype', '=', 's.rectype');
            })
            ->distinct()
            ->select(['s.file', 's.xref', DB::raw('s.rectype AS type')])
            ->whereIn('s.rectype', self::normalizeIndexRectypes($rectypes));

        if ($ordered) {
            $query->orderBy('s.file')->orderBy('s.xref');
        }
        if ($tree instanceof Tree) {
            $query->where('s.file', '=', $tree->id());
        }

        return $query;
    }

    /**
     * The indexed link tokens of one record, deduplicated per (class, token) -
     * a link with several wt= parameters is counted once. The snippet is the
     * display context captured at build time (NULL on rows created before
     * the snippet column existed - the caller falls back to the token).
     *
     * @return array<int, array{tag_path: string, class: string, token: string, snippet: string|null}>
     */
    public static function indexRowLinks(int $file, string $xref, string $rectype): array {
        $links = [];
        foreach (DB::table(self::INDEX_LINK_TABLE)
            ->where('file', '=', $file)
            ->where('xref', '=', $xref)
            ->where('rectype', '=', $rectype)
            ->get(['tag_path', 'link_class', 'token', 'snippet']) as $row) {
            $key = $row->link_class . "\0" . $row->token;
            if (!isset($links[$key])) {
                $links[$key] = [
                    'tag_path' => (string) $row->tag_path,
                    'class'    => (string) $row->link_class,
                    'token'    => (string) $row->token,
                    'snippet'  => $row->snippet !== null ? (string) $row->snippet : null,
                ];
            }
        }

        return array_values($links);
    }

    /**
     * Map the UI record-type filter to the concrete rectype values stored
     * in the index (OTHER expands to its subtypes, which are stored as the
     * actual o_type values).
     *
     * @param  array<int,string> $rectypes
     * @return array<int,string>
     */
    private static function normalizeIndexRectypes(array $rectypes): array {
        $record_keys = self::supportedGedcomRecordKeys();
        $rectypes    = array_map('strtoupper', $rectypes);
        $rectypes    = array_values(array_filter($rectypes, static fn (string $s): bool => in_array($s, $record_keys, true)));
        if ($rectypes === []) {
            $rectypes = self::supportedGedcomTableKeys();
        }
        $expanded = array_values(array_filter($rectypes, static fn (string $s): bool => $s !== 'OTHER'));
        if (in_array('OTHER', $rectypes, true)) {
            $expanded = array_merge($expanded, self::GEDCOM_OTHER_SUBTYPES);
        }

        return array_values(array_unique($expanded));
    }

    /**
     * Display context: a window of up to ~10 characters around the token.
     *
     * The window is measured in bytes ($offset is a PREG_OFFSET_CAPTURE byte
     * offset) and then snapped onto UTF-8 character boundaries so a multi-byte
     * character is never cut in half at an edge — a stray continuation byte
     * would render as "?". A split edge character is completed (kept whole),
     * not dropped. Pure byte/ord logic, so no mbstring dependency is needed.
     */
    private static function snippet(string $text, int $offset, string $token): string {
        $length = strlen($text);
        $start  = max(0, $offset - 10);
        $end    = min($length, $offset + 10 + strlen($token));

        // Left edge: if it lands inside a multi-byte sequence, back up to the
        // sequence's lead byte so the whole character is kept.
        while ($start > 0 && (ord($text[$start]) & 0xC0) === 0x80) {
            $start--;
        }
        // Right edge: if the next byte is a continuation byte, the window ends
        // mid-character — extend it to complete that character.
        while ($end < $length && (ord($text[$end]) & 0xC0) === 0x80) {
            $end++;
        }

        return substr($text, $start, $end - $start);
    }

}