<?php

declare(strict_types=1);

namespace Schwendinger\Webtrees\Module\LinkEnhancer\Services;

use Fisharebest\Webtrees\I18N;
use Schwendinger\Webtrees\Module\LinkEnhancer\Services\XrefsService;

use function array_fill_keys;
use function array_slice;
use function count;
use function e;
use function explode;
use function implode;
use function in_array;
use function preg_quote;
use function preg_replace;
use function str_replace;

/**
 * HTML rendering for the link inventory (XREF overview table).
 * Extracted from XrefsService — pure presentation, no DB access.
 */
final class LinkRenderer
{
    public const TARGET_NOT_FOUND_GLYPH = XrefsService::TARGET_NOT_FOUND_GLYPH;
    public const TARGET_TYPE_MISMATCH_GLYPH = XrefsService::TARGET_TYPE_MISMATCH_GLYPH;
    public const TARGET_AMBIGUOUS_GLYPH = XrefsService::TARGET_AMBIGUOUS_GLYPH;

    public static function emptyCounts(): array
    {
        return array_fill_keys(XrefsService::LINK_CLASSES, 0);
    }

    public static function getClassLabel(string $class): string
    {
        return match($class) {
            'xref'    => I18N::translate('Cross-references'),
            'ext'     => I18N::translate('External links'),
            'pic'     => I18N::translate('Pictures'),
            'classic' => I18N::translate('Classic cross-references'),
            'other'   => I18N::translate('Other - maybe damaged - links'),
            default   => $class
        };
    }

