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

use Schwendinger\Webtrees\Module\LinkEnhancer\Factories\CustomMarkdownFactory;
use Schwendinger\Webtrees\Module\LinkEnhancer\LinkEnhancerUtils as Utils;
use Schwendinger\Webtrees\Module\LinkEnhancer\Services\WthbService;
use Schwendinger\Webtrees\Module\LinkEnhancer\Http\RequestHandlers\GotoXrefAction;;
use Fisharebest\Localization\Translation;
use Fisharebest\Webtrees\FlashMessages;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Session;
use Fisharebest\Webtrees\Validator;
use Fisharebest\Webtrees\View;
use Fisharebest\Webtrees\Webtrees;
use Fisharebest\Webtrees\Http\RequestHandlers\HomePage;
use Fisharebest\Webtrees\Http\RequestHandlers\TreePage;
use Fisharebest\Webtrees\Module\AbstractModule;
use Fisharebest\Webtrees\Module\ModuleConfigInterface;
use Fisharebest\Webtrees\Module\ModuleConfigTrait;
use Fisharebest\Webtrees\Module\ModuleCustomInterface;
use Fisharebest\Webtrees\Module\ModuleCustomTrait;
use Fisharebest\Webtrees\Module\ModuleGlobalInterface;
use Fisharebest\Webtrees\Module\ModuleGlobalTrait;
use Fisharebest\Webtrees\Schema\MigrationInterface;
use Fisharebest\Webtrees\Services\TreeService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Illuminate\Database\Capsule\Manager as DB;
use Aura\Router\Map;

use Exception;
use PDOException;

class LinkEnhancerModule extends AbstractModule implements ModuleCustomInterface, ModuleGlobalInterface, ModuleConfigInterface {


    // For every module interface that is implemented, the corresponding trait *should* also use be used.
    use ModuleCustomTrait;
    use ModuleGlobalTrait;
    use ModuleConfigTrait;

    /**
     * list of const for module administration
     */
    public const CUSTOM_MODULE = 'linkenhancer';
    public const CUSTOM_AUTHOR = 'Bernd Schwendinger';
    public const GITHUB_USER = 'bschwede';
    public const CUSTOM_WEBSITE = 'https://github.com/' . self::GITHUB_USER . '/' . self::CUSTOM_MODULE . '/';
    public const CUSTOM_VERSION = '1.2.6';
    public const CUSTOM_LAST = 'https://raw.githubusercontent.com/' . self::GITHUB_USER . '/' .
        self::CUSTOM_MODULE . '/main/latest-version.txt';


    public const PREF_HOME_LINK_TYPE = 'HOME_LINK_TYPE'; // home link type: 0=off, 1=tree, 2=my-page
    public const PREF_HOME_LINK_JSON = 'HOME_LINK_JS'; // string; javascript object { '*': stylerules-string, 'theme': stylerules-string}
    public const EXAMPLE_HOME_LINK_JSON = '{ "*": ".homelink { color: #039; }",  "colors_nocturnal": ".homelink { color: antiquewhite; }" }';
    public const PREF_WTHB_ACTIVE = 'WTHB_LINK_ACTIVE'; // link to GenWiki "Webtrees Handbuch"
    public const PREF_WTHB_SUBCONTEXT = 'WTHB_SUBCONTEXT'; // support subcontext topics
    public const PREF_WTHB_TOCNSEARCH = 'WTHB_TOCNSEARCH'; // support webtrees manual full-text search and toc
    public const PREF_WTHB_FAICON = 'WTHB_FAICON'; // prepend fa icon to help link
    public const PREF_WTHB_UPDATE = 'WTHB_UPDATE'; // auto refresh table on module update
    public const PREF_WTHB_LASTHASH = 'WTHB_LASTHASH'; // last csv hash used for import
    public const PREF_WTHB_STD_LINK = 'WTHB_STD_LINK'; // standard link to GenWiki "Webtrees Handbuch"
    public const PREF_WTHB_TRANSLATE = 'WTHB_TRANSLATE'; // use translation service for webtrees manual pages
    public const PREF_WTHB_ADMINVIEWPATCH = 'WTHB_ADMINVIEWPATCH'; // register admin layout view
    public const PREF_JS_DEBUG_CONSOLE = 'JS_DEBUG_CONSOLE'; // console.debug with active route info; 0=off, 1=on
    public const PREF_GENWIKI_LINK = 'GENWIKI_LINK'; // base link to GenWiki
    
    public const PREF_LINKSPP_ACTIVE = 'LINKSPP_ACTIVE'; // enable links++
    public const PREF_LINKSPP_JS = 'LINKSPP_JS'; // Javascript
    public const PREF_LINKSPP_OPEN_IN_NEW_TAB = 'LINKSPP_OPEN_IN_NEW_TAB'; // enable open link in new browser tab

    public const PREF_MD_ACTIVE = 'MD_ACTIVE'; // enable markdown enhancements
    public const PREF_MD_IMG_ACTIVE = 'MD_IMG_ACTIVE'; // enable enhanced markdown img syntax
    public const PREF_MD_IMG_STDCLASS = 'MD_IMG_STDCLASS'; // standard classname(s) for div wrapping img- and link-tag    
    public const PREF_MD_IMG_TITLE_STDCLASS = 'MD_IMG_TITLE_STDCLASS'; // standard classname(s) for picture subtitle
    public const PREF_MDE_ACTIVE = 'MDE_ACTIVE'; // enable markdown editor for note textareas

    public const PREF_MD_TD_H_CTRL_TYPE = 'MD_TD_H_CTRL_ACTIVE'; // enable table cell height control
    public const PREF_MD_TD_H_CB_VISIBLE = 'MD_TD_H_CB_VISIBLE'; // set checkbox visiblity: always or on th:hover

