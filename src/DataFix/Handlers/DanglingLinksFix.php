<?php

declare(strict_types=1);

namespace Schwendinger\Webtrees\Module\LinkEnhancer\DataFix\Handlers;

use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\GedcomRecord;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\TreeService;
use Fisharebest\Webtrees\Tree;
use Illuminate\Support\Collection;
use Schwendinger\Webtrees\Module\LinkEnhancer\DataFix\FixHandlerInterface;
use Schwendinger\Webtrees\Module\LinkEnhancer\Services\IdResolver;
use Schwendinger\Webtrees\Module\LinkEnhancer\Services\UidIndexService;
use Schwendinger\Webtrees\Module\LinkEnhancer\Services\XrefsService;

use function preg_match;
use function strlen;

final class DanglingLinksFix implements FixHandlerInterface
{
    public const ID = 'dangling_links';

    /** @var Tree[] */
    private array $tree_cache = [];

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return I18N::translate('Report dangling link targets');
    }

    public function optionsHtml(Tree $tree, array $params): string
    {
        return '<div class="alert alert-info">' . e(I18N::translate(
            'This fix reports le-links whose target record does not exist. No changes are made (report-only).'
        )) . '</div>';
    }

    public function recordsToFix(Tree $tree, array $params): Collection
    {
        $rows = DB::table(XrefsService::INDEX_LINK_TABLE)
            ->where('file', '=', $tree->id())
            ->distinct()
            ->select('file', 'xref', 'rectype')
            ->get();

        return $rows
            ->map(static fn (object $row): object => (object) ['xref' => $row->xref, 'type' => $row->rectype])
            ->values();
    }

    public function needsUpdate(GedcomRecord $record, array $params): bool
    {
        // Permissive pre-check: does the GEDCOM contain any wt= targets?
        if (!preg_match('/[?&@]wt=[a-z]?@[A-Za-z0-9][A-Za-z0-9:_.-]{0,254}@/', $record->gedcom())) {
            return false;
        }

        $tree = $record->tree();
        foreach (XrefsService::extractWtTargetsFromGedcom($record->gedcom(), $tree->name()) as $target) {
            if (!$this->targetExists($target['xref'], $target['tree'], $tree)) {
                return true;
            }
        }

        return false;
    }

    public function preview(GedcomRecord $record, array $params): string
    {
        $tree     = $record->tree();
        $findings = [];

        foreach (XrefsService::extractWtTargetsFromGedcom($record->gedcom(), $tree->name()) as $target) {
            if (!$this->targetExists($target['xref'], $target['tree'], $tree)) {
                $reason = strlen($target['xref']) < IdResolver::UID_MIN_LENGTH
                    ? I18N::translate('no record %s in tree "%s"', '@' . $target['xref'] . '@', $target['tree'])
                    : I18N::translate('UID not found in tree "%s"', $target['tree']);
                $findings[] = 'wt=' . $target['type'] . '@' . $target['xref'] . '@' . $target['tree'] . ' → ' . $reason;
            }
        }

        if ($findings === []) {
            return '<span class="text-muted">' . e(I18N::translate('No dangling links found.')) . '</span>';
        }

        $html = '<div class="alert alert-warning"><strong>' . e(I18N::translate(
            '%d missing target(s):', count($findings)
        )) . '</strong><ul>';
        foreach ($findings as $f) {
            $html .= '<li>' . e($f) . '</li>';
        }
        $html .= '</ul></div>';

        return $html;
    }

    public function apply(GedcomRecord $record, array $params): void
    {
    }

    public function processBlocks(Tree $tree, array $params): array
    {
        return ['processed' => 0, 'changed' => 0, 'skipped' => 0, 'errors' => []];
    }

    private function targetExists(string $ref, string $target_tree_name, Tree $source_tree): bool
    {
        $tree = $this->findTree($target_tree_name);
        if ($tree === null) {
            return false;
        }

        if (strlen($ref) < IdResolver::UID_MIN_LENGTH) {
            return Registry::gedcomRecordFactory()->make($ref, $tree) !== null;
        }

        $results = UidIndexService::lookup($ref, $tree->id());
        return !$results->isEmpty();
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
