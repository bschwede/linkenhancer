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

namespace Schwendinger\Webtrees\Module\LinkEnhancer;

use Fisharebest\Webtrees\I18N;

/**
 * Wrapper class for Fisharebest\Webtrees\I18N and special i18n cases
 * - methods with xlate prefix should be used for msgids already included in webtrees core POT file
 * - translate does not translate the msgid, it is meant to be an extraction marker for xgettext
 */
final class MoreI18N {

    /**
     * Extraction marker for xgettext (keyword "translate").
     *
     * The cron-jobs.php manifest is pure data, loaded in tick/CLI context where
     * no UI language is active - I18N::translate() must not run there (it also
     * applies sprintf() to its result). Wrapping a literal in
     * MoreI18N::translate() marks it for extraction (the last qualified name
     * component "translate" matches the xgettext keyword) while returning the
     * string unchanged. Actual translation happens at render time
     * (I18N::translate() in the views/services).
     *
     * Identity function: returns the argument unchanged.
     */
    public static function translate(string $text): string {
        return $text;
    }

    //-- inspired by: https://github.com/vesta-webtrees-2-custom-modules/vesta_common/blob/master/patchedWebtrees/MoreI18N.php
    //functionally same as I18N::translate,
    //different name prevents gettext from picking this up
    //(intention: use where already expected to be translated via main webtrees)
    public static function xlate(string $message, ...$args): string {
        return I18N::translate($message, ...$args);
    }

    //functionally same as I18N::translateContext,
    //different name prevents gettext from picking this up
    //(intention: use where already expected to be translated via main webtrees)
    public static function xlateContext(string $context, string $message, ...$args): string {
        return I18N::translateContext($context, $message, ...$args);
    }

    //functionally same as I18N::plural,
    //different name prevents gettext from picking this up
    //(intention: use where already expected to be translated via main webtrees)
    public static function xlatePlural(string $singular, string $plural, int $count, ...$args): string {
        return I18N::plural($singular, $plural, $count, ...$args);
    }    
}
