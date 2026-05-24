<?php

declare(strict_types=1);

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

namespace Schwendinger\Webtrees\Module\LinkEnhancer\CommonMark;

//use Schwendinger\Webtrees\Module\LinkEnhancer\LinkEnhancerModule;
use function count, array_key_exists;

class SmartPunctHelper { // helper routines for SmartPunctExtension configuration

    // taken from:
    // - https://de.wikipedia.org/wiki/Anf%C3%BChrungszeichen#Andere_Sprachen
    // - https://en.wikipedia.org/wiki/Quotation_mark#Summary_table
    public const QUOTE_DEFS = [ // BCP47 language code => [d]ouble/[s]ingle quote [o]pener/[c]loser
        "af" => ["do" => "“", "dc" => "”", "so" => "‘", "sc" => "’"],
        "ar" => ["do" => "«", "dc" => "»", "so" => "‹", "sc" => "›"],
        "be" => ["do" => "«", "dc" => "»", "so" => "„", "sc" => "“"],
        "bg" => ["do" => "„", "dc" => "“", "so" => "‚", "sc" => "‘"],
        "ca" => ["do" => "«", "dc" => "»", "so" => "“", "sc" => "”"],
        "cs" => ["do" => "„", "dc" => "“", "so" => "‚", "sc" => "‘"],
        "da" => ["do" => "„", "dc" => "“", "so" => "‚", "sc" => "‘"],
        "de-CH" => ["do" => "«", "dc" => "»", "so" => "‹", "sc" => "›"],
        "de" => ["do" => "„", "dc" => "“", "so" => "‚", "sc" => "‘"],
        "el" => ["do" => "«", "dc" => "»", "so" => "“", "sc" => "”"],
        "en-GB" => ["do" => "‘", "dc" => "’", "so" => "“", "sc" => "”"],
        "en" => ["do" => "“", "dc" => "”", "so" => "‘", "sc" => "’"], // en-US
        "eo" => ["do" => "“", "dc" => "”", "so" => "", "sc" => ""],
        "es" => ["do" => "«", "dc" => "»", "so" => "“", "sc" => "”"],
        "et" => ["do" => "„", "dc" => "”", "so" => "„", "sc" => "”"],
        "eu" => ["do" => "«", "dc" => "»", "so" => "“", "sc" => "”"],
        "fi" => ["do" => "”", "dc" => "”", "so" => "’", "sc" => "’"],
        "fr" => ["do" => "«", "dc" => "»", "so" => "‹", "sc" => "›"],
        "ga" => ["do" => "“", "dc" => "”", "so" => "‘", "sc" => "’"],
        "he" => ["do" => "”", "dc" => "„", "so" => "’", "sc" => "‚"],
        "hr" => ["do" => "„", "dc" => "”", "so" => "", "sc" => ""],
        "hsb" => ["do" => "„", "dc" => "“", "so" => "‚", "sc" => "‘"],
        "hu" => ["do" => "„", "dc" => "”", "so" => "", "sc" => ""],
        "hy" => ["do" => "«", "dc" => "»", "so" => "„", "sc" => "“"],
        "id" => ["do" => "”", "dc" => "”", "so" => "’", "sc" => "’"],
        "is" => ["do" => "„", "dc" => "“", "so" => "‚", "sc" => "‘"],
        "it" => ["do" => "«", "dc" => "»", "so" => "", "sc" => ""],
        "ja" => ["do" => "「", "dc" => "」", "so" => "『", "sc" => "』"],
        "ka" => ["do" => "„", "dc" => "“", "so" => "", "sc" => ""],
        "ko" => ["do" => "“", "dc" => "”", "so" => "‘", "sc" => "’"],
        "lt" => ["do" => "„", "dc" => "“", "so" => "‚", "sc" => "‘"],
        "lv" => ["do" => "„", "dc" => "“", "so" => "‚", "sc" => "‘"],
        "nl" => ["do" => "“", "dc" => "”", "so" => "‘", "sc" => "’"],
        "no" => ["do" => "«", "dc" => "»", "so" => "‘", "sc" => "’"],
        "pl" => ["do" => "„", "dc" => "”", "so" => "", "sc" => ""],
        "pt-BR" => ["do" => "“", "dc" => "”", "so" => "‘", "sc" => "’"],
        "pt" => ["do" => "«", "dc" => "»", "so" => "“", "sc" => "”"], //pt-PT
        "ro" => ["do" => "„", "dc" => "”", "so" => "«", "sc" => "»"],
        "ru" => ["do" => "«", "dc" => "»", "so" => "„", "sc" => "“"],
        "sk" => ["do" => "„", "dc" => "“", "so" => "‚", "sc" => "‘"],
        "sl" => ["do" => "„", "dc" => "“", "so" => "‚", "sc" => "‘"],
        "sq" => ["do" => "«", "dc" => "»", "so" => "‹", "sc" => "›"],
        "sr" => ["do" => "„", "dc" => "”", "so" => "‚", "sc" => "’"],
        "sv" => ["do" => "”", "dc" => "”", "so" => "’", "sc" => "’"],
        "th" => ["do" => "“", "dc" => "”", "so" => "‘", "sc" => "’"],
        "tr" => ["do" => "“", "dc" => "”", "so" => "‘", "sc" => "’"],
        "uk" => ["do" => "«", "dc" => "»", "so" => "„", "sc" => "“"],
        "zh-CN" => ["do" => "“", "dc" => "”", "so" => "‘", "sc" => "’"],
        "zh-TW" => ["do" => "「", "dc" => "」", "so" => "『", "sc" => "』"],
    ];

    public const STD_QUOTE_DEF = [  // en-US
        "do" => "“", "dc" => "”", "so" => "‘", "sc" => "’"
    ];

    public const QUOTE_CHARS = [
        "„",
        "”",
        "“",
        "‚",
        "’",
        "‘",
        "«",
        "»",
        "‹",
        "›",
        "「",
        "」",
        "『",
        "』",
        "《",
        "》",
        "〈",
        "〉",
    ];

    /**
     * get quote definition depending on language code, if not present fallback standard definition will be returned
     * @param string|null $langcode
     * @return array{do:string, dc:string, so:string, sc:string}
     */
    public static function getQuoteDefinition(string|null $langcode) : array {
        if (!$langcode) {
            return self::STD_QUOTE_DEF;
        }

        $langs[] = $langcode;

        $lang_chunks = explode('-', $langcode);
        if (count($lang_chunks) > 1) {
            $langs[] = $lang_chunks[0];
        }

        $langs[] = 'en';

        foreach ($langs as $lang) {
            if (array_key_exists($lang, self::QUOTE_DEFS)) {
                return self::QUOTE_DEFS[$lang];
            }
        }
        return self::STD_QUOTE_DEF; // just in case - en is standard def
    }

    public static function array_value_or_default(array $array, string|int $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $array) && $array[$key] !== '' && $array[$key] !== null
            ? $array[$key]
            : $default;
    }

    /**
     * converts quote definition into configuration array needed for the commonmark environment
     * @param array $quote_def
     * @return array{double_quote_closer: mixed, double_quote_opener: mixed, single_quote_closer: mixed, single_quote_opener: mixed}
     */
    public static function getSmartPunctExtConfig(array $quote_def) :array {
        return [
            'double_quote_opener' => self::array_value_or_default($quote_def, 'do', '"'),
            'double_quote_closer' => self::array_value_or_default($quote_def, 'dc', '"'),
            'single_quote_opener' => self::array_value_or_default($quote_def, 'so', "'"),
            'single_quote_closer' => self::array_value_or_default($quote_def, 'sc', "'"),
        ];        
    }
}