    public const PREF_MD_EXT_ACTIVE = 'MD_EXT_ACTIVE'; // enable markdown extensions
    public const PREF_MD_EXT_STRIKE_ACTIVE = 'MD_EXT_STRIKE_ACTIVE'; // enable markdown extension - strikethrough
    public const PREF_MD_EXT_DL_ACTIVE = 'MD_EXT_DL_ACTIVE'; // enable markdown extension - definition list
    public const PREF_MD_EXT_MARK_ACTIVE = 'MD_EXT_MARK_ACTIVE'; // enable markdown extension - highlight
    
    public const PREF_MD_EXT_FN_ACTIVE = 'MD_EXT_FN_ACTIVE'; // enable markdown extension - footnotes
    public const PREF_MD_EXT_FN_BACKREF_CHAR = 'MD_EXT_FN_BACKREF_CHAR'; // markdown extension - footnotes back reference symbol
    public const PREF_MD_EXT_FN_ADD_HR = 'MD_EXT_FN_ADD_HR'; // markdown extension - footnotes add container hr
    
    public const PREF_MD_EXT_TOC_ACTIVE = 'MD_EXT_TOC_ACTIVE'; // enable markdown extension - table of contents
    public const PREF_MD_EXT_TOC_PERMALINK_CHAR = 'MD_EXT_TOC_PERMALINK_CHAR'; // markdown extension - table of contents permalink symbol
    public const PREF_MD_EXT_TOC_STYLE = 'MD_EXT_TOC_STYLE'; // markdown extension - table of contents style of list (bullet / ordered)
    public const PREF_MD_EXT_TOC_NORMALIZE = 'MD_EXT_TOC_NORMALIZE'; // markdown extension - table of contentsnormalize method (flat, relative, as-is)
    public const PREF_MD_EXT_TOC_POS = 'MD_EXT_TOC_POS'; // markdown extension - table of contents position
    public const PREF_MD_EXT_TOC_PLACEHOLDER = 'MD_EXT_TOC_PLACEHOLDER'; // markdown extension - table of contents placeholder
    public const PREF_MD_EXT_TOC_CSSCLASS = 'MD_EXT_TOC_CSSCLASS'; // markdown extension - table of contents additional css class name
    

    public const STDCLASS_HOME_LINK = 'homelink';
    public const STDCLASS_MD_CONTENT = 'md-content'; // section with rendered content; also used in index-img.js
    public const STDCLASS_MD_IMG = 'md-img';
    public const STDCLASS_MD_IMG_TITLE = 'md-img-title';
    public const STDCLASS_MD_STICKY_WRAPPER = 'md-sticky-wrapper'; // for Bootstrap dropdown ()=> md-toc-dropdown.phtml) or height-control checkbox
    public const STDCLASS_MD_TOC = 'md-toc';
    public const STDCLASS_MD_TOC_WO_MARKER = 'md-toc-wo-marker';
    public const STDCLASS_MD_TOC_INLINE = 'md-toc-inline';
    public const STDCLASS_MD_TOC_DROPDOWN = 'md-toc-dropdown';
    public const STDLINK_GENWIKI = 'https://wiki.genealogy.net/';
    public const STDLINK_WTHB = 'https://wiki.genealogy.net/Webtrees_Handbuch';
    public const STDLINK_WTHB_TOC = 'https://wiki.genealogy.net/Webtrees_Handbuch/Verzeichnisse/Inhaltsverzeichnis';
    
    public const HELP_TABLE = 'route_help_map';

    public const HELP_CSV = __DIR__ . DIRECTORY_SEPARATOR . 'Schema' . DIRECTORY_SEPARATOR . 'SeedHelpTable.csv';

    public const HELP_SCHEMA_TARGET_VERSION = 2;

