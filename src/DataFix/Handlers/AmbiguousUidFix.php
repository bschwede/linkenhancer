<?php

declare(strict_types=1);

namespace Schwendinger\Webtrees\Module\LinkEnhancer\DataFix\Handlers;

use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\GedcomRecord;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Tree;
use Illuminate\Support\Collection;
use Schwendinger\Webtrees\Module\LinkEnhancer\DataFix\FixHandlerInterface;
use Schwendinger\Webtrees\Module\LinkEnhancer\DataFix\TreeLookupTrait;
use Schwendinger\Webtrees\Module\LinkEnhancer\Services\IdResolver;
use Schwendinger\Webtrees\Module\LinkEnhancer\Services\UidIndexService;
use Schwendinger\Webtrees\Module\LinkEnhancer\Services\XrefsService;

use function preg_match;
use function strlen;

final class AmbiguousUidFix implements FixHandlerInterface
{
    use TreeLookupTrait;

    public const ID = 'ambiguous_uid';

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return I18N::translate('Report ambiguous UID targets');
    }

    public function optionsHtml(Tree $tree, array $params): string
    {
        return '<div class="alert alert-info">' . e(I18N::translate(
            'This fix reports UID targets that resolve to multiple records (ambiguous). No changes are made (report-only).'
        )) . '</div>';
    }

    public function recordsToFix(Tree $tree, array $params): Collection
    {
        $rows = DB::table(XrefsService::INDEX_LINK_TABLE)
            ->where('file', '=', $tree->id())
            ->whereNotNull('target_uid')
            ->distinct()
            ->select('file', 'xref', 'rectype')
            ->get();

        return $rows
            ->map(static fn (object $row): object => (object) ['xref' => $row->xref, 'type' => $row->rectype])
            ->values();
    }

    public function needsUpdate(GedcomRecord $record, array $params): bool
    {
        // Permissive pre-check: does the GEDCOM contain any UID-length wt= targets?
        if (!preg_match('/[?&@]wt=[a-z]?@[A-Za-z0-9][A-Za-z0-9:_.-]{17,254}@/', $record->gedcom())) {
            return false;
        }

        $tree = $record->tree();
        foreach (XrefsService::extractWtTargetsFromGedcom($record->gedcom(), $tree->name()) as $target) {
            if (strlen($target['xref']) < IdResolver::UID_MIN_LENGTH) {
                continue;
            }
            $t = $this->findTree($target['tree']);
            if ($t === null) {
                continue;
            }
            $results = UidIndexService::lookup($target['xref'], $t->id());
            if ($results->count() > 1) {
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
            if (strlen($target['xref']) < IdResolver::UID_MIN_LENGTH) {
                continue;
            }
            $t = $this->findTree($target['tree']);
            if ($t === null) {
                continue;
            }
            $results = UidIndexService::lookup($target['xref'], $t->id());
            if ($results->count() > 1) {
                $xrefs = $results->map(static fn (object $r): string => '@' . $r->xref . '@')->implode(', ');
                $findings[] = $target['xref'] . ' → ' . $results->count() . ' ' . I18N::translate('records') . ': ' . $xrefs;
            }
        }

        if ($findings === []) {
            return '<span class="text-muted">' . e(I18N::translate('No ambiguous UIDs found.')) . '</span>';
        }

        $html = '<div class="alert alert-warning"><strong>' . e(I18N::translate(
            '%d ambiguous UID target(s):', count($findings)
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
}
