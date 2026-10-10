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
use Schwendinger\Webtrees\Module\LinkEnhancer\LinkEnhancerModule;
use Throwable;

use function e;
use function preg_match_all;
use function preg_replace_callback;
use function str_replace;
use function str_starts_with;
use function stripos;
use function strrpos;
use function strtotime;
use function substr;
use function time;

final class XrefsService { // stuff related with handling cross-references

    /**
     * Valid linkenhancer link: [text](#@param1&paramN) / ![pic](#@...)
     * with a non-empty URL part starting at "#@".
     */
    public const RE_LE_LINK = '/(!?\[[^\]]+\]\(#@[^)]+\))/';

    /**
     * LE link in HTML block content: <a ... href="#@params@...">text</a>
     * (single or double quotes around the href value).
     */
    public const RE_LE_HTML_LINK = '/<a\s[^>]*?href=["\']#@[^"\']*["\'][^>]*>[^<]*<\/a>/i';

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

    /** Number of context characters on each side of a token in a snippet. */
    private const SNIPPET_CONTEXT_CHARS = 10;

    /**
     * The reference carried between the @...@ of a wt=/id= target. XREFs are
     * short (max 20) but UIDs can reach 36+ chars - so the class is widened to
     * 255 to also accept UID-length values. RE_CLASSIC_XREF and RE_XREF_CLASS
     * (the pre-filter candidate selection) stay at 20: a classic ref is
     * XREF-only and the pre-filter does not depend on the ref length.
     */
    private const RE_REF_CLASS = '[A-Za-z0-9][A-Za-z0-9:_.-]{0,254}';

    /**
     * One "wt" parameter value: wt=[type]@REF@[tree] - the optional type
     * letter may be absent, the "@tree" part may be empty (same tree) or
     * contain spaces (e.g. "My Family"). The tree value may carry a
     * trailing " dia" suffix (stripped by stripDiaSuffix()). REF is an
     * XREF or a UID (RE_REF_CLASS).
     *
     * IMPORTANT: This pattern is designed for the EXTRACTED URL part
     * (after "#@" in markdown links or "href="#@..." in HTML links), NOT
     * for the full GEDCOM text. The prefix (?:^|[?&]) requires "wt=" to
     * be at the start or after ?/& - in raw GEDCOM it is preceded by "@".
     */
    private const RE_WT_TARGET_TMPL = '/(?:^|[@?&])wt=(?P<type>[a-z]__TYPEQUANT__)@(?P<xref>' . self::RE_REF_CLASS . ')@(?P<tree>[^&]*)/';
    public const RE_WT_TARGET = '/(?:^|[@?&])wt=(?P<type>[a-z])?@(?P<xref>' . self::RE_REF_CLASS . ')@(?P<tree>[^&]*)/';
    
    public static function getReWtTarget(?bool $type_avail = null): string
    {
        $type_quant = match($type_avail) {
            true => '{1}',
            false => '{0}',
            default => '?'
        };
        return str_replace('__TYPEQUANT__', $type_quant, self::RE_WT_TARGET_TMPL);
    }

    /**
     * The optional "id" parameter: id=@REF@ - at most one per link, the
     * position among the parameters does not matter, no tree part. REF is an
     * XREF or a UID (RE_REF_CLASS).
     */
    private const RE_ID_TARGET = '/(?:^|[?&])id=@(' . self::RE_REF_CLASS . ')@/';

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
    public const TARGET_AMBIGUOUS_GLYPH = "?";

    public const GEDCOM_TABLES = [
        'INDI' => [
            'table' => 'individuals',
            'prefix' => 'i',
            'typestr' => "'INDI'",
        ],
        'FAM' => [
            'table' => 'families',
            'prefix' => 'f',
            'typestr' => "'FAM'",
        ],
        'OBJE' => [
            'table'      => 'media',
            'prefix'     => 'm',
            'typestr'    => "'OBJE'",
        ],
        'SOUR' => [
            'table' => 'sources',
            'prefix' => 's',
            'typestr' => "'SOUR'",
        ],
        'OTHER' => [ // NOTE, REPO, _LOC
            'table' => 'other',
            'prefix' => 'o',
            'typestr' => '`o_type`',
        ]
    ];

