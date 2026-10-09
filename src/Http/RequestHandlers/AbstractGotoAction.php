<?php

declare(strict_types=1);

namespace Schwendinger\Webtrees\Module\LinkEnhancer\Http\RequestHandlers;

use Fisharebest\Webtrees\GedcomRecord;
use Fisharebest\Webtrees\Http\ViewResponseTrait;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\TreeService;
use Fisharebest\Webtrees\Site;
use Fisharebest\Webtrees\Tree;
use Psr\Http\Server\RequestHandlerInterface;

use function method_exists;
use function trim;

abstract class AbstractGotoAction implements RequestHandlerInterface
{
    use ViewResponseTrait;

    /**
     * The tree the page layout shows in its header (genealogy menu, tree
     * title, header search): the tree-scoped route's tree, otherwise the
     * site's default tree (HomePage pattern) so the global selection page
     * still gets a full header. Null when the user can see no tree at all.
     */    
    protected function headerTree(?Tree $request_tree): ?Tree
    {
        if ($request_tree instanceof Tree) {
            return $request_tree;
        }

        $trees   = Registry::container()->get(TreeService::class)->all();
        $default = Site::getPreference('DEFAULT_GEDCOM');

        return $trees->get($default) ?? $trees->first();
    }

    /**
     * A short, user-facing label for a record: a human name when the record
     * type provides one (individual / family), otherwise its XREF.
     */    
    protected static function recordLabel(GedcomRecord $record): string
    {
        if (method_exists($record, 'fullName')) {
            $label = trim((string) $record->fullName());
            if ($label !== '') {
                return $label;
            }
        }

        return '@' . $record->xref() . '@';
    }
}
