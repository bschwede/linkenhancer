<?php

declare(strict_types=1);

namespace Schwendinger\Webtrees\Module\LinkEnhancer\DataFix\Handlers;

use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\GedcomRecord;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Tree;
use Illuminate\Support\Collection;
use Schwendinger\Webtrees\Module\LinkEnhancer\DataFix\FixHandlerInterface;
use Schwendinger\Webtrees\Module\LinkEnhancer\Services\UidIndexService;

final class DuplicateUidFix implements FixHandlerInterface
{
    public const ID = 'duplicate_uid';

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return I18N::translate('Report duplicate UIDs in index');
    }

    public function optionsHtml(Tree $tree, array $params): string
    {
        return '<div class="alert alert-info">' . e(I18N::translate(
            'This fix reports UIDs that are assigned to multiple records in the UID index. No changes are made (report-only).'
        )) . '</div>';
    }

    public function recordsToFix(Tree $tree, array $params): Collection
    {
        $rows = DB::table(UidIndexService::UID_INDEX_TABLE . ' AS a')
            ->join(UidIndexService::UID_INDEX_TABLE . ' AS b', static function ($join): void {
                $join->on('a.uid', '=', 'b.uid')
                    ->on('a.file', '=', 'b.file')
                    ->whereColumn('a.xref', '<', 'b.xref');
            })
            ->where('a.file', '=', $tree->id())
            ->select(['a.uid', 'a.xref AS xref', 'a.rectype AS rectype'])
            ->get();

        return $rows
            ->map(static fn (object $row): object => (object) ['xref' => $row->xref, 'type' => $row->rectype])
            ->values();
    }

    public function needsUpdate(GedcomRecord $record, array $params): bool
    {
        return true;
    }

    public function preview(GedcomRecord $record, array $params): string
    {
        $tree = $record->tree();

        $duplicates = DB::table(UidIndexService::UID_INDEX_TABLE . ' AS a')
            ->join(UidIndexService::UID_INDEX_TABLE . ' AS b', static function ($join): void {
                $join->on('a.uid', '=', 'b.uid')
                    ->on('a.file', '=', 'b.file')
                    ->whereColumn('a.xref', '<', 'b.xref');
            })
            ->where('a.file', '=', $tree->id())
            ->where('a.xref', '=', $record->xref())
            ->select(['a.uid', 'a.xref AS xref1', 'a.rectype AS type1', 'b.xref AS xref2', 'b.rectype AS type2'])
            ->get();

        if ($duplicates->isEmpty()) {
            return '<span class="text-muted">' . e(I18N::translate('No duplicate UIDs found for this record.')) . '</span>';
        }

        $html = '<div class="alert alert-warning"><strong>' . e(I18N::translate(
            '%d duplicate UID(s) on this record:', $duplicates->count()
        )) . '</strong><ul>';
        foreach ($duplicates as $row) {
            $html .= '<li><code>' . e($row->uid) . '</code>: @' . e($row->xref1) . '@ (' . e($row->type1) . ') ⇄ @' . e($row->xref2) . '@ (' . e($row->type2) . ')</li>';
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
