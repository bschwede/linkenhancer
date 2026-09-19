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

namespace Schwendinger\Webtrees\Module\LinkEnhancer\Http\RequestHandlers;

use Fisharebest\Webtrees\FlashMessages;
use Fisharebest\Webtrees\GedcomRecord;
use Fisharebest\Webtrees\Http\Exceptions\HttpNotFoundException;
use Fisharebest\Webtrees\Http\ViewResponseTrait;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\TreeService;
use Fisharebest\Webtrees\Site;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\Validator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Schwendinger\Webtrees\Module\LinkEnhancer\Services\IdResolver;

use function array_map;
use function count;
use function method_exists;
use function redirect;
use function trim;

/**
 * Resolve an id (a record XREF or a UID) to a record. The standard navigation
 * target for every linkenhancer link (see
 * .opencode/plans/linkenhancer-uid-link-target-cross-tree-goto.md, Teil C).
 *
 * Routes: /tree/{tree}/goto-id/{id} (tree-scoped) and /goto-id/{id} (global).
 * Resolves via the shared IdResolver (length-aware, bidirectional XREF/UID).
 * Behaviour: exactly one visible record -> redirect, several -> selection
 * list, none -> 404. Records the user may not see are filtered out before
 * counting (R3). A tree-scoped request that finds nothing in the tree falls
 * back to a global lookup and reports the cross-tree hit with a flash message
 * (A1/C1). Unlike goto-xref there is no isXref() gate: the {id} parameter is
 * read verbatim (loose string) so it may be an XREF or a UID.
 */
class GotoIdAction implements RequestHandlerInterface
{
    use ViewResponseTrait;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $tree = Validator::attributes($request)->treeOptional();
        $id   = Validator::attributes($request)->string('id');

        $candidates = IdResolver::candidates($id, $tree);
        $cross      = false;

        // A1/C1: a tree-scoped request that finds nothing in the tree falls back
        // to a global lookup so a hit living in another tree is not lost. The
        // global route already searches all trees, so no fallback applies there.
        if ($candidates === [] && $tree !== null) {
            $candidates = IdResolver::candidates($id, null);
            $cross      = true;
        }

        if ($candidates === []) {
            throw new HttpNotFoundException(
                I18N::translate('No record for %s was found.', $id)
            );
        }

        if (count($candidates) === 1) {
            if ($cross) {
                FlashMessages::addMessage(I18N::translate('%s was found in another tree.', $id));
            }

            return redirect($candidates[0]['record']->url());
        }

        $hits = array_map(
            static fn (array $candidate): array => [
                'record'   => $candidate['record'],
                'uid'      => (string) $candidate['uid'],
                'rectype'  => $candidate['rectype'],
                'tag_path' => $candidate['tag_path'],
                'label'    => self::label($candidate['record']),
            ],
            $candidates
        );

        return $this->viewResponse('_linkenhancer_::goto-uid-select', [
            'title' => I18N::translate('Multiple records for %s', $id),
            'tree'  => $this->headerTree($tree),
            'uid'   => $id,
            'hits'  => $hits,
        ]);
    }

    /**
     * The tree the page layout shows in its header (genealogy menu, tree
     * title, header search): the tree-scoped route's tree, otherwise the
     * site's default tree (HomePage pattern) so the global selection page
     * still gets a full header. Null when the user can see no tree at all.
     */
    private function headerTree(?Tree $request_tree): ?Tree
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
    private static function label(GedcomRecord $record): string
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
