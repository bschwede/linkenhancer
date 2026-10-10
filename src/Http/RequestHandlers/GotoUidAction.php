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

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\FlashMessages;
use Fisharebest\Webtrees\GedcomRecord;
use Fisharebest\Webtrees\Http\Exceptions\HttpException;
use Fisharebest\Webtrees\Http\Exceptions\HttpNotFoundException;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\TreeService;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\Validator;
use Illuminate\Support\Collection;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Schwendinger\Webtrees\Module\LinkEnhancer\LinkEnhancerModule;
use Schwendinger\Webtrees\Module\LinkEnhancer\Services\UidIndexService;

use function count;
use function redirect;

/**
 * Resolve a UID (_UID in GEDCOM 5.5.1 / UID in GEDCOM 7.0) to a record.
 *
 * Routes: /tree/{tree}/goto-uid/{uid} (tree-scoped) and /goto-uid/{uid}
 * (global). Behaviour (D2): exactly one visible record -> redirect, several ->
 * selection list, none -> 404. Records the user may not see are filtered out
 * before counting, so a hidden record is neither leaked nor counted (R3). The
 * UID index is read-only here - there is no live scan (D4). The {uid} route
 * parameter is read verbatim; the Aura router default [^/]+ already covers
 * every UID form, so no extra validation is needed (R2). A tree-scoped request
 * that finds nothing in the tree falls back to a global lookup and reports the
 * cross-tree hit with a flash message (A1).
 */
class GotoUidAction extends AbstractGotoAction
{

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $tree = Validator::attributes($request)->treeOptional();
        $uid  = Validator::attributes($request)->string('uid');

        $visible = $this->visibleRecords(UidIndexService::lookup($uid, $tree?->id()));
        $cross   = false;

        // A1: a tree-scoped request that finds nothing in the tree falls back to
        // a global lookup so a hit living in another tree is not lost. The
        // global route already searches all trees, so no fallback applies there.
        if ($visible === [] && $tree !== null) {
            $visible = $this->visibleRecords(UidIndexService::lookup($uid, null));
            $cross   = true;
        }

        if ($visible === []) {
            throw new HttpNotFoundException(
                I18N::translate('No record with UID %s was found. The UID index may not be available yet.', $uid)
            );
        }

        if (count($visible) === 1) {
            if ($cross) {
                FlashMessages::addMessage(I18N::translate('UID %s was found in another tree.', $uid));
            }

            return redirect($visible[0]['record']->url());
        }

        return $this->viewResponse(LinkEnhancerModule::MODULE_NAME . '::goto-uid-select', [
            'title' => I18N::translate('Multiple records for UID %s', $uid),
            'tree'  => $this->headerTree($tree),
            'uid'   => $uid,
            'hits'  => $visible,
        ]);
    }

    /**
     * Resolve the raw index rows to records the current user is allowed to see.
     *
     * @return array<int, array{record: GedcomRecord, uid: string, rectype: string, tag_path: string, label: string}>
     */
    private function visibleRecords(Collection $hits): array
    {
        $tree_service = Registry::container()->get(TreeService::class);
        $visible      = [];

        foreach ($hits as $hit) {
            // find() throws (non-nullable) on a missing/inaccessible tree; use a
            // null-safe scan so a stale index row is skipped, not fatal (R3).
            $tree = $tree_service->all()->first(static fn (Tree $t): bool => $t->id() === (int) $hit->file);
            if (!$tree instanceof Tree) {
                continue;
            }

            $record = Registry::gedcomRecordFactory()->make((string) $hit->xref, $tree);
            if (!$record instanceof GedcomRecord) {
                continue;
            }

            try {
                Auth::checkRecordAccess($record, false);
            } catch (HttpException) {
                continue; // not visible - do not leak its existence (R3)
            }

            $visible[] = [
                'record'   => $record,
                'uid'      => (string) $hit->uid,
                'rectype'  => (string) $hit->rectype,
                'tag_path' => (string) $hit->tag_path,
                'label'    => static::recordLabel($record),
            ];
        }

        return $visible;
    }
}