    protected const PREFERENCES_SCHEMA = [ //type=int|string|bool
        self::PREF_HOME_LINK_TYPE            => [ 'type' => 'int',    'default' => '1' ], // triple-state, 0=off, 1=tree, 2=my-page
        self::PREF_HOME_LINK_JSON            => [ 'type' => 'string', 'default' => '' ], // json object { '*': stylerules-string, 'theme': stylerules-string}
        self::PREF_WTHB_ACTIVE               => [ 'type' => 'bool',   'default' => '1' ],
        self::PREF_WTHB_SUBCONTEXT           => [ 'type' => 'bool',   'default' => '1' ],
        self::PREF_WTHB_TOCNSEARCH           => [ 'type' => 'bool',   'default' => '1' ],
        self::PREF_WTHB_FAICON               => [ 'type' => 'bool',   'default' => '1' ],
        self::PREF_WTHB_UPDATE               => [ 'type' => 'bool',   'default' => '1' ],
        self::PREF_WTHB_ADMINVIEWPATCH       => [ 'type' => 'bool',   'default' => '1' ],
        self::PREF_WTHB_LASTHASH             => [ 'type' => 'string', 'default' => '' ],
        self::PREF_JS_DEBUG_CONSOLE          => [ 'type' => 'bool',   'default' => '0' ],
        self::PREF_WTHB_STD_LINK             => [ 'type' => 'string', 'default' => self::STDLINK_WTHB ], // url
        self::PREF_GENWIKI_LINK              => [ 'type' => 'string', 'default' => self::STDLINK_GENWIKI ], // url
        self::PREF_WTHB_TRANSLATE            => [ 'type' => 'int',    'default' => '1' ], // triple-state. 0=off, 1=user defined, 2=on
        self::PREF_LINKSPP_ACTIVE            => [ 'type' => 'bool',   'default' => '1' ],
        self::PREF_LINKSPP_JS                => [ 'type' => 'string', 'default' => '' ],
        self::PREF_LINKSPP_OPEN_IN_NEW_TAB   => [ 'type' => 'bool',   'default' => '1' ],
        // markdown
        self::PREF_MD_ACTIVE                 => [ 'type' => 'bool',   'default' => '1' ],
        self::PREF_MD_IMG_ACTIVE             => [ 'type' => 'bool',   'default' => '1' ],
        self::PREF_MD_IMG_STDCLASS           => [ 'type' => 'string', 'default' => self::STDCLASS_MD_IMG ], // css class name
        self::PREF_MD_IMG_TITLE_STDCLASS     => [ 'type' => 'string', 'default' => self::STDCLASS_MD_IMG_TITLE ], // css class name
        self::PREF_MDE_ACTIVE                => [ 'type' => 'bool',   'default' => '1' ],
        self::PREF_MD_TD_H_CTRL_TYPE         => [ 'type' => 'int',    'default' => '1' ], // triple-state. 0=off, 1=available (default=off), 2=available (default=ON)
        self::PREF_MD_TD_H_CB_VISIBLE        => [ 'type' => 'bool',   'default' => '1' ],
        // markdown extensions
        self::PREF_MD_EXT_ACTIVE             => [ 'type' => 'bool',   'default' => '1' ],
        self::PREF_MD_EXT_STRIKE_ACTIVE      => [ 'type' => 'bool',   'default' => '1' ],
        self::PREF_MD_EXT_DL_ACTIVE          => [ 'type' => 'bool',   'default' => '1' ],
        self::PREF_MD_EXT_MARK_ACTIVE        => [ 'type' => 'bool',   'default' => '1' ],
        self::PREF_MD_EXT_FN_ACTIVE          => [ 'type' => 'bool',   'default' => '1' ],
        self::PREF_MD_EXT_FN_BACKREF_CHAR    => [ 'type' => 'string', 'default' => '↩' ],
        self::PREF_MD_EXT_FN_ADD_HR          => [ 'type' => 'bool',   'default' => '1' ],
        self::PREF_MD_EXT_TOC_ACTIVE         => [ 'type' => 'bool',   'default' => '1' ],
        self::PREF_MD_EXT_TOC_PERMALINK_CHAR => [ 'type' => 'string', 'default' => '#' ],
        self::PREF_MD_EXT_TOC_STYLE          => [ 'type' => 'string', 'default' => 'none' ],
        self::PREF_MD_EXT_TOC_NORMALIZE      => [ 'type' => 'string', 'default' => 'relative' ],
        self::PREF_MD_EXT_TOC_POS            => [ 'type' => 'string', 'default' => 'dropdown' ],
        self::PREF_MD_EXT_TOC_PLACEHOLDER    => [ 'type' => 'string', 'default' => '[TOC]'],
        self::PREF_MD_EXT_TOC_CSSCLASS       => [ 'type' => 'string', 'default' => '' ],
    ];

    private array|null $prefs_cache = null;

    protected WthbService $wthb;

    public function __construct(public readonly bool $vesta_common_enabled = false)
    {
        $this->setName('_' . self::CUSTOM_MODULE . '_'); // need to be initialized before getPref is called; normally set in app/Services/ModuleService.php: $module->setName('_' . basename(dirname($filename)) . '_'); but in this case this is too late

        $std_url = $this->getPref(self::PREF_WTHB_STD_LINK);
        $wiki_url = rtrim($this->getPref(self::PREF_GENWIKI_LINK), '/') . '/';

        $this->wthb = new WthbService(
            self::HELP_TABLE, 
            $std_url,
            $wiki_url
        );
    }    
  
    /**
     * How should this module be identified in the control panel, etc.?
     *
     * @return string
     */
    public function title(): string
    {
        return /*I18N: Module title */I18N::translate("Link-Enhancer");
    }

    /**
     * A sentence describing what this module does.
     *
     * @return string
     */
    public function description(): string
    {
        return /*I18N: Module description */I18N::translate('Cross-references to Gedcom datasets, Markdown editor, context-sensitive link to the GenWiki webtrees manual');
    }

    /**
     * The person or organisation who created this module.
     *
     * @return string
     */
    public function customModuleAuthorName(): string
    {
        return self::CUSTOM_AUTHOR;
    }

    /**
     * The version of this module.
     *
     * @return string
     */
    public function customModuleVersion(): string
    {
        return self::CUSTOM_VERSION; 
    }

    /**
     * A URL that will provide the latest version of this module.
     *
     * @return string
     */
    public function customModuleLatestVersionUrl(): string
    {
        return self::CUSTOM_LAST;
    }

    /**
     * Where to get support for this module.  Perhaps a github repository?
     *
     * @return string
     */
    public function customModuleSupportUrl(): string
    {
        return self::CUSTOM_WEBSITE;
    }

    /**
     * Where does this module store its resources?
     *
     * @return string
     */
    public function resourcesFolder(): string
    {
        return __DIR__ . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR;
    }

    /**
     * Additional/updated translations.
     *
     * @param string $language
     *
     * @return array<string>
     */
    public function customTranslations(string $language): array
    {
        $file = $this->resourcesFolder() . 'lang' . DIRECTORY_SEPARATOR . $language . '.mo';
        return file_exists($file) ? (new Translation($file))->asArray() : [];
    }