    public const GEDCOM_OTHER_SUBTYPES = ["NOTE", "REPO", "_LOC"];

    /**
     * Block modules whose "text" settings may contain LE links / XREFs.
     * Key = block.module_name.
     *
     * @var array<string, array{title: string, settings: array<string, string>}>
     */
    public const BLOCKS = [
        'html' => [
            'title' => 'HTML',
            'settings' => ['title' => 'title', 'html' => 'text', 'languages' => 'info'],
        ],
        'faq' => [
            'title' => 'FAQ',
            'settings' => ['header' => 'title', 'faqbody' => 'text', 'languages' => 'info'],
        ],
        'stories' => [
            'title' => 'Stories',
            'settings' => ['title' => 'title', 'story_body' => 'text', 'languages' => 'info'],
        ],
        '_vesta_classic_look_and_feel_' => [
            'title' => 'Name badges (vesta)',
            'settings' => ['header' => 'title', 'snippet' => 'text', 'regex' => 'info', 'access' => 'access'],
        ],
    ];

    /**
     * Block pre-filter: the setting value contains an LE link in HTML href
     * syntax: <a ... href="#@...">.
     */
    public const BLOCK_LE_PREFILTER = 'href=["\']#@';

    /** LIKE fallback for engines without REGEXP support. */
    public const BLOCK_LE_LIKE = '%#@%';

    /**
     * Record-type filter sentinels (see xref-overview.phtml). They are not a
     * real GEDCOM record type or block module name - the double-underscore
     * form cannot collide with either (GEDCOM keys are 2-letter tags, block
     * module names are single tokens). "__all_gedcom__" = every GEDCOM record
     * type, no blocks; "__all_blocks__" = every block module, no GEDCOM.
     */
    public const RECTYPE_ALL_GEDCOM = '__all_gedcom__';
    public const RECTYPE_ALL_BLOCKS = '__all_blocks__';

    /**
     * Strip the trailing " dia" (case-insensitive) suffix from a tree name.
     * The " dia" marker is used in le-links to flag a tree as a
     * duplicate/alternate view; the JS parser (le-xref-parser.js) strips
     * it the same way. Returns the cleaned name.
     */
    public static function stripDiaSuffix(string $tree_name): string
    {
        return (string) preg_replace('/[ +]dia$/i', '', $tree_name);
    }

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

    /**
     * Replace the renumbered XREF inside le-link "wt=" targets of a GEDCOM text.
     *
     * Only the XREF position of a wt= target whose resolved tree is $target_tree_name
     * is touched. An empty @tree part means "same tree as the source record"
     * ($source_tree_name), so the replacement is evaluated relative to the source -
     * a cross-tree source's own self-links (empty @tree) are NOT rewritten. Display
     * text and id= (UID) targets are left alone.
     *
     * The URL part of each le-link is extracted after "(#@" up to the closing
     * ")" so RE_WT_TARGET's boundary matches at the URL start.
     *
     * @return array{gedcom: string, replaced: int}
     */
    public static function replaceLinkTargetXref(
        string $gedcom,
        string $old_xref,
        string $new_xref,
        string $source_tree_name,
        string $target_tree_name
    ): array {
        $replaced = 0;

        // Markdown le-links: [text](#@url) / ![pic](#@url)
        $gedcom = preg_replace_callback(
            self::RE_LE_LINK,
            static function (array $m) use ($old_xref, $new_xref, $source_tree_name, $target_tree_name, &$replaced): string {
                $token = $m[0];
                $url   = self::extractLeLinkUrl($token);
                if ($url === null) {
                    return $token;
                }
                $head = substr($token, 0, strrpos($token, '(#@') + 3);
                return $head . self::replaceWtXref(
                    $url,
                    $old_xref,
                    $new_xref,
                    $source_tree_name,
                    $target_tree_name,
                    $replaced
                ) . ')';
            },
            $gedcom
        );

        return ['gedcom' => $gedcom, 'replaced' => $replaced];
    }

