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
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Validator;
use Fisharebest\Webtrees\Module\AbstractModule;
use Fisharebest\Webtrees\Schema\MigrationInterface;
use Psr\Http\Message\ServerRequestInterface;
use Illuminate\Database\Capsule\Manager as DB;

use PDOException;

enum WebRessource
{
    case CssAndJs;
    case Css;
    case Js;
}

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
     * translate title items of the json string
     *
     * @return string
     */
    public static function getWthbLinksJsonStringTranslated(string $jsonString): string
    {
        $json = json_decode($jsonString, true);
        if (!$json) return '';
        
        $translation = self::getWthbLinksTranslations();

        $fnmap = function($item) use ($translation) {
            if ($item['title'] ?? false) {
                $item['title'] = $translation[$item['title']] ?? false ? $translation[$item['title']] : $item['title'];
            }
            return $item;
        };

        $result = json_encode(array_map($fnmap, $json));
        return ($result ? $result : $jsonString);
    }


    /**
     * array of I18N strings needed for by javascript routines
     *
     * @return array
     */    
    public static function getWthbLinksTranslations(): array 
    {
        // see also: LinkEnhancerModule::STD_WTHB_LINKS_JSON
        return [
            "webtrees FAQ"                       => I18N::translate('webtrees FAQ'),
            "webtrees Forum"                     => I18N::translate('webtrees Forum'),
            "webtrees Forum - ask a question (account necessary)" => I18N::translate('webtrees Forum') . ' - ' . I18N::translate('ask a question (account necessary)'),
            "CompGen Discourse"                  => I18N::translate('CompGen Discourse'),
            "CompGen Discourse - ask a question (account necessary)" => I18N::translate('CompGen Discourse') . ' - ' . I18N::translate('ask a question (account necessary)'),
            "GitHub - webtrees issues"           => I18N::translate('GitHub - webtrees issues'),
            "GitHub - webtrees related projects" => I18N::translate('GitHub - webtrees related projects'),
        ];
    }

    /**
     * array of I18N strings needed by javascript routines
     *
     * @return array
     */
    public static function getJsI18N(string $component, AbstractModule $module): array
    {
        return match(strtolower($component)) {
            'mde' => [
                    // MDE
                    'bold'             => /*I18N: JS MDE */ I18N::translate('bold'),
                    'italic'           => /*I18N: JS MDE */ I18N::translate('italic'),
                    'strikethrough'    => /*I18N: JS MDE */ I18N::translate('strikethrough'),
                    'format as code'   => /*I18N: JS MDE */ I18N::translate('format as code'),
                    'highlight'        => /*I18N: JS MDE */ I18N::translate('highlight'),
                    'Level 1 heading'  => /*I18N: JS MDE */ I18N::translate('Level %s heading', '1'),
                    'Bulleted list'    => /*I18N: JS MDE */ I18N::translate('Bulleted list'),
                    'Numbered list'    => /*I18N: JS MDE */ I18N::translate('Numbered list'),
                    'quote'            => /*I18N: JS MDE blockquote */ I18N::translate('quote'),
                    'Insert link'      => /*I18N: JS MDE */ I18N::translate('Insert link'),
                    'Insert image'     => /*I18N: JS MDE */ I18N::translate('Insert image'),
                    'hr'               => /*I18N: JS MDE */ I18N::translate('Horizontal rule'),
                    'Undo'             => /*I18N: JS MDE */ I18N::translate('Undo'),
                    'Redo'             => /*I18N: JS MDE */ I18N::translate('Redo'),
                    'Help'             => /*I18N: webtrees.pot */ I18N::translate('Help'),
                    'Link destination' => /*I18N: JS MDE */ I18N::translate('Link destination'),
                    'Insert table'     => /*I18N: JS MDE */ I18N::translate('Insert table'),
                    'queryTableCnR'    => /*I18N: JS MDE */ I18N::translate('How many columns and rows should the table have (input: [number] [number])?'),
                ],

            'le' => [
                    // enhanced links 
                    'cross reference'  => /*I18N: JS enhanced link */ I18N::translate('cross reference'),
                    'oofb'             => /*I18N: JS enhanced link, %s name of location */ I18N::translate('Online Local heritage book of %s at CompGen', '%s'),
                    'gov'              => /*I18N: JS enhanced link */ I18N::translate('Historic Geo Information System (GOV)'),
                    'gedbas'           => /*I18N: JS enhanced link */ I18N::translate('GEDBAS (Genealogical Database - collected personal data)'),
                    'www'              => /*I18N: JS enhanced link */ I18N::translate('wer-wir-waren.at'),
                    'ewp'              => /*I18N: JS enhanced link */ I18N::translate('Residents database - Family research in West Prussia'),
                    'Interactive tree' => /*I18N: webtrees.pot */ I18N::translate('Interactive tree'),
                    'syntax error'     => /*I18N: JS enhanced link */ I18N::translate('Syntax error'),
                    'param error'      => /*I18N: JS enhanced link */ I18N::translate('Unknown parameter keys'),
                    'wt-help1'         => /*I18N: JS enhanced link wt1 - %s=rectypes*/ I18N::translate('standard link to note (available record types: %s) with XREF in active tree', '%s'),
                    'wt-help2'         => /*I18N: JS enhanced link wt2 */ I18N::translate('link to record type individual with XREF from "othertree" and also link to'),
                    'wt-help3'         => /*I18N: JS enhanced link wt3 */ I18N::translate('link to record without given record type - access via redirect url'),
                    'osm-help1'        => /*I18N: JS enhanced link osm1 */ I18N::translate('zoom/lat/lon for locating map'),
                    'osm-help2'        => /*I18N: JS enhanced link osm2 */ I18N::translate('same as before with additional marker'),
                    'osm-help3'        => /*I18N: JS enhanced link osm3 */ I18N::translate('show also node/way/relation, see also'),
                    'osm-help4'        => /*I18N: JS enhanced link osm4 */ I18N::translate('show also node/way/relation - alternative notation'),
                    'ofb-help1'        => /*I18N: JS enhanced link ofb1 */ I18N::translate('link to Online Local heritage book at CompGen with given uid'),
                    'wp-help1'         => /*I18N: JS enhanced link wp1 */ I18N::translate('open the article in the german wikipedia'),
                    'wp-help2'         => /*I18N: JS enhanced link wp2 */ I18N::translate('open the english version of the article'),
                    'wp-help3'         => /*I18N: JS enhanced link wp3 */ I18N::translate('you can address every subdomain instance of wikipedia.org'),
                    'gedbas-help1'     => /*I18N: JS enhanced link gedbas1 */ I18N::translate('open person record with given number'),
                    'gedbas-help2'     => /*I18N: JS enhanced link gedbas2 */ I18N::translate('open person record with UID'),
                    'gedbas-help3'     => /*I18N: JS enhanced link gedbas3 */ I18N::translate('open person record with UID') . ' (' . I18N::translate('without database number') . ')',
                    'des-vl'           => I18N::translate('Data Entry System (DES)') . ' - ' . I18N::translate('Casualty lists'),
                ],
            
            'wthb' => [
                    'help_title_wthb'   => I18N::translate('Webtrees manual'),
                    'help_title_ext'    => /*I18N: webtrees.pot */ I18N::translate('Help'),
                    'cfg_title'         => /*I18N: wthb link user setting title */ I18N::translate('Webtrees manual link - user setting'),
                    'tocnsearch'        => I18N::translate("Full-text search") . ' / ' . I18N::translate('Table of contents'),
                    'wtcorehelp'        => I18N::translate("webtrees help topics (included)"),
                    'startpage'         => I18N::translate("start page"),
                    'admin_title'       => $module->title() . ' - ' . I18N::translate('Settings'),
                ],
            
            'img' => [
                    'limitheight'   => I18N::translate('Limit cell height'),
                ],
            
            default => []
        };
    }

    /**
     * Markdown examples for help screen
     *
     * @param string $base_url
     * @return array
     */
    public static function getMarkdownHelpExamples(
        string $base_url, 
        bool $mdext_active = false,
        bool $mdext_highlight_active = false,
        bool $mdext_strike_active = false,
        bool $mdext_dl_active = false,
        bool $mdext_fn_active = false
    ): array
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
        if ($mdext_active && $mdext_strike_active) {
            array_push($mdsyntax,
                [
                    'md' => '~~' . I18N::translate('strikethrough') . '~~',
                    'html' => '<del>' . I18N::translate('strikethrough') . '</del>'
                ],
            );
        }
        if ($mdext_active && $mdext_highlight_active) {
            array_push(
                $mdsyntax,
                [
                    'md' => '==' . I18N::translate('highlight') . '==',
                    'html' => '<mark>' . I18N::translate('highlight') . '</mark>'
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
        if ($mdext_active && $mdext_dl_active) {
            array_push($mdsyntax,
                [
                    'md' => I18N::translate('Term') . "\n: "
                        . /*I18N: webtrees.pot */I18N::translate('Definition'),
                    'html' => "<dl>\n  <dt>" . I18N::translate('Term') . "</dt>\n  <dd>" 
                        . /*I18N: webtrees.pot */I18N::translate('Definition') . "</dd>\n</dl>"
                ]
            );
        }
        if ($mdext_active && $mdext_fn_active) {
            array_push($mdsyntax,
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


    /**
     * applies Migrate# class files (zero based) to the database until target_version -1
     * same as Database::getSchema, but use module settings instead of site settings (Issue #3 in personal_facts_with_hooks)
     * taken from modules_v4/vesta_common/VestaModuleTrait.php
     * @param AbstractModule $module
     * @param string $namespace      namespace of Migration class
     * @param string $schema_name    setting name of current schema version
     * @param int $target_version
     * @return bool                  true if updates upplied
     */
    public static function updateSchema(AbstractModule $module, string $namespace, string $schema_name, int $target_version): bool
    {
        try {
            $current_version = intval($module->getPreference($schema_name));
        } catch (PDOException $ex) {
            // During initial installation, the site_preference table won’t exist.
            $current_version = 0;
        }

        $updates_applied = false;

        // Update the schema, one version at a time.
        while ($current_version < $target_version) {

            $class = $namespace . '\\Migration' . $current_version;
            /** @var MigrationInterface $migration */
            $migration = new $class();
            $migration->upgrade();
            $current_version++;

            //when a module is first installed, we may not be able to setPreference at this point
            ////(if this is called e.g. from SetName())
            //because of foreign key constraints:
            //the module may not have been inserted in the 'module' table at this point!
            //cf. ModuleService.all()
            //
            //not that critical, we can just set the preference next time
            //
            //let's just check this directly (using ModuleService at this point may lead to looping, if we're indirectly called from there)
            if (DB::table('module')->where('module_name', '=', $module->name())->exists()) {
                $module->setPreference($schema_name, (string) $current_version);
            }
            $updates_applied = true;
        }

        return $updates_applied;
    }


    /**
     * include bundle-{infix}.min.[css|js] from ressource folder depending on given bundle shortcuts
     * css files smaler than 500 Bytes are included directly, otherwise per link- and script-tag (js)
     * @param AbstractModule $module   methods assetUrl (ModuleCustomTrait) and resourcesFolder (AbstractModule) are used
     * @param array $bundleShortcuts
     * @return string
     */
    public static function getIncludeWebressourceString(AbstractModule $module, array $bundleShortcuts, WebRessource $resType): string
    {
        if (!$bundleShortcuts) {
            return '';
        }
        $includeRes = '';
        asort($bundleShortcuts);
        $bundleShortcutsCss = match ($resType) { //array_filter($bundleShortcuts, function ($var) { return $var !== 'wthb'; }); // wthb support - only js
                WebRessource::Js => null,
                default => $bundleShortcuts
        };
        $bundleShortcutsJs = match ($resType) { //array_filter($bundleShortcuts, fn($var) => $var !== 'img'); // markdown image support - only css
                WebRessource::Css => null,
                default => $bundleShortcuts
        };

        // CSS
        if ($bundleShortcutsCss) {
            $infix = implode("-", $bundleShortcutsCss);
            $assetFile = $module->resourcesFolder() . "css/bundle-{$infix}.min.css";
            if (file_exists($assetFile)) {
                if (filesize($assetFile) > 500) {
                    $includeRes .= '<link rel="stylesheet" type="text/css" href="' . $module->assetUrl("css/bundle-{$infix}.min.css") . '">';
                } else {
                    $includeRes .= '<style>' . file_get_contents($assetFile) . '</style>';
                }
            }
        }

        // Javascript
        if ($bundleShortcutsJs) {
            $infix = implode("-", $bundleShortcutsJs);
            $includeRes .= '<script src="' . $module->assetUrl("js/bundle-{$infix}.min.js") . '"></script>';
        }
        return $includeRes;
    }


    public static function getSearchEngines(): array
    {
        // key   = Displayname without whitespaces, in lower case prepended with 'icon-' it's the css class name for background icon to be displayed
        // value = search engine url, append search terms uriencoded
        return [
            'GenWiki' => 'https://wiki.genealogy.net/index.php?title=Spezial%3ASuche&profile=advanced&fulltext=1&ns0=1&ns6=1&search=%22Webtrees+Handbuch%22+',
            'Startpage' => 'https://www.startpage.com/do/search?query=site:wiki.genealogy.net+"Webtrees%20Handbuch"+AND+',
            'Ecosia' => 'https://www.ecosia.org/search?q=site%3Agenealogy.net%20%22webtrees%20handbuch%22%20AND%20',
            'mojeek' => 'https://www.mojeek.com/search?q=inurl%3Agenealogy.net+%22Webtrees+Handbuch%22+',
            'Qwant' => 'https://www.qwant.com/?t=web&q=site%3Awiki.genealogy.net+%22Webtrees+Handbuch%22+AND+',
            'Perplexity' => 'https://www.perplexity.ai/search/?q=site:wiki.genealogy.net%20inurl:%22Webtrees%20Handbuch%22+',
            'DuckDuckGo' => 'https://duckduckgo.com/?q=site:wiki.genealogy.net+inurl:"Webtrees%20Handbuch"+',
            'Google' => 'https://www.google.com/search?q=site:wiki.genealogy.net+"webtrees+Handbuch"+AND+',
        ];
    }    
}