    /**
     * Called for all *enabled* modules.
     */
    public function boot(): void
    {
        $this->updateSchema('\Schwendinger\Webtrees\Module\LinkEnhancer\Schema', 'SCHEMA_VERSION', self::HELP_SCHEMA_TARGET_VERSION);

        // check for csv updates once a day and if schema was updated
        Registry::cache()->file()->remember(
            $this->name() . '-check-wthb-csvupdate-' . self::CUSTOM_VERSION . '_' . self::HELP_SCHEMA_TARGET_VERSION,
            function () {
                $importOnUpdate = false;
                $this_hash = null;
                $cfg_wthb_update = $this->getPref(self::PREF_WTHB_UPDATE, true);
                if ($cfg_wthb_update) {
                    $csvfile = self::HELP_CSV;
                    if (file_exists($csvfile)) {
                        $this_hash = hash_file('sha256', $csvfile);
                        $cfg_wthb_lasthash = $this->getPref(self::PREF_WTHB_LASTHASH);
                        $importOnUpdate = $this_hash != $cfg_wthb_lasthash;
                    }
                }

                if ((int) ($this->wthb->getHelpTableCount()['total'] ?? 0) === 0 || $importOnUpdate) {
                    $this->importDeliveredCsv();
                    if ($this_hash) {
                        $this->setPref(self::PREF_WTHB_LASTHASH, $this_hash);
                    }
                }
            },
            86400
        );

        // Register a namespace for our views.
        View::registerNamespace($this->name(), $this->resourcesFolder() . 'views/');

        if ($this->getPref(self::PREF_WTHB_ACTIVE, true) 
            && $this->getPref(self::PREF_WTHB_ADMINVIEWPATCH, true)
            && !$this->vesta_common_enabled) {
            // register patched administration layout if vesta common is not available
            View::registerCustomView('::layouts/administration', $this->name() . '::patched/layouts/administration');
        }

        if ($this->getPref(self::PREF_MD_ACTIVE, true)) {
            Registry::markdownFactory(new CustomMarkdownFactory($this));
        }

        $router = Registry::routeFactory()->routeMap();
        if ($this->getPref(self::PREF_LINKSPP_ACTIVE, true)) {
            $router->attach('', '/tree/{tree}', static function (Map $router) {
                $router->get(GotoXrefAction::class, '/goto-xref/{xref}');
            });
        }
    }
 