    /**
     * Replace a matching renumbered XREF in the XREF position of the "wt" targets
     * of one LE-link URL (already extracted, so "wt=" sits at the start).
     */
    private static function replaceWtXref(
        string $url,
        string $old_xref,
        string $new_xref,
        string $source_tree_name,
        string $target_tree_name,
        int &$replaced
    ): string {
        return preg_replace_callback(
            self::RE_WT_TARGET,
            static function (array $m) use ($old_xref, $new_xref, $source_tree_name, $target_tree_name, &$replaced): string {
                if ($m['xref'] !== $old_xref) {
                    return $m[0];
                }
                $url_tree = $m['tree'] !== '' ? self::stripDiaSuffix((string) $m['tree']) : '';
                $target_tree = $url_tree === '' ? $source_tree_name : $url_tree;
                if ($target_tree !== $target_tree_name) {
                    return $m[0];
                }
                $replaced++;

                return str_replace('@' . $old_xref . '@', '@' . $new_xref . '@', $m[0]);
            },
            $url
        );
    }

    /**
     * Setting names classified as 'text' for a given block module.
     *
     * @return array<int,string>
     */
    public static function blockTextSettings(string $module_name): array {
        return LinkIndexService::blockTextSettings($module_name);
    }

    /**
     * All block module names (for the rectype filter dropdown).
     *
     * @return array<int,string>
     */
    public static function blockModuleNames(): array {
        return LinkIndexService::blockModuleNames();
    }

    /**
     * Which data sources back a record-type filter value, and with which
     * type list. Centralized so the data endpoint and the tests share one
     * definition of the "all GEDCOM records" / "all Blocks" sentinels.
     *
     * @return array{gedcom: bool, blocks: bool, rectypes: array<int,string>}
     *         rectypes = GEDCOM record types for the GEDCOM query (empty =
     *         every type) when gedcom is true, or the block module names for
     *         the block query when blocks is true.
     */
    public static function rectypeSources(string $rectype): array {
        return LinkIndexService::rectypeSources($rectype);
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
        return LinkIndexService::supportsRegexp();
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
        return LinkIndexService::supportedGedcomTableKeys();
    }

    public static function supportedGedcomRecordKeys(): array
    {
        return LinkIndexService::supportedGedcomRecordKeys();
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
        return LinkIndexService::getRecordsQuery($tree, $xref, $rectypes, $ordered);
    }

