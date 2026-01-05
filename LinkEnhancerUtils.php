<?php

/*
 * webtrees - linkenhancer (custom module)
 *
 * Copyright (C) 2025 Bernd Schwendinger
 *
 * webtrees: online genealogy application
 * Copyright (C) 2025 webtrees development team.
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
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Validator;
use Psr\Http\Message\ServerRequestInterface;

class LinkEnhancerUtils { // misc helper functions
    /**
     * Wrapper for init javascript with try-catch-wrapper
     * 
     * @param string $docReadyJs  execute on document ready
     * @param string $initJs      execute directly
     * @return string
     */
    public static function getJavascriptWrapper(string $docReadyJs, string $initJs): string
    {
        $js = '';
        $js .= $initJs ? "try {{$initJs}} catch(e){console.error('LE-Mod Init Error:', e);}" : '';
        $js .= $docReadyJs ? "document.addEventListener('DOMContentLoaded', function(event) {try {{$docReadyJs}} catch(e){console.error('LE-Mod doc ready Init Error:', e);}});" : '';
        return $js ? "<script>$js</script>" : '';
    }


    /**
     * wrap I18N strings needed by javascript routines in a json object
     *
     * @return string
     */
    public static function getJsonI18N(): string
    {
        return json_encode([
            // MDE
            'bold' => /*I18N: JS MDE */ I18N::translate('bold'),
            'italic' => /*I18N: JS MDE */ I18N::translate('italic'),
            'strikethrough' => /*I18N: JS MDE */ I18N::translate('strikethrough'),
            'format as code' => /*I18N: JS MDE */ I18N::translate('format as code'),
            'Level 1 heading' => /*I18N: JS MDE */ I18N::translate('Level %s heading', '1'),
            'Bulleted list' => /*I18N: JS MDE */ I18N::translate('Bulleted list'),
            'Numbered list' => /*I18N: JS MDE */ I18N::translate('Numbered list'),
            'quote' => /*I18N: JS MDE blockquote */ I18N::translate('quote'),
            'Insert link' => /*I18N: JS MDE */ I18N::translate('Insert link'),
            'Insert image' => /*I18N: JS MDE */ I18N::translate('Insert image'),
            'hr' => /*I18N: JS MDE */ I18N::translate('Horizontal rule'),
            'Undo' => /*I18N: JS MDE */ I18N::translate('Undo'),
            'Redo' => /*I18N: JS MDE */ I18N::translate('Redo'),
            'Help' => /*I18N: webtrees.pot */ I18N::translate('Help'),
            'Link destination' => /*I18N: JS MDE */ I18N::translate('Link destination'),
            'Insert table' => /*I18N: JS MDE */ I18N::translate('Insert table'),
            'queryTableCnR' => /*I18N: JS MDE */ I18N::translate('How many columns and rows should the table have (input: [number] [number])?'),
            // enhanced links 
            'cross reference' => /*I18N: JS enhanced link */ I18N::translate('cross reference'),
            'oofb' => /*I18N: JS enhanced link, %s name of location */ I18N::translate('Online Local heritage book of %s at CompGen', '%s'),
            'gov' => /*I18N: JS enhanced link */ I18N::translate('Historic Geo Information System (GOV)'),
            'gedbas' => /*I18N: JS enhanced link */ I18N::translate('GEDBAS (Genealogical Database - collected personal data)'),
            'www' => /*I18N: JS enhanced link */ I18N::translate('wer-wir-waren.at'),
            'ewp' => /*I18N: JS enhanced link */ I18N::translate('Residents database - Family research in West Prussia'),
            'Interactive tree' => /*I18N: webtrees.pot */ I18N::translate('Interactive tree'),
            'syntax error' => /*I18N: JS enhanced link */ I18N::translate('Syntax error'),
            'param error' => /*I18N: JS enhanced link */ I18N::translate('Unknown parameter keys'),
            'wt-help1' => /*I18N: JS enhanced link wt1 - %s=rectypes*/ I18N::translate('standard link to note (available record types: %s) with XREF in active tree', '%s'),
            'wt-help2' => /*I18N: JS enhanced link wt2 */ I18N::translate('link to record type individual with XREF from "othertree" and also link to'),
            'osm-help1' => /*I18N: JS enhanced link osm1 */ I18N::translate('zoom/lat/lon for locating map'),
            'osm-help2' => /*I18N: JS enhanced link osm2 */ I18N::translate('same as before with additional marker'),
            'osm-help3' => /*I18N: JS enhanced link osm3 */ I18N::translate('show also node/way/relation, see also'),
            'osm-help4' => /*I18N: JS enhanced link osm4 */ I18N::translate('show also node/way/relation - alternative notation'),
            'ofb-help1' => /*I18N: JS enhanced link ofb1 */ I18N::translate('link to Online Local heritage book at CompGen with given uid'),
            'wp-help1' => /*I18N: JS enhanced link wp1 */ I18N::translate('open the article in the german wikipedia'),
            'wp-help2' => /*I18N: JS enhanced link wp2 */ I18N::translate('open the english version of the article'),
            'wp-help3' => /*I18N: JS enhanced link wp3 */ I18N::translate('you can address every subdomain instance of wikipedia.org'),
            'gedbas-help1' => /*I18N: JS enhanced link gedbas1 */ I18N::translate('open person record with given number'),
            'gedbas-help2' => /*I18N: JS enhanced link gedbas2 */ I18N::translate('open person record with UID'),
        ]);
    }

    /**
     * Markdown examples for help screen
     *
     * @param string $base_url
     * @return array
     */
    public static function getMarkdownHelpExamples(string $base_url, bool $mdext_active = false): array
    {
        $public_url = $base_url . '/public/apple-touch-icon.png';

        $tablemarkup = "<table>\n  "
            . "<tr>\n    <th class=\"left\">Title 1</th>\n    <th class=\"center\">Title 2</th>\n    <th class=\"right\">Title 3</th>\n  </tr>\n  <tr>\n    "
            . "<td class=\"left\">Text 1</td>\n    <td class=\"center\">Text 2</td>\n    <td class=\"right\">Text 3</td>\n  </tr>\n</table>";
        $mdsyntax = [
            [
                'md' => '*' . I18N::translate('italic') . '*',
                'html' => '<em>' . I18N::translate('italic') . '</em>'
            ],
            [
                'md' => '**' . I18N::translate('bold') . '**',
                'html' => '<strong>' . I18N::translate('bold') . '</strong>'
            ],
            [
                'md' => '`' . I18N::translate('format as code') . '`',
                'html' => '<code>' . I18N::translate('format as code') . '</code>'
            ]
        ];
        if ($mdext_active) {
            array_push($mdsyntax,
                [
                    'md' => '~~' . I18N::translate('strikethrough') . '~~',
                    'html' => '<del>' . I18N::translate('strikethrough') . '</del>'
                ],
            );
        }
        array_push($mdsyntax,
            [
                'md' => '# ' . I18N::translate('Level %s heading', '1'),
                'html' => '<h1>' . I18N::translate('Level %s heading', '1') . '</h1>'
            ],
            [
                'md' => '## ' . I18N::translate('Level %s heading', '2') . "\n\n"
                    . I18N::translate('and so on until level %s', '6'),
                'html' => '<h2>' . I18N::translate('Level %s heading', '2') . '</h2>'
            ],
            [
                'md' => str_repeat('- ' . I18N::translate('Bulleted list') . "\n", 2),
                'html' => "<ul>\n" . str_repeat('  <li>' . I18N::translate('Bulleted list') . "</li>\n", 2) . '</ul>'
            ],
            [
                'md' => str_repeat('1. ' . I18N::translate('Numbered list') . "\n", 2),
                'html' => "<ol>\n" . str_repeat('  <li>' . I18N::translate('Numbered list') . "</li>\n", 2) . '</ol>'
            ]
        );
        if ($mdext_active) {
            array_push($mdsyntax,
                [
                    'md' => I18N::translate('Term') . "\n: "
                        . /*I18N: webtrees.pot */I18N::translate('Definition'),
                    'html' => "<dl>\n  <dt>" . I18N::translate('Term') . "</dt>\n  <dd>" 
                        . /*I18N: webtrees.pot */I18N::translate('Definition') . "</dd>\n</dl>"
                ],
                [
                    'md' => I18N::translate('Text with a footnote reference') . "[^note1]\n\n[^note1]: " . I18N::translate('Footnote text'),
                    'html' => "<p>" . I18N::translate('Text with a footnote reference') . "<sup id=\"fnref_note1__\"><a class=\"footnote-ref\" href=\"#\">1</a></sup></p>\n\n<div class=\"footnotes\"><hr>\n<ol>\n  <li id=\"fn_note1__\" class=\"footnote\"><p>" . I18N::translate('Footnote text') . " <a class=\"footnote-backref\" href=\"#\">↩</a></p></li>\n</ol></div>"
                ]
            );
        }
        array_push($mdsyntax,
            [
                'md' => '[' . I18N::translate('Insert link') . '](#anchor)',
                'html' => '<a href="#anchor">' . I18N::translate('Insert link') . '</a>'
            ],
            [
                'md' => '![' . I18N::translate('Insert image') . '](webtrees.png)',
                'html' => '<img src="webtrees.png" alt="' . I18N::translate('Insert image') . '" />',
                'out' => '<img src="' . $public_url . '" width="100">'
            ],
            [
                'md' => I18N::translate('Horizontal rule') . "\n\n---",
                'html' => I18N::translate('Horizontal rule') . "\n<hr>"
            ],
            [
                'md' => I18N::translate('Insert table') . "\n\n|Title 1  | Title 2 |  Title 3|\n|:----    |  :---:  |     ---:|\n|Text 1   |  Text2  |    Text3|\n",
                'html' => $tablemarkup,
                'out' => '<div class="md-example">' . $tablemarkup . '</div>'
            ],
            [
                'md' => '> ' . I18N::translate('quote'),
                'html' => '<blockquote>' . I18N::translate('quote') . "</blockquote>"
            ]
        );
        return $mdsyntax;
    }


    /**
     * returns informations for active route of current request; needed for context help
     *
     * @param ServerRequestInterface|null $request
     *
     * @return array
     */
    public static function getActiveRoute(ServerRequestInterface|null $request = null): array
    {
        $request ??= Registry::container()->get(ServerRequestInterface::class);

        $route = $request->getAttribute('route');
        if ($route) {
            $extras = is_array($route->extras) && isset($route->extras['middleware']) ? implode('|', $route->extras['middleware']) : '';
            return [
                'path' => $route->path,
                'handler' => $route->name,
                'method' => implode('|', $route->allows),
                'extras' => $extras,
                'attr' => $route->attributes
            ];

        }
        return [];
    }


    /**
     * is it a request for an edit page
     *
     * @param ServerRequestInterface $request
     * @return bool
     */
    public static function isEditPage(ServerRequestInterface|null $request = null): bool
    {
        if (!$request) {
            $request = Registry::container()->get(ServerRequestInterface::class);
        }
        $route = Validator::attributes($request)->route();
        $routename = basename(strtr($route->name ?? '/', ['\\' => '/']));

        return in_array($routename, [
            'EditFactPage',
            'EditMainFieldsPage',
            'EditNotePage',
            'EditRecordPage',
            'AddChildToFamilyPage',
            'AddChildToIndividualPage',
            'AddNewFact',
            'AddParentToIndividualPage',
            'AddSpouseToFamilyPage',
            'AddSpouseToIndividualPage',
            'AddUnlinkedPage'
        ]);
    } 


    /**
     * is it a request for an admin backend page
     *
     * @param ServerRequestInterface $request
     * @return bool
     */
    public static function isAdminPage(ServerRequestInterface|null $request = null): bool
    {
        if (!$request) {
            $request = Registry::container()->get(ServerRequestInterface::class);
        }

        $activeRouteInfo = self::getActiveRoute($request);

        if (is_array($activeRouteInfo)
            && array_key_exists('path', $activeRouteInfo) 
            && array_key_exists('extras', $activeRouteInfo)
        ) {
            $action = strtolower(($request->getAttribute('action') ?? ''));
            return (str_starts_with($activeRouteInfo['path'], '/admin') 
            || str_contains($activeRouteInfo['extras'], 'AuthAdministrator')
            || (str_contains($activeRouteInfo['extras'], 'AuthManager') && !str_contains($activeRouteInfo['path'], '/tree-page-'))
            || (str_starts_with($activeRouteInfo['path'], '/module') && str_starts_with($action, 'admin')));
        }
        return false;
    }     

}