    /**
     * Raw content, to be added at the end of the <head> element.
     * Typically, this will be <link> and <meta> elements.
     *
     * @return string
     */
    public function headContent(): string
    {
        $cfg_home_type   = $this->getPref(self::PREF_HOME_LINK_TYPE, true); // 0=off, 1=Home, 2=My-Page
        $cfg_home_active = boolval($cfg_home_type);
        $cfg_wthb_active = $this->getPref(self::PREF_WTHB_ACTIVE, true);
        $cfg_link_active = $this->getPref(self::PREF_LINKSPP_ACTIVE, true);
        $cfg_md_active   = $this->getPref(self::PREF_MD_ACTIVE, true);

        if (!$cfg_home_active && !$cfg_wthb_active && ! $cfg_md_active && !$cfg_link_active) {
            return '';
        }

        $cfg_md_editor_active = $cfg_md_active ? $this->getPref(self::PREF_MDE_ACTIVE, true) : false;
        $cfg_md_img_active    = $cfg_md_active ? $this->getPref(self::PREF_MD_IMG_ACTIVE, true) : false;
        $cfg_md_ext_active    = $cfg_md_active ? $this->getPref(self::PREF_MD_EXT_ACTIVE, true) : false;
        $cfg_js_debug_console = $this->getPref(self::PREF_JS_DEBUG_CONSOLE, true);


        $request = Registry::container()->get(ServerRequestInterface::class);
        //ressources to include
        $bundleShortcuts = [];
        $includeRes = '';
        $docReadyJs = ''; // init on document ready
        $initJs = '';

        $theme = Session::get('theme');
        $palette = Session::get('palette', '');
        
        $activeRouteInfo = Utils::getActiveRoute($request);
        if ($cfg_js_debug_console) {
            $docReadyJs .= "console.debug('LE-Mod theme:', '$theme'" . ($palette ? ", 'palette=$palette'" : '') . ");";
            $docReadyJs .= "console.debug('LE-Mod active route:', " . json_encode($activeRouteInfo) .");";
        }

        // --- Webtrees Handbuch Link
        if ($cfg_wthb_active) {
            $bundleShortcuts[] = 'wthb';

            $withSubcontext = $this->getPref(self::PREF_WTHB_SUBCONTEXT, true);
            $help = $this->wthb->getContextHelp($activeRouteInfo, $withSubcontext, $cfg_js_debug_console);
            if ($cfg_js_debug_console) {
                $docReadyJs .= "console.debug('LE-Mod help rows:', " . json_encode($help['result']) . ");";
                $docReadyJs .= "console.debug('LE-Mod help sql:', " . json_encode($help['sql']) . ");";
                if ($withSubcontext) $docReadyJs .= "console.debug('LE-Mod help subcontext:', " . json_encode($help['subcontext']) . ");";
            }

            $help_url = $help['help_url']; //gettype(value: $help) == 'string' ? $help : $help->first()->url;
            
            // link to Webtrees Manual in GenWiki or external link?
            $wiki_url = $this->getPref(self::PREF_GENWIKI_LINK);
            
            $options = [
                'I18N' => [
                    'help_title_wthb'   => I18N::translate('Webtrees manual'),
                    'help_title_ext'    => /*I18N: webtrees.pot */ I18N::translate('Help'),
                    'cfg_title'         => /*I18N: wthb link user setting title */ I18N::translate('Webtrees manual link - user setting'),
                    'searchntoc'        => I18N::translate("Full-text search") . ' / ' . I18N::translate('Table of contents'),
                ],
                'help_url'     => $help_url,
                'faicon'       => $this->getPref(self::PREF_WTHB_FAICON, true),
                'wiki_url'     => $wiki_url,
                'dotranslate'  => $this->getPref(self::PREF_WTHB_TRANSLATE, true), // 0=off, 1=user defined, 2=on
                'subcontext'   => $withSubcontext ? $help['subcontext'] : [],
                'modal_url'    => route('module', ['module' => $this->name(), 'action' => 'helpwthb']),
                'tocnsearch'   => $this->getPref(self::PREF_WTHB_TOCNSEARCH, true),
            ];

            $initJs .= "LinkEnhMod.initWthb(" . json_encode($options) . ");";
        }        

        // === admin backend - only if patch P002 for administration.phtml was applied; default: headContent of custom modules is not called on the admin backend
        // TODO - is it possible to determine the underlying page layout or should the info for backend pages be stored in DB?!
        if (Utils::isAdminPage($request))
        {
                if ($cfg_wthb_active) {
                    $includeRes .= $this->getIncludeWebressourceString(['wthb']);
                    return $includeRes . Utils::getJavascriptWrapper($docReadyJs, $initJs);
                }
                return ''; # other stuff is of no use in admin backend
        }


        $tree = Validator::attributes($request)->treeOptional();

        // === include on all pages
        // --- I18N for JS MDE and enhanced links
        if ($cfg_link_active || $cfg_md_editor_active) {
            $includeRes .= "<script>window.I18N = " . Utils::getJsonI18N() . "; </script>";
        }
        // --- Home Link
        if ($cfg_home_active && $tree != null) {
            $params = [ 'tree' => $tree->name()];
            $url = $cfg_home_type == 1 ? route(TreePage::class, $params) : route(HomePage::class, $params);
            $docReadyJs .= '$(".wt-site-title").wrapInner(`<a class="' . self::STDCLASS_HOME_LINK .'" href="' . e($url) . '"></a>`);';

            $cfg_home_link_json = $this->getPref(self::PREF_HOME_LINK_JSON); // getPref returns trimmed string 
            if ($cfg_home_link_json) {
                $theme_palette = $theme . ($palette ? "_{$palette}" : ''); // palette is also set with other themes than colors
                $json = json_decode($cfg_home_link_json, true);
                if ($json) {
                    $stylerules = $json[$theme_palette] ?? $json[$theme] ?? $json['*'] ?? null;
                    if ($stylerules) {
                        $includeRes .= "<style>{$stylerules}</style>";
                    } elseif ($cfg_js_debug_console) {
                        $docReadyJs .= "console.debug('LE-Mod home link: JSON contains no matching style rule for current theme');";
                    }
                } else {
                    FlashMessages::addMessage(
                        I18N::translate('Home link - JSON with CSS rules seems to be invalid.'),
                        'warning'
                    );
                }
            }
        }
        
        // --- Link++
        if ($cfg_link_active) {
            $bundleShortcuts[] = 'le';

            $lecfg = $this->getPref(self::PREF_LINKSPP_JS); // getPref returns trimmed string
            $lecfg = $lecfg != '' ? $lecfg : '{}';
            $treename = ($tree !== null ? $tree->name() : '');

            $options = [
                'thisXref'     => Validator::attributes($request)->isXref()->string('xref', ''),
                'openInNewTab' => $this->getPref(self::PREF_LINKSPP_OPEN_IN_NEW_TAB, true),
                'tree'         => $treename,
                'baseurl'      => route(TreePage::class, [ 'tree' => $treename ]),
                'urlmode'      => (Validator::attributes($request)->boolean('rewrite_urls', false) ? 'pretty' : 'default'),
            ];
            $docReadyJs .= "LinkEnhMod.initLE($lecfg, " . json_encode($options) . ");";
        }

        // === include selectively
        // --- markdown support
        if ($cfg_md_active && $tree != null && $tree->getPreference('FORMAT_TEXT') == 'markdown') {
            if ($cfg_md_img_active || $cfg_md_ext_active) {
                // markdown image support
                $bundleShortcuts[] = 'img';

                $options = [
                    'I18N' => [
                        'limitheight'   => I18N::translate('Limit cell height'),
                    ],                    
                    'ext_fn'    => $this->getPref(self::PREF_MD_EXT_FN_ACTIVE, true),
                    'ext_toc'   => $this->getPref(self::PREF_MD_EXT_TOC_ACTIVE, true),
                    'td_h_ctrl' => $this->getPref(self::PREF_MD_TD_H_CTRL_TYPE, true),
                    'td_h_cb'   => $this->getPref(self::PREF_MD_TD_H_CB_VISIBLE, true),
                ];
                $docReadyJs .= "LinkEnhMod.initMd(" . json_encode($options) . ");";
            }

            if ($cfg_md_editor_active) {
                // --- TinyMDE -- only nessary on edit pages        
                if (Utils::isEditPage($request)) {
                    $bundleShortcuts[] = 'mde';
                    $docReadyJs .= 'window.LEhelp = "' . e(route('module', ['module' => $this->name(), 'action' => 'helpmd'])) . '";';

                    $options = [
                        'href'       => $cfg_link_active,
                        'src'        => $cfg_md_img_active,
                        'ext'        => $cfg_md_ext_active,
                        'ext_mark'   => $this->canActivateHighlightExtension(),
                        'ext_fn'     => $this->getPref(self::PREF_MD_EXT_FN_ACTIVE, true),
                        'ext_strike' => $this->getPref(self::PREF_MD_EXT_STRIKE_ACTIVE, true),
                        'todo'       => version_compare(Webtrees::VERSION, '2.2.5', '>='),
                    ];                    
                    $docReadyJs .= "LinkEnhMod.installMDE(" . json_encode($options) . ");";
                }
            }
        }
        
        $includeRes .= $this->getIncludeWebressourceString($bundleShortcuts);

        return $includeRes . Utils::getJavascriptWrapper($docReadyJs, $initJs);
    }

