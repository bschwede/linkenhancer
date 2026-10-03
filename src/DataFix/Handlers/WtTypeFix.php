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
use function strlen;

final class WtTypeFix implements FixHandlerInterface
{
    public const ID = 'wt_type_fix';

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
        return I18N::translate('Fix wt= type letter (set/correct/remove)');
    }

    public function optionsHtml(Tree $tree, array $params): string
    {
        return '<div class="alert alert-info">' . e(I18N::translate(
            'This fix ensures the type letter in wt= link targets matches the resolved target record. '
            . 'It will set a missing letter, correct a wrong one, or remove a letter when the target type has no mapping (e.g. media objects).'
        )) . '</div>';
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
        return (bool) preg_match_all(XrefsService::RE_WT_TARGET, $record->gedcom(), $m, PREG_SET_ORDER) > 0;
    }

    public function preview(GedcomRecord $record, array $params): string
    {
        $result = $this->convert($record->gedcom(), $record->tree());
        $data_fix_service = Registry::container()->get(DataFixService::class);

        return $data_fix_service->gedcomDiff($record->tree(), $record->gedcom(), $result['gedcom']);
    }

    public function apply(GedcomRecord $record, array $params): void
    {
        $tree   = $record->tree();
        $result = $this->convert($record->gedcom(), $tree);

        if ($result['fixed'] > 0) {
            $record->updateRecord($result['gedcom'], false);

            if (IndexRebuildScheduler::canDefer(IndexRebuildScheduler::EVENT_LINK_INDEX)) {
                IndexRebuildScheduler::defer(['link' => true, 'uid' => false], $tree->id());
            }
        }
    }

    public function processBlocks(Tree $tree, array $params): array
    {
        return ['processed' => 0, 'changed' => 0, 'skipped' => 0, 'errors' => []];
    }

    /**
     * @return array{gedcom: string, fixed: int, skipped: int}
     */
    private function convert(string $gedcom, Tree $source_tree): array
    {
        $fixed   = 0;
        $skipped = 0;

        $gedcom = preg_match_all(XrefsService::RE_WT_TARGET, $gedcom, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) > 0
            ? preg_replace_callback(
                XrefsService::RE_WT_TARGET,
                function (array $m) use ($source_tree, &$fixed, &$skipped): string {
                    $type_letter = $m['type'];
                    $ref         = $m['xref'];
                    $tree_name   = ($m['tree'] === '' ? $source_tree->name() : (string) $m['tree']);

                    $actual_tag = $this->resolveTargetType($ref, $tree_name);
                    if ($actual_tag === null) {
                        $skipped++;
                        return $m[0];
                    }

                    $expected_letter = self::TYPE_TAG_TO_LETTER[$actual_tag] ?? null;

                    if ($expected_letter === null) {
                        if ($type_letter !== '') {
                            $fixed++;
                            return str_replace('wt=' . $type_letter . '@', 'wt=@', $m[0]);
                        }
                        return $m[0];
                    }

                    if ($type_letter === $expected_letter) {
                        return $m[0];
                    }

                    $fixed++;
                    if ($type_letter === '') {
                        return str_replace('wt=@', 'wt=' . $expected_letter . '@', $m[0]);
                    }
                    return str_replace('wt=' . $type_letter . '@', 'wt=' . $expected_letter . '@', $m[0]);
                },
                $gedcom
            )
            : $gedcom;

        return ['gedcom' => $gedcom, 'fixed' => $fixed, 'skipped' => $skipped];
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
