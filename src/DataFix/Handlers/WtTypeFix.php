<?php

declare(strict_types=1);

namespace Schwendinger\Webtrees\Module\LinkEnhancer\DataFix\Handlers;

use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\GedcomRecord;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\DataFixService;
use Fisharebest\Webtrees\Services\TreeService;
use Fisharebest\Webtrees\Tree;
use Illuminate\Support\Collection;
use Schwendinger\Webtrees\Module\LinkEnhancer\DataFix\FixHandlerInterface;
use Schwendinger\Webtrees\Module\LinkEnhancer\Services\IdResolver;
use Schwendinger\Webtrees\Module\LinkEnhancer\Services\IndexRebuildScheduler;
use Schwendinger\Webtrees\Module\LinkEnhancer\Services\UidIndexService;
use Schwendinger\Webtrees\Module\LinkEnhancer\Services\XrefsService;

use function preg_match_all;
use function preg_replace_callback;
use function strlen;
use function strrpos;
use function substr;

final class WtTypeFix implements FixHandlerInterface
{
    public const ID = 'wt_type_fix';

    private const MODE_SET    = 'set';
    private const MODE_REPAIR = 'repair';
    private const MODE_REMOVE = 'remove';

    private const TYPE_TAG_TO_LETTER = [
        'INDI' => 'i',
        'FAM'  => 'f',
        'SOUR' => 's',
        'REPO' => 'r',
        'NOTE' => 'n',
        '_LOC' => 'l',
    ];