    /**
     * Subquery for block entries containing LE links. One subquery per module,
     * UNION ALL'd. Always a live scan (no index).
     *
     * @param Tree|null        $tree       restrict to one tree (global blocks always included)
     * @param array<int,string> $rectypes  active rectype filter (empty = all)
     * @param bool             $index_mode true = index column shape (file, xref, type, block_id)
     *                                    false = live column shape (xref, file, type, gedcom, block_id)
     * @param int|null         $user_id    when set, restrict to tree-level blocks (user_id IS NULL)
     *                                    and personal blocks owned by this user (D5)
     * @param string           $filter_xref coarse SQL pre-filter: only blocks whose text
     *                                    settings contain this XREF (LIKE-based)
     *
     * @return Builder|null null when no block module matches the rectype filter
     */
    public static function getBlockQuery(Tree|null $tree, array $rectypes, bool $index_mode = false, int|null $user_id = null, string $filter_xref = ''): ?Builder {
        return LinkIndexService::getBlockQuery($tree, $rectypes, $index_mode, $user_id, $filter_xref);
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
                $offset = (int) $match[1];

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
                    'snippet' => self::snippet($text, (int) $match[1], $match[0]),
                ];
            }
        }

        ksort($found);

        return array_values($found);
    }

    /**
     * Classify LE links in HTML block content (<a href="#@...">text</a>).
     * No pic class, no classic @XREF@ pass.
     *
     * @return array<int, array{class: string, token: string, snippet: string}>
     */
    public static function classifyHtmlLinks(string $text): array {
        $found = [];

        if (preg_match_all(self::RE_LE_HTML_LINK, $text, $m1, PREG_OFFSET_CAPTURE) !== false) {
            foreach ($m1[0] as $match) {
                $token  = $match[0];
                $offset = (int) $match[1];

                $href = self::extractHtmlLinkHref($token);
                if ($href === null) {
                    continue;
                }

                $le_params = $href['value'];
                $class     = preg_match(self::RE_WT_PARAM, $le_params) === 1 ? 'xref' : 'ext';

                $found[$offset] = [
                    'class'   => $class,
                    'token'   => $token,
                    'snippet' => self::snippet($text, $offset, $token),
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
        return LinkRenderer::emptyCounts();
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


    public static function getClassLabel(string $class): string
    {
        return LinkRenderer::getClassLabel($class);
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
      * $highlight_problems: when true, each missing/mismatch target is wrapped
      * in a <mark class="le-problem-mark"> so it stands out (the admin "only
      * broken targets" filter). false (default) = no mark, unchanged output.
      *
      * $ambiguous_linker: when non-null, a "missing" UID-length target with no
      * explicit tree is additionally resolved against the other trees. The
      * linker returns the global matches ("not unique" state) or null when the
      * id is not such a case (see XrefsService::ambiguousTargetHtml).
      * Signature: fn(string $id, ?string $target_tree_name):
      * ?array{count: int, hits: array<int, array{name: string, url: string, tree_label: string}>, goto_url: string}.
      * null (default) = no ambiguous resolution, unchanged output.
      *
      * $show_snippets: when false (the non-admin overview, where raw GEDCOM
      * text is privacy-sensitive), each entry renders the link token itself
      * without the surrounding snippet context. true (default) = unchanged.
      *
      * @param array<int, array{path: string, class: string, token: string, snippet: string}> $entries
      * @param callable(string, ?string): (array{name: string, url: string, tree_label: string}|null)|null $target_linker
      * @param bool $highlight_problems wrap missing/mismatch targets in a <mark>
      * @param callable(string, ?string): array{count: int, hits: array<int, array{name: string, url: string, tree_label: string}>, goto_url: string}|null $ambiguous_linker
      * @param bool $show_snippets render the snippet context around the token
      */
    public static function linkInventoryHtml(array $entries, int $max_per_class = self::LINKS_PER_CLASS_DEFAULT, string $highlight_xref = '', ?callable $target_linker = null, bool $highlight_problems = false, ?callable $ambiguous_linker = null, bool $show_snippets = true): string {
        return LinkRenderer::linkInventoryHtml($entries, $max_per_class, $highlight_xref, $target_linker, $highlight_problems, $ambiguous_linker, $show_snippets);
    }

    /**
     * True when any resolvable target of the given inventory is "missing"
     * (unresolvable target, XREF or UID) or a "mismatch" (declared type
     * differs from the actual record tag). Shares its decision with
     * targetLinksHtml() via
     * expectedTagFor()/targetStatus(). A null $target_linker (or an inventory
     * without xref/classic/pic targets) yields false.
     *
     * @param array<int, array{path: string, class: string, token: string, snippet: string}> $entries
     * @param callable(string, ?string): (array{name: string, url: string, tree_label: string, actual: string}|null)|null $target_linker
     */
    public static function inventoryHasProblem(array $entries, ?callable $target_linker): bool {
        return LinkRenderer::inventoryHasProblem($entries, $target_linker);
    }

    /**
     * HTML for the "count" column: total plus the per-class breakdown.
     *
     * @param array<string, int> $counts
     */
    public static function linkCountSummary(array $counts): string {
        return LinkRenderer::linkCountSummary($counts);
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
     * The "xref" key carries the target reference - a record XREF or a UID.
     * The wt=/id= classes are widened via RE_REF_CLASS to accept UID-length
     * values; a classic @XREF@ stays XREF-only (RE_CLASSIC_XREF).
     *
     * @return array<int, array{xref: string, tree: string|null, type: string|null}>
     */
    public static function extractLinkTargets(string $token): array {
        if (preg_match(self::RE_CLASSIC_XREF, $token, $match) === 1) {
            return [['xref' => $match[1], 'tree' => null, 'type' => null]];
        }

        $targets = [];
        $url = self::extractLeLinkUrl($token);
        if ($url !== null) {
            if (preg_match_all(self::RE_WT_TARGET, $url, $matches, PREG_SET_ORDER) !== false) {
                foreach ($matches as $m) {
                    $url_tree = $m['tree'] !== '' ? self::stripDiaSuffix($m['tree']) : '';
                    $targets[] = [
                        'xref' => $m['xref'],
                        'tree' => $url_tree !== '' ? $url_tree : null,
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
        } elseif (str_contains($token, 'href="') || str_contains($token, "href='")) {
            $href = self::extractHtmlLinkHref($token);
            if ($href !== null) {
                $url = $href['value'];

                if (preg_match_all(self::RE_WT_TARGET, $url, $matches, PREG_SET_ORDER) !== false) {
                    foreach ($matches as $m) {
                        $targets[] = [
                            'xref' => $m['xref'],
                            'tree' => $m['tree'] !== '' ? self::stripDiaSuffix($m['tree']) : null,
                            'type' => $m['type'] !== '' ? $m['type'] : null,
                        ];
                    }
                }
                if (preg_match(self::RE_ID_TARGET, $url, $m) === 1) {
                    $targets[] = [
                        'xref' => $m[1],
                        'tree' => null,
                        'type' => null,
                    ];
                }
            }
        }

        return $targets;
    }

    /**
     * Extract all wt= targets from GEDCOM text (Markdown LE links only —
     * GEDCOM records never contain HTML; block settings do, and those go
     * through processBlocks()).
     *
     * @return array<int, array{xref: string, tree: string, type: string}>
     */
    public static function extractWtTargetsFromGedcom(string $gedcom, string $source_tree_name): array
    {
        $targets = [];

        if (preg_match_all(self::RE_LE_LINK, $gedcom, $link_matches, PREG_SET_ORDER) !== false) {
            foreach ($link_matches as $lm) {
                $url = self::extractLeLinkUrl($lm[0]);
                if ($url === null) {
                    continue;
                }
                if (preg_match_all(self::RE_WT_TARGET, $url, $wt_matches, PREG_SET_ORDER) !== false) {
                    foreach ($wt_matches as $m) {
                        $targets[] = [
                            'xref' => $m['xref'],
                            'tree' => ($m['tree'] === '' ? $source_tree_name : self::stripDiaSuffix((string) $m['tree'])),
                            'type' => (string) $m['type'],
                        ];
                    }
                }
            }
        }

        return $targets;
    }

    /**
     * Extract the URL part from a Markdown LE link token.
     * Token format: [text](#@url) / ![pic](#@url)
     * Returns the URL between "(#@" and the closing ")" (exclusive).
     * Returns null when the token does not contain "(#@".
     */
    public static function extractLeLinkUrl(string $token): ?string
    {
        $pos = strrpos($token, '(#@');
        if ($pos === false) {
            return null;
        }
        return substr($token, $pos + 3, -1);
    }

    /**
     * Extract the href value from an HTML anchor token (matched by RE_LE_HTML_LINK).
     * Returns ['value' => string, 'is_le' => bool] or null when no href found.
     * The "#@" prefix is stripped from value when present (is_le = true).
     */
    public static function extractHtmlLinkHref(string $token): ?array
    {
        $href_pos = stripos($token, 'href="');
        $quote    = '"';
        if ($href_pos === false) {
            $href_pos = stripos($token, "href='");
            $quote    = "'";
        }
        if ($href_pos === false) {
            return null;
        }
        $href_start = $href_pos + 6;
        $href_end   = strpos($token, $quote, $href_start);
        $href_val   = $href_end !== false
            ? substr($token, $href_start, $href_end - $href_start)
            : substr($token, $href_start);
        $is_le      = str_starts_with($href_val, '#@');

        return [
            'value'   => $is_le ? substr($href_val, 2) : $href_val,
            'is_le'   => $is_le,
            'start'   => $href_start,
            'end'     => $href_end,
        ];
    }

    /**
     *
     * Freshness comes from le_index_meta (written by the CLI at the end of
     * a full, untruncated run) - not from MAX(scanned_at), which would
     * go stale on a quiet database where changed records are re-scanned
     * but no new rows appear.
     *
     * @return array{rows: int, scanned_at: string|null, fresh: bool}
     */
    public static function indexStatus(int $fresh_seconds = self::INDEX_FRESH_SECONDS): array {
        return LinkIndexService::indexStatus($fresh_seconds);
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
        return LinkIndexService::getIndexQuery($tree, $target_xref, $rectypes, $ordered);
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
        return LinkIndexService::indexRowLinks($file, $xref, $rectype);
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
        $start  = max(0, $offset - self::SNIPPET_CONTEXT_CHARS);
        $end    = min($length, $offset + self::SNIPPET_CONTEXT_CHARS + strlen($token));

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