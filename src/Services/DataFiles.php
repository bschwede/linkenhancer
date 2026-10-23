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

namespace Schwendinger\Webtrees\Module\LinkEnhancer\Services;

use Fisharebest\Webtrees\Webtrees;

use function is_dir;
use function mkdir;

/**
 * The module's data files, gathered in data/linkenhancer/.
 *
 * webtrees' data/ directory is shared with the core and other modules, so
 * every file this module creates there (locks, cached TOC data) lives in its
 * own subdirectory. Single source of truth for those paths:
 *
 *   data/linkenhancer/build-link-index.lock    link index build single-instance lock
 *   data/linkenhancer/build-uid-index.lock     UID index build single-instance lock
 *   data/linkenhancer/wthb-toc-lang/           WTHB TOC multilingual data (JSON + state)
 *
 * Files created before the subdirectory existed (flat data/linkenhancer-*)
 * are ignored (clean break) and may be deleted.
 */
final class DataFiles {

    /**
     * The module's data subdirectory (data/linkenhancer/), created on demand.
     */
    public static function dir(): string
    {
        $dir = Webtrees::DATA_DIR . 'linkenhancer/';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        return $dir;
    }

    /**
     * Absolute path of one control file in data/linkenhancer/.
     */
    public static function path(string $name): string
    {
        return self::dir() . $name;
    }
}