    /** @var Tree[] tree_name => Tree */
    private array $tree_cache = [];

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return I18N::translate('Fix wt= type letter (set/repair/remove)');
    }

    public function optionsHtml(Tree $tree, array $params): string
    {
        $mode = (string) ($params['wt_type_mode'] ?? self::MODE_REPAIR);

        $html  = '<div class="row mb-3">';
        $html .= '<label class="col-sm-3 col-form-label">' . e(I18N::translate('Mode')) . '</label>';
        $html .= '<div class="col-sm-9">';
        $html .= '<select class="form-select" name="wt_type_mode">';
        $html .= '<option value="set" ' . ($mode === self::MODE_SET ? 'selected' : '') . '>' . e(I18N::translate('Set — add missing type letters')) . '</option>';
        $html .= '<option value="repair" ' . ($mode === self::MODE_REPAIR ? 'selected' : '') . '>' . e(I18N::translate('Repair — correct wrong type letters')) . '</option>';
        $html .= '<option value="remove" ' . ($mode === self::MODE_REMOVE ? 'selected' : '') . '>' . e(I18N::translate('Remove — strip all type letters')) . '</option>';
        $html .= '</select></div></div>';

        $html .= '<div class="alert alert-info small mb-3">';
        if ($mode === self::MODE_SET) {
            $html .= e(I18N::translate('Adds the correct type letter to wt= targets that have none (e.g. wt=@SOUR1@ → wt=s@SOUR1@).'));
        } elseif ($mode === self::MODE_REMOVE) {
            $html .= e(I18N::translate('Removes ALL type letters from wt= targets (e.g. wt=i@INDI1@ → wt=@INDI1@). The letters were originally only needed for JavaScript routing.'));
        } else {
            $html .= e(I18N::translate('Corrects the type letter when it does not match the resolved target record (e.g. wt=i@SOUR1@ → wt=s@SOUR1@).'));
        }
        $html .= '</div>';

        return $html;
    }

    public function recordsToFix(Tree $tree, array $params): Collection
    {
        $rows = DB::table(XrefsService::INDEX_LINK_TABLE)
            ->where('file', '=', $tree->id())
            ->whereNotNull('target_xref')
            ->distinct()
            ->select('file', 'xref', 'rectype')
            ->get();

        return $rows
            ->map(static fn (object $row): object => (object) ['xref' => $row->xref, 'type' => $row->rectype])
            ->values();
    }

    public function needsUpdate(GedcomRecord $record, array $params): bool
    {
        $mode = (string) ($params['wt_type_mode'] ?? self::MODE_REPAIR);
        $pattern = match ($mode) {
            self::MODE_SET => XrefsService::getReWtTarget(false),
            default => XrefsService::getReWtTarget(true) // repair and remove - type char must be set
        };
        return (bool) preg_match($pattern, $record->gedcom());
    }

    public function preview(GedcomRecord $record, array $params): string
    {
        $result = $this->convert($record->gedcom(), $record->tree(), $params);
        $data_fix_service = Registry::container()->get(DataFixService::class);

        return $data_fix_service->gedcomDiff($record->tree(), $record->gedcom(), $result['gedcom']);
    }

    public function apply(GedcomRecord $record, array $params): void
    {
        $tree   = $record->tree();
        $result = $this->convert($record->gedcom(), $tree, $params);

        if ($result['fixed'] > 0) {
            $record->updateRecord($result['gedcom'], false);

            if (IndexRebuildScheduler::canDefer(IndexRebuildScheduler::EVENT_LINK_INDEX)) {
                IndexRebuildScheduler::defer(['link' => true, 'uid' => false], $tree->id());
            }
        }
    }

    public function processBlocks(Tree $tree, array $params): array
    {
        return ['processed' => 0, 'changed' => 0, 'skipped' => 0, 'errors' => [], 'details' => []];
    }

    /**
     * @param array<string, string> $params
     * @return array{gedcom: string, fixed: int, skipped: int}
     */
    private function convert(string $gedcom, Tree $source_tree, array $params): array
    {
        $mode    = (string) ($params['wt_type_mode'] ?? self::MODE_REPAIR);
        $fixed   = 0;
        $skipped = 0;

        // Markdown le-links: [text](#@url) / ![pic](#@url)
        $gedcom = preg_replace_callback(
            XrefsService::RE_LE_LINK,
            function (array $m) use ($mode, $source_tree, &$fixed, &$skipped): string {
                $token = $m[0];
                $pos   = strrpos($token, '(#@');
                if ($pos === false) {
                    return $token;
                }
                $head = substr($token, 0, $pos + 3);
                $url  = $this->fixWtTargets(
                    substr($token, $pos + 3, -1),
                    $mode,
                    $source_tree,
                    $fixed,
                    $skipped
                );
                return $head . $url . ')';
            },
            $gedcom
        );

        return ['gedcom' => $gedcom, 'fixed' => $fixed, 'skipped' => $skipped];
    }

    /**
     * Fix all wt= type letters in one extracted URL (RE_WT_TARGET is designed
     * for the extracted URL, not the full GEDCOM text).
     */
    private function fixWtTargets(
        string $url,
        string $mode,
        Tree $source_tree,
        int &$fixed,
        int &$skipped
    ): string {
        if (!preg_match_all(XrefsService::RE_WT_TARGET, $url, $matches, PREG_SET_ORDER)) {
            return $url;
        }

        return preg_replace_callback(
            XrefsService::RE_WT_TARGET,
            function (array $m) use ($mode, $source_tree, &$fixed, &$skipped): string {
                $type_letter = $m['type'];
                $ref         = $m['xref'];
                $tree_name   = ($m['tree'] === '' ? $source_tree->name() : XrefsService::stripDiaSuffix((string) $m['tree']));

                // Mode REMOVE: strip all type letters, no resolution needed
                if ($mode === self::MODE_REMOVE) {
                    if ($type_letter !== '') {
                        $fixed++;
                        return str_replace('wt=' . $type_letter . '@', 'wt=@', $m[0]);
                    }
                    return $m[0];
                }

                // Modes SET and REPAIR need the actual target type
                $actual_tag = $this->resolveTargetType($ref, $tree_name);
                if ($actual_tag === null) {
                    $skipped++;
                    return $m[0];
                }

                $expected_letter = self::TYPE_TAG_TO_LETTER[$actual_tag] ?? null;

                if ($mode === self::MODE_SET) {
                    if ($type_letter !== '' || $expected_letter === null) {
                        return $m[0];
                    }
                    $fixed++;
                    return str_replace('wt=@', 'wt=' . $expected_letter . '@', $m[0]);
                }

                // MODE REPAIR (default)
                if ($type_letter === '' || $expected_letter === null) {
                    return $m[0];
                }
                if ($type_letter === $expected_letter) {
                    return $m[0];
                }
                $fixed++;
                return str_replace('wt=' . $type_letter . '@', 'wt=' . $expected_letter . '@', $m[0]);
            },
            $url
        );
    }

    private function resolveTargetType(string $ref, string $target_tree_name): ?string
    {
        $tree = $this->findTree($target_tree_name);
        if ($tree === null) {
            return null;
        }

        if (strlen($ref) < IdResolver::UID_MIN_LENGTH) {
            $record = Registry::gedcomRecordFactory()->make($ref, $tree);
            return $record instanceof GedcomRecord ? $record->tag() : null;
        }

        $results = UidIndexService::lookup($ref, $tree->id());
        if ($results->isEmpty() || $results->count() > 1) {
            return null;
        }

        $record = Registry::gedcomRecordFactory()->make((string) $results->first()->xref, $tree);
        return $record instanceof GedcomRecord ? $record->tag() : null;
    }

    private function findTree(string $name): ?Tree
    {
        if (array_key_exists($name, $this->tree_cache)) {
            return $this->tree_cache[$name];
        }
        try {
            $tree = Registry::container()->get(TreeService::class)->all()->get($name);
            $this->tree_cache[$name] = $tree;
            return $tree;
        } catch (\Throwable) {
            $this->tree_cache[$name] = null;
            return null;
        }
    }
}