    /**
     * Raw content, to be added at the end of the <body> element.
     * Typically, this will be <script> elements.
     *
     * @return string
     */
    public function bodyContent(): string {
        $cfg_md_active        = $this->getPref(self::PREF_MD_ACTIVE, true);
        $cfg_md_editor_active = $cfg_md_active ? $this->getPref(self::PREF_MDE_ACTIVE, true) : false;
        $cfg_wthb_active      = $this->getPref(self::PREF_WTHB_ACTIVE, true);
        $cfg_wthb_tocnsearch  = $this->getPref(self::PREF_WTHB_TOCNSEARCH, true);

        $html = '';
        $needajax = false;

        if ($cfg_wthb_active) {
            $html .= view($this->name() . '::wthb-modal');
            $needajax = $cfg_wthb_tocnsearch;
        }
        if ($needajax || ($cfg_md_editor_active && Utils::isEditPage())) { // markdown editor is not useful on other pages
            $html .= view($this->name() . '::ajax');
        }
        return $html;
    }


    private function getIncludeWebressourceString(array $bundleShortcuts): string
    {
        if (!$bundleShortcuts) {
            return '';
        }
        $includeRes = '';
        asort($bundleShortcuts);
        $bundleShortcutsCss = $bundleShortcuts; //array_filter($bundleShortcuts, function ($var) { return $var !== 'wthb'; }); // wthb support - only js
        $bundleShortcutsJs = $bundleShortcuts; //array_filter($bundleShortcuts, fn($var) => $var !== 'img'); // markdown image support - only css

        // CSS
        if ($bundleShortcutsCss) {
            $infix = implode("-", $bundleShortcutsCss);
            $assetFile = $this->resourcesFolder() . "css/bundle-{$infix}.min.css";
            if (file_exists($assetFile)) {
                if (filesize($assetFile) > 500) {
                    $includeRes .= '<link rel="stylesheet" type="text/css" href="' . $this->assetUrl("css/bundle-{$infix}.min.css") . '">';
                } else {
                    $includeRes .= '<style>' . file_get_contents($assetFile) . '</style>';
                }
            }
        }

        // Javascript
        if ($bundleShortcutsJs) {
            $infix = implode("-", $bundleShortcutsJs);
            $includeRes .= '<script src="' . $this->assetUrl("js/bundle-{$infix}.min.js") . '"></script>';
        }
        return $includeRes;
    }
    
