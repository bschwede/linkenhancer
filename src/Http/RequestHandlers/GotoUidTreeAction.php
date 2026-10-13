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
class GotoUidTreeAction extends GotoUidAction
{
    // just a wrapper to register the same handler to more than one route in wt2.3
}