    /**
     * @param array<int, array{path: string, class: string, token: string, snippet: string}> $entries
     * @param callable(string, ?string): (array{name: string, url: string, tree_label: string}|null)|null $target_linker
     * @param callable(string, ?string): array{count: int, hits: array<int, array{name: string, url: string, tree_label: string}>, goto_url: string}|null $ambiguous_linker
     */
    public static function linkInventoryHtml(array $entries, int $max_per_class = XrefsService::LINKS_PER_CLASS_DEFAULT, string $highlight_xref = '', ?callable $target_linker = null, bool $highlight_problems = false, ?callable $ambiguous_linker = null, bool $show_snippets = true): string
    {
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
        foreach (XrefsService::LINK_CLASSES as $class) {
            $class_items = $items[$class] ?? [];
            if ($class_items === []) {
                continue;
            }
            $shown = ($max_per_class > 0) ? array_slice($class_items, 0, $max_per_class) : $class_items;
            $class_label = self::getClassLabel($class);
            $html .= '<li><u>' . e($class_label) . ' (' . ($class !== $class_label ? e($class) . ': ' : '') . count($class_items) . ')</u><ol>';
            foreach ($shown as $entry) {
                $prefix = ($entry['path'] !== '' && $entry['path'] !== 'NOTE') ? e($entry['path']) . ': ' : '';
                if ($show_snippets) {
                    $parts = explode($entry['token'], $entry['snippet']);
                    $hl    = e(array_shift($parts));
                    foreach ($parts as $part) {
                        $hl .= '<strong>' . e($entry['token']) . '</strong>' . e($part);
                    }
                } else {
                    $hl = '<strong>' . e($entry['token']) . '</strong>';
                }
                if ($highlight_xref !== '') {
                    $pattern = '/(?<![A-Za-z0-9])' . preg_quote($highlight_xref, '/') . '(?![A-Za-z0-9])/';
                    $hl      = (string) preg_replace($pattern, '<mark class="le-xref-target">$0</mark>', $hl);
                }
                $target_html = self::targetLinksHtml($entry, $target_linker, $highlight_problems, $ambiguous_linker);
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
     * @param array{class: string, token: string} $entry
     * @param callable(string, ?string): (array{name: string, url: string, tree_label: string, actual: string}|null)|null $target_linker
     * @param callable(string, ?string): array{count: int, hits: array<int, array{name: string, url: string, tree_label: string}>, goto_url: string}|null $ambiguous_linker
     */
    private static function targetLinksHtml(array $entry, ?callable $target_linker, bool $highlight_problems = false, ?callable $ambiguous_linker = null): string
    {
        if ($target_linker === null || !in_array($entry['class'], ['xref', 'classic', 'pic'], true)) {
            return '';
        }

        $links = [];
        foreach (XrefsService::extractLinkTargets($entry['token']) as $target) {
            $expected_tag = self::expectedTagFor($target, $entry['class']);
            $resolved     = $target_linker($target['xref'], $target['tree']);
            $status       = self::targetStatus($resolved, $expected_tag);

            if ($status === 'missing') {
                $ambiguous = ($ambiguous_linker !== null)
                    ? $ambiguous_linker($target['xref'], $target['tree'])
                    : null;
                if ($ambiguous !== null) {
                    $links[] = self::ambiguousTargetHtml($ambiguous, $target['xref']);
                    continue;
                }

                $problem = '<span class="le-target-missing" title="' . e(I18N::translate("target not found")). '">' . self::TARGET_NOT_FOUND_GLYPH . ($target['tree'] ? ' ' . $target['tree'] . ': ' : '') . ' @' . e($target['xref']) . '@</span>';
                $links[] = $highlight_problems ? '<mark class="le-problem-mark">' . $problem . '</mark>' : $problem;
                continue;
            }

            $label  = ($resolved['tree_label'] !== '')
                ? '<span class="text-muted small">' . e($resolved['tree_label']) . ': </span> '
                : '';
            $anchor = '<span class="le-cross-ref" title="' . e(I18N::translate('Cross-reference')) . '">↪</span> ' . $label . '<a href="' . e($resolved['url']) . '">' . $resolved['name'] . '</a>';
            if ($status === 'mismatch') {
                $hint    = I18N::translate('expected %1$s, is %2$s', $expected_tag, $resolved['actual']);
                $problem = '<span class="le-target-type-mismatch" title="' . e($hint) . '">' . self::TARGET_TYPE_MISMATCH_GLYPH . '</span>';
                $anchor .= ' ' . ($highlight_problems ? '<mark class="le-problem-mark">' . $problem . '</mark>' : $problem);
            }
            $links[] = $anchor;
        }
        if ($links === []) {
            return '';
        }

        return '<div class="le-target-links">' . implode('<br>', $links) . '</div>';
    }

    /**
     * @param array{count: int, hits: array<int, array{name: string, url: string, tree_label: string}>, goto_url: string} $ambiguous
     */
    private static function ambiguousTargetHtml(array $ambiguous, string $uid): string
    {
        $count = (int) $ambiguous['count'];
        $html  = '<span class="le-target-ambiguous" title="'
            . e(I18N::translate('%1$s - %2$d matches in other trees', I18N::translate('target not unique'), $count))
            . '">' . self::TARGET_AMBIGUOUS_GLYPH . ' ' . $count . ' @' . e($uid) . '@</span>';

        if ($count <= 3) {
            foreach ($ambiguous['hits'] as $hit) {
                $label = ($hit['tree_label'] !== '')
                ? '<span class="text-muted small">' . e($hit['tree_label']) . ': </span> '
                : '';
                $html .= '<br><span class="le-cross-ref" title="' . e(I18N::translate('Cross-reference')) . '">↪</span> ' . $label . '<a href="' . e($hit['url']) . '">' . $hit['name'] . '</a>';
            }
        } elseif ($ambiguous['goto_url'] !== '') {
            $html .= '<br><span class="le-cross-ref" title="' . e(I18N::translate('Cross-reference')) . '">↪</span> <a href="' . e($ambiguous['goto_url']) . '">@' . e($uid) . '@ (' . $count . ')</a>';
        }

        return $html;
    }

    private static function expectedTagFor(array $target, string $class): ?string
    {
        return $target['type'] !== null
            ? (XrefsService::WT_TYPE_TAGS[$target['type']] ?? null)
            : ($class === 'pic' ? 'OBJE' : null);
    }

    private static function targetStatus(?array $resolved, ?string $expected_tag): string
    {
        if ($resolved === null) {
            return 'missing';
        }
        if ($expected_tag !== null && $resolved['actual'] !== $expected_tag) {
            return 'mismatch';
        }

        return 'ok';
    }

    /**
     * @param array<int, array{path: string, class: string, token: string, snippet: string}> $entries
     * @param callable(string, ?string): (array{name: string, url: string, tree_label: string, actual: string}|null)|null $target_linker
     */
    public static function inventoryHasProblem(array $entries, ?callable $target_linker): bool
    {
        if ($target_linker === null) {
            return false;
        }

        foreach ($entries as $entry) {
            if (!in_array($entry['class'], ['xref', 'classic', 'pic'], true)) {
                continue;
            }
            foreach (XrefsService::extractLinkTargets($entry['token']) as $target) {
                if (self::targetStatus($target_linker($target['xref'], $target['tree']), self::expectedTagFor($target, $entry['class'])) !== 'ok') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, int> $counts
     */
    public static function linkCountSummary(array $counts): string
    {
        $total = 0;
        $parts = [];
        foreach (XrefsService::LINK_CLASSES as $class) {
            $n = $counts[$class] ?? 0;
            $total += $n;
            if ($n > 0) {
                $parts[] = $class . ':&nbsp;' . $n;
            }
        }
        if ($total === 0) {
            return '0';
        }

        return '<strong>' . $total . '</strong><div class="text-muted"><small>' . implode('<br/>', $parts) . '</small></div>';
    }
}