    /**
     * Open control panel page with options
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    public function getAdminAction(ServerRequestInterface $request): ResponseInterface
    {
        $this->layout = 'layouts/administration';
        return $this->viewResponse($this->name() . '::' . 'settings', $this->getInitializedOptions($request));
    }


    /**
     * Reset routes to dilivered status / shipped CSV
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    public function getAdminResetRoutesAction(ServerRequestInterface $request): ResponseInterface
    {
        $this->importDeliveredCsv();
        $csvfile = self::HELP_CSV;
        if (file_exists($csvfile)) {
            $this_hash = hash_file('sha256', $csvfile);
            if ($this_hash) {
                $this->setPref(self::PREF_WTHB_LASTHASH, $this_hash);
            }
        }
        return redirect($this->getConfigLink());
    }    

    /**
     * import in webtrees registered routes into help table
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    public function getAdminImportRoutesAction(ServerRequestInterface $request): ResponseInterface
    {
        $title = I18N::translate('Import registered routes');
        try {
            $result = $this->wthb->importRoutesAction($request);
            $this->wthb->setImportFlashOk($title, $result);
        } catch (Exception $ex) {
            $this->wthb->setImportFlashError($title, $ex->getMessage());
        }
        
        return redirect($this->getConfigLink());
    }

    /**
     * export Custom Module Manager config as csv
     * Jefferson49\Webtrees\Module\CustomModuleManager\Configuration\ModuleUpdateServiceConfiguration
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    public function getAdminCmmConfig2CsvAction(ServerRequestInterface $request): ResponseInterface
    {
        $filename = "wthb-route-mapping-export-cmm.csv";
        try {
            return $this->wthb->exportCmmCsvAction($filename, $request);

        } catch (Exception $ex) {
            FlashMessages::addMessage(
                /*I18N: webtrees.pot */ I18N::translate('Export failed') . ' - Custom Module Manager config<hr><samp dir="ltr">' . $ex->getMessage() . '</samp>',
                'danger'
            );
            return redirect($this->getConfigLink());
        }

    }    

    /**
     * Download context help mapping table as CSV
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    public function postAdminCsvExportAction(ServerRequestInterface $request): ResponseInterface
    {
        $filename = "wthb-route-mapping-export.csv";
        try {
            return $this->wthb->exportCsvAction($filename, $request);

        } catch (Exception $ex) {
            FlashMessages::addMessage(
                /*I18N: webtrees.pot */I18N::translate('Export failed') . '<hr><samp dir="ltr">' . $ex->getMessage() . '</samp>',
                'danger'
            );
            return redirect($this->getConfigLink());
        }
    }


    /**
     * import context help mapping from CSV into help table
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    public function postAdminCsvImportAction(ServerRequestInterface $request): ResponseInterface {
        try {
            $this->wthb->importCsvAction($request);
        } catch (Exception $ex) {
            FlashMessages::addMessage(
                /*I18N: webtrees.pot */ I18N::translate('Import failed') . '<hr><samp dir="ltr">' . $ex->getMessage() . '</samp>',
                'danger'
            );
        }
        return redirect($this->getConfigLink());   
    }


    /**
     * Save the user preferences in the database
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    public function postAdminAction(ServerRequestInterface $request): ResponseInterface
    {
        if (Validator::parsedBody($request)->string('save') === '1') {

            $preferences = array_diff(
                array_keys(self::PREFERENCES_SCHEMA),
                [ self::PREF_WTHB_LASTHASH ]
            );
            foreach ($preferences as $preference) {
                try {
                    $value = trim(Validator::parsedBody($request)->string($preference));
                    $this->setPref($preference, $value);
                } catch (Exception $ex) { //Fisharebest\Webtrees\Http\Exceptions\HttpBadRequestException
                    //TODO maybe compose warning flash message for preferences not found in request?!
                }
            }

            FlashMessages::addMessage(/*I18N: webtrees.pot */ I18N::translate(
                'The preferences for the module “%s” have been updated.',
                $this->title()
            ), 'success');
        }
        return redirect($this->getConfigLink());
    }


    /**
     * import shipped csv route mapping
     */
    protected function importDeliveredCsv(): void
    {
        $result = $this->wthb->importCsvFlash(self::HELP_CSV);
    }


    public function canActivateHighlightExtension(): bool {
        return ($this->getPref(self::PREF_MD_EXT_ACTIVE, true) 
            && $this->getPref(self::PREF_MD_EXT_MARK_ACTIVE, true)
            && class_exists('\\League\\CommonMark\\Extension\\Highlight\\HighlightExtension', true));
    }


    /**
     * Get a module setting. Return a user or Module default if the setting is not set.
     * extends getPreference
     *
     * @param string $setting_name
     * @param string $default
     *
     * @return mixed
     */
    public function getPref(string $setting_name, bool $typecasted = false): mixed
    {
        if ($this->prefs_cache === null) {
            $this->prefs_cache = $this->getAllPrefs();
        }
        $result = (array_key_exists($setting_name, $this->prefs_cache) ? $this->prefs_cache[$setting_name] : '');
        
        $value = trim(
            isset($result) && $result != ''
                ? $result 
                : (array_key_exists($setting_name, self::PREFERENCES_SCHEMA) ? (self::PREFERENCES_SCHEMA[$setting_name]['default'] ?? '') : '')
        );
        if ($typecasted) {
            $type  = (array_key_exists($setting_name, self::PREFERENCES_SCHEMA) ? (self::PREFERENCES_SCHEMA[$setting_name]['type'] ?? 'string') : 'string');
            $value = match ($type) {
                'int' => intval($value),
                'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
                default => (string) $value,
            };            
        }
        return $value;
    }

    /**
     * Load all module settings as array.
     *
     * @return array
     */    
    public function getAllPrefs(): array {
        $result = DB::table('module_setting')
            ->where('module_name', '=', $this->name())
            ->pluck('setting_value', 'setting_name')
            ->toArray();
        return $result;
    }


    /**
     * Set a module setting.
     *
     * Since module settings are NOT NULL, setting a value to NULL will cause
     * it to be deleted.
     *
     * extends/wraps setPreference
     * 
     * @param string $setting_name
     * @param string $setting_value
     *
     * @return void
     */
    public function setPref(string $setting_name, string $setting_value): void
    {
        //allow user to blank a setting, also if we have a DEFAULT_PREFERENCE
        if (array_key_exists($setting_name, self::PREFERENCES_SCHEMA) && (self::PREFERENCES_SCHEMA[$setting_name]['type'] ?? '') === 'string') {
            $setting_value = ((self::PREFERENCES_SCHEMA[$setting_name]['default'] ?? false) && !$setting_value ? ' ' : $setting_value);
        } else {
            $setting_value = (!$setting_value ? ' ' : $setting_value);
        }
        
        $this->setPreference($setting_name, $setting_value);
        if (is_array($this->prefs_cache)) {
            $this->prefs_cache[$setting_name] = $setting_value;
        }        
    }


    /**
     * prepare preferences and values used in settings view
     * @param ServerRequestInterface $request
     *
     * @return array
     */
    private function getInitializedOptions(ServerRequestInterface $request): array
    {
        $response = [];

        $response['title'] = $this->title();
        $response['description'] = $this->description();

        $preferences = array_keys(self::PREFERENCES_SCHEMA);
        foreach ($preferences as $preference) {
            $response['prefs'][$preference] = $this->getPref($preference);
        }

        $jsfile = $this->resourcesFolder() . 'js' . DIRECTORY_SEPARATOR . 'bundle-le-config.js';
        $jscode = '';
        if (file_exists($jsfile)) {
            $jscode = strval(file_get_contents($jsfile));
        }
        $response['jscode_linkpp'] = $jscode;
        
        $response['links'] = [];
        $response['links']['csvexport'] = route('module', [
            'module' => $this->name(),
            'action' => 'AdminCsvExport'
        ]);
        $response['links']['csvimport'] = route('module', [
            'module' => $this->name(),
            'action' => 'AdminCsvImport'
        ]);        
        $response['links']['routeimport'] = route('module', [
            'module' => $this->name(),
            'action' => 'AdminImportRoutes'
        ]);
        $response['links']['resetroutes'] = route('module', [
            'module' => $this->name(),
            'action' => 'AdminResetRoutes'
        ]);
        $response['links']['csvexportcmm'] = ($this->wthb->isCmmAvailable() ?
            route('module', [
                'module' => $this->name(),
                'action' => 'AdminCmmConfig2Csv'
            ])
            : ''
        );
        

        $response['tablerows'] = $this->wthb->getHelpTableCount();


        $tree_service = Registry::container()->get(TreeService::class);

        //FORMAT_TEXT = markdown
        $trees = $tree_service->all();
        $trees_w_md = [];
        $trees_w_text = [];
        foreach ($trees as $tree) {
            if ($tree->getPreference('FORMAT_TEXT') === 'markdown') {
                $trees_w_md[] = $tree->name();
            } else {
                $trees_w_text[] = $tree->name();
            }
        }
        $cntTotal = count($trees);
        $response['mdcfg'] = [
            'total'        => $cntTotal,
            'activated'    => count($trees_w_md),
            'trees_w_md'   => $trees_w_md,
            'trees_w_text' => $trees_w_text
        ];

        $response['vesta_common_enabled'] = $this->vesta_common_enabled;

        return $response;
    }


    /**
     * Serve help page.
     * Addressed by MDE command bar help icon (see tiny-mde-wt.js; url passed via window.LEhelp in headContent)
     *
     * @param ServerRequestInterface $request
     *
     * @return ResponseInterface
     */
    public function getHelpMdAction(ServerRequestInterface $request): ResponseInterface {
        // resources/views/edit/shared-note.phtml doesn't include < ?= view('modals/ajax') ? >
        // see also app/Http/RequestHandlers/HelpText.php
        //$topic = $request->getAttribute('topic');
        $title = /*I18N: webtrees.pot */ I18N::translate('Help') . ' - Markdown';
        $mdsyntax = Utils::getMarkdownHelpExamples(
            Validator::attributes($request)->string('base_url'),
            $this->getPref(self::PREF_MD_EXT_ACTIVE, true),
            $this->canActivateHighlightExtension(),
            $this->getPref(self::PREF_MD_EXT_STRIKE_ACTIVE, true),
            $this->getPref(self::PREF_MD_EXT_DL_ACTIVE, true),
            $this->getPref(self::PREF_MD_EXT_FN_ACTIVE, true)
        );
        $text  = view($this->name() . '::help-md', [
            'link_active'       => $this->getPref(self::PREF_LINKSPP_ACTIVE, true),
            'mdimg_active'      => $this->getPref(self::PREF_MD_IMG_ACTIVE, true),
            'mdsyntax'          => $mdsyntax,
            'mdimg_css_class1'  => $this->getPref(self::PREF_MD_IMG_STDCLASS),
            'mdimg_css_class2'  => $this->getPref(self::PREF_MD_IMG_TITLE_STDCLASS)
        ]);

        $html = view('modals/help', [
            'title' => $title,
            'text' => $text,
        ]);

        return response($html);        
    }

    /**
     * Serve help page for webtrees manual full-text search and table of contents
     * Addressed by top menu WTHB-Link (see XXX.js; url passed via window.LEhelp in headContent)
     *
     * @param ServerRequestInterface $request
     *
     * @return ResponseInterface
     */
    public function getHelpWthbAction(ServerRequestInterface $request): ResponseInterface
    {           
        $title = /*I18N: webtrees.pot */ I18N::translate('Help') . ' - ' . I18N::translate('Webtrees manual');
        $tochtml = view($this->name() . '::help-wthb-toc');
        $text = view($this->name() . '::help-wthb', [
            'toc_url'  => self::STDLINK_WTHB_TOC,
            'toc_html' => $tochtml,
            'search'   => $this->getSearchEngines(),
        ]);

        $html = view('modals/help', [
            'title' => $title,
            'text' => $text,
        ]);

        return response($html)
            ->withHeader('Cache-Control', 'public, max-age=86400, immutable')
            ->withHeader('Expires', gmdate('D, d M Y H:i:s', time() + 86400) . ' GMT') // force caching for Firefox
            ->withHeader('ETag', md5($html));
    }


    private function getSearchEngines() : array
    {
        // key   = Displayname without whitespaces, in lower case prepended with 'icon-' it's the css class name for background icon to be displayed
        // value = search engine url, append search terms uriencoded
        return [
            'GenWiki'    => 'https://wiki.genealogy.net/index.php?title=Spezial%3ASuche&profile=advanced&fulltext=1&ns0=1&ns6=1&search=%22Webtrees+Handbuch%22+',
            'Startpage'  => 'https://www.startpage.com/do/search?query=site:wiki.genealogy.net+"Webtrees%20Handbuch"+AND+',
            'Ecosia'     => 'https://www.ecosia.org/search?q=site%3Agenealogy.net%20%22webtrees%20handbuch%22%20AND%20',
            'mojeek'     => 'https://www.mojeek.com/search?q=inurl%3Agenealogy.net+%22Webtrees+Handbuch%22+',
            'Qwant'      => 'https://www.qwant.com/?t=web&q=site%3Awiki.genealogy.net+%22Webtrees+Handbuch%22+AND+',
            'Perplexity' => 'https://www.perplexity.ai/search/?q=site:wiki.genealogy.net%20inurl:%22Webtrees%20Handbuch%22+',
            'DuckDuckGo' => 'https://duckduckgo.com/?q=site:wiki.genealogy.net+inurl:"Webtrees%20Handbuch"+',
            'Google'     => 'https://www.google.com/search?q=site:wiki.genealogy.net+"webtrees+Handbuch"+AND+',
        ];
    }

    //same as Database::getSchema, but use module settings instead of site settings (Issue #3 in personal_facts_with_hooks)
    /* taken from modules_v4/vesta_common/VestaModuleTrait.php */
    protected function updateSchema($namespace, $schema_name, $target_version): bool
    {
        try {
            $current_version = intval($this->getPreference($schema_name));
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
            if (DB::table('module')->where('module_name', '=', $this->name())->exists()) {
                $this->setPreference($schema_name, (string) $current_version);
            }
            $updates_applied = true;
        }

        return $updates_applied;
    }

}