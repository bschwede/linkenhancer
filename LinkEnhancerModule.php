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

use Schwendinger\Webtrees\Module\LinkEnhancer\CustomMarkdownFactory;
use Fisharebest\Localization\Translation;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\View;
use Fisharebest\Webtrees\Module\AbstractModule;
use Fisharebest\Webtrees\Module\ModuleCustomInterface;
use Fisharebest\Webtrees\Module\ModuleCustomTrait;
use Fisharebest\Webtrees\Module\ModuleGlobalInterface;
use Fisharebest\Webtrees\Module\ModuleGlobalTrait;
use Fisharebest\Webtrees\Module\ModuleConfigInterface;
use Fisharebest\Webtrees\Module\ModuleConfigTrait;
use Fisharebest\Webtrees\Http\RequestHandlers\TreePage;
use Fisharebest\Webtrees\Http\RequestHandlers\HomePage;
use Fisharebest\Webtrees\Validator;
use Fisharebest\Webtrees\FlashMessages;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Illuminate\Database\Capsule\Manager as DB;
use Fisharebest\Webtrees\Schema\MigrationInterface;
use Fisharebest\Webtrees\Html;
use Schwendinger\Webtrees\Module\LinkEnhancer\Schema\SeedHelpTable;
use Fisharebest\Webtrees\Session;
use Fisharebest\Webtrees\Services\TreeService;
use Psr\Http\Message\StreamInterface;
use Fisharebest\Webtrees\GedcomFilters\GedcomEncodingFilter;
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
    public const CUSTOM_VERSION = '0.0.5';
    public const CUSTOM_LAST = 'https://raw.githubusercontent.com/' . self::GITHUB_USER . '/' .
        self::CUSTOM_MODULE . '/main/latest-version.txt';


    public const PREF_HOME_LINK_TYPE = 'HOME_LINK_TYPE'; // home link type: 0=off, 1=tree, 2=my-page
    public const PREF_HOME_LINK_JS = 'HOME_LINK_JS'; // string; javascript object { '*': stylerules-string, 'theme': stylerules-string}
    public const PREF_WTHB_ACTIVE = 'WTHB_LINK_ACTIVE'; // link to GenWiki "Webtrees Handbuch"
    public const PREF_WTHB_FAICON = 'WTHB_FAICON'; // prepend fa icon to help link
    public const PREF_WTHB_UPDATE = 'WTHB_UPDATE'; // auto refresh table on module update
    public const PREF_WTHB_LASTHASH = 'WTHB_LASTHASH'; // last csv hash used for import
    public const PREF_WTHB_STD_LINK = 'WTHB_STD_LINK'; // standard link to GenWiki "Webtrees Handbuch"
    public const PREF_JS_DEBUG_CONSOLE = 'JS_DEBUG_CONSOLE'; // console.debug with active route info; 0=off, 1=on
    public const PREF_GENWIKI_LINK = 'GENWIKI_LINK'; // base link to GenWiki
    public const PREF_MDE_ACTIVE = 'MDE_ACTIVE'; // enable markdown editor for note textareas
    public const PREF_LINKSPP_ACTIVE = 'LINKSPP_ACTIVE'; // enable links++
    public const PREF_LINKSPP_JS = 'LINKSPP_JS'; // Javascript

    public const PREF_MD_IMG_ACTIVE = 'MD_IMG_ACTIVE'; // enable enhanced markdown img syntax
    public const PREF_MD_IMG_STDCLASS = 'MD_IMG_STDCLASS'; // standard classname(s) for div wrapping img- and link-tag    
    public const PREF_MD_IMG_TITLE_STDCLASS = 'MD_IMG_TITLE_STDCLASS'; // standard classname(s) for picture subtitle

    public const STDCLASS_HOME_LINK = 'homelink';
    public const STDCLASS_MD_IMG = 'md-img';
    public const STDCLASS_MD_IMG_TITLE = 'md-img-title';
    public const STDLINK_GENWIKI = 'https://wiki.genealogy.net/';
    public const STDLINK_WTHB = 'https://wiki.genealogy.net/Webtrees_Handbuch';
    
    public const HELP_TABLE = 'route_help_map';

    protected const DEFAULT_PREFERENCES = [
        self::PREF_HOME_LINK_TYPE        => '1', //int triple-state, 0=off, 1=tree, 2=my-page
        self::PREF_HOME_LINK_JS          => '{ "*": ".homelink { color: #039; }" }', // string; javascript object { '*': stylerules-string, 'theme': stylerules-string}
        self::PREF_WTHB_ACTIVE           => '1', //bool
        self::PREF_WTHB_FAICON           => '1', //bool
        self::PREF_WTHB_UPDATE           => '1', //bool
        self::PREF_JS_DEBUG_CONSOLE            => '0', //bool
        self::PREF_WTHB_STD_LINK         => self::STDLINK_WTHB, //string
        self::PREF_GENWIKI_LINK          => self::STDLINK_GENWIKI, //string
        self::PREF_MDE_ACTIVE            => '1', //bool
        self::PREF_LINKSPP_ACTIVE        => '1', //bool
        self::PREF_LINKSPP_JS            => '',  //string
        self::PREF_MD_IMG_ACTIVE         => '1', //bool
        self::PREF_MD_IMG_STDCLASS       => self::STDCLASS_MD_IMG, //string
        self::PREF_MD_IMG_TITLE_STDCLASS => self::STDCLASS_MD_IMG_TITLE, //string
    ];

  
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
     * load csv data from stream into array needed to populate route_help_map table
     *
     * @param $stream
     * @param string $separator  character between data fields
     *
     * @return array<string>
     */
    public function loadCsvStream($stream, string $separator = ";") :array
    {
        $data = [];

        $header = fgetcsv($stream, null, $separator);

        while (($row = fgetcsv($stream, null, $separator)) !== false) {
            $data[] = array_combine($header, $row);
        }

        return $data;
    }    

    public function importDeliveredCsv() {
        $csvfile = __DIR__ . DIRECTORY_SEPARATOR . 'Schema/SeedHelpTable.csv';
        try {
            $result = $this->importCsv2HelpTable($csvfile);
            FlashMessages::addMessage(
                I18N::translate('Routes imported (Total: %s / skipped: %s)', $result['total'], $result['skipped']),
                'success'
            );
        } catch (Exception $ex) {
            FlashMessages::addMessage(
                $ex->getMessage(),
                'danger'
            );
        }            
    }


    public function importCsv2HelpTable(string|StreamInterface $file, string $separator = ';', bool $truncate = true, string $encoding = '')  {
        $result = [ 'total' => 0, 'skipped' => 0 ];
        $data = [];
        if (gettype($file) == 'string') {
            if (file_exists($file)) {
                $stream = fopen($file, 'r');
                $data = $this->loadCsvStream($stream, $separator);
                fclose($stream);
            } else {
                return $result;
            }
        } else {
            // similar to the implementation in:
            // - app/Services/TreeService.php
            // - resources/views/admin/trees-import.phtml
            $stream = $file->detach();
            stream_filter_append($stream, GedcomEncodingFilter::class, STREAM_FILTER_READ, ['src_encoding' => $encoding]);
            $data = $this->loadCsvStream($stream,$separator);
        }

        $seeder = new SeedHelpTable($data, $truncate);
        $seeder->run();
        $result = ['total' => $seeder->cntRowsTotal, 'skipped' => $seeder->cntRowsSkipped];

        return $result;
    }


    /**
     * Called for all *enabled* modules.
     */
    public function boot(): void
    {
        $this->updateSchema('\Schwendinger\Webtrees\Module\LinkEnhancer\Schema', 'SCHEMA_VERSION', 1);

        $importOnUpdate = false;
        $this_hash = null;
        $cfg_wthb_update = boolval($this->getPref(self::PREF_WTHB_UPDATE));
        if ($cfg_wthb_update) {
            $csvfile = __DIR__ . "/Schema/SeedHelpTable.csv";
            if (file_exists($csvfile)) {
                $this_hash = hash_file('sha256', $csvfile);
                $cfg_wthb_lasthash = $this->getPref(self::PREF_WTHB_LASTHASH);
                $importOnUpdate = $this_hash != $cfg_wthb_lasthash;
            }            
        }

        if ((int) ($this->getHelpTableCount()['total'] ?? 0) === 0 || $importOnUpdate) {
            $this->importDeliveredCsv();
            if ($this_hash) {
                $this->setPref(self::PREF_WTHB_LASTHASH, $this_hash);
            }
        }

        // Register a namespace for our views.
        View::registerNamespace($this->name(), $this->resourcesFolder() . 'views/');

        if (boolval($this->getPref(self::PREF_MD_IMG_ACTIVE))) {
            Registry::markdownFactory(new CustomMarkdownFactory($this));
        }
    }

    /**
     * Query route to help mapping table for total count of rows and count of mapped rows (where an url is set)
     *
     * @return array
     */
    public function getHelpTableCount():array {
        $totalCnt = 0;
        $mappedCnt = 0;
        if (DB::schema()->hasTable(self::HELP_TABLE)) {
            try {
                $totalCnt  = DB::table(self::HELP_TABLE)->count();
                $mappedCnt = DB::table(self::HELP_TABLE)
                    ->whereNotNull('url')
                    ->where('url', '!=', '')
                    ->count();
            } catch (Exception $e) {}
        }

        return [ 'total' => (int) $totalCnt, 'assigned' => (int) $mappedCnt];
    }

    /**
     * Query route to help mapping table for matching entries
     *
     * @param array|null $activeroute
     *
     * @return mixed
     */
    public function getContextHelp(array|null $activeroute = null): mixed  {
        $std_url = $this->getPref(self::PREF_WTHB_STD_LINK, self::STDLINK_WTHB);
        if (!DB::schema()->hasTable(self::HELP_TABLE)) {
            FlashMessages::addMessage(
                I18N::translate('Table for context help is missing - fallback to standard url'),
                'info'
            );
            return $std_url;
        }
        $url = $std_url;
        $wiki_url = rtrim($this->getPref(self::PREF_GENWIKI_LINK, self::STDLINK_GENWIKI), '/') . '/';
        $activeroute ??= $this->getActiveRoute();
        
        if ($activeroute) {
            // custom module?
            $module = str_starts_with($activeroute['path'],"/" . "module/" ) && ($activeroute['attr']['module'] ?? false)
                ? $activeroute['attr']['module']
                : '';

            // WHERE url is not null AND
            // (
            //      (path = route[path] AND handler = route[handler]) 
            //   OR (path = route[path] AND handler = module)         #if route.path ^=/module/
            //   OR (handler = module)                                #if route.path ^=/module/
            //   OR (category=generic AND extras=route[extras])       #last try by Auth-Level
            // )
            $result = DB::table(self::HELP_TABLE)
                ->whereNotNull('url')
                ->where('url', '!=', '')
                ->where(function($query) use ($module, $activeroute) {
                    $query
                        ->where('path', '=', $activeroute['path'])
                        ->where('handler', '=', $activeroute['handler'])
                        ->when($module != '', function($query2) use ($module, $activeroute) {
                            $query2
                                ->orWhere('path', '=', $activeroute['path'])
                                ->where('handler', '=', $module)
                                ->orWhere('handler', '=', $module);
                        })
                        ->when(($activeroute['extras'] ?? false), function($query3) use ($activeroute) {
                            $query3
                                ->orWhere('category', '=', 'generic')
                                ->where('extras', '=', $activeroute['extras']);
                        });
                })
                ->orderBy('order')
                ->get()
                ->map(function ($obj) use ($std_url, $wiki_url): mixed { // complete url by appending prefix to url path - also external url are possible
                    $first_url = trim($obj->url);
                    $obj->url = $first_url == '' ? $std_url : (preg_match('/^https?:\/\//', $first_url) ? $first_url : $wiki_url . ltrim($first_url, '/'));
                    return $obj;
                });
                        
            if (!$result->isEmpty()) {
                return $result;
            }
        }

        return $url;
    }

    /**
     * returns informations for active route of current request; needed for context help
     *
     * @param ServerRequestInterface|null $request
     *
     * @return array
     */    
    public function getActiveRoute(ServerRequestInterface|null $request = null) : array {
        $request ??= Registry::container()->get(ServerRequestInterface::class);

        $route = $request->getAttribute('route');
        if ($route) {
            $extras = is_array($route->extras) && isset($route->extras['middleware']) ? implode('|', $route->extras['middleware']) : '';
            return [
                'path'    => $route->path,
                'handler' => $route->name,
                'method'  => implode('|', $route->allows),
                'extras'  => $extras,
                'attr'    => $route->attributes
            ];

        }
        return [];
    }


    /**
     * import in webtrees registered routes into table route_help_map
     *
     * @return array  count of rows in total and skipped
     */
    public function importRoutes(): array
    {
        $router = Registry::routeFactory()->routeMap();
        $existingRoutes = $router->getRoutes();
        $data = [];

        foreach ($existingRoutes as $route) {
            $extras = is_array($route->extras) && isset($route->extras['middleware']) ? implode('|', $route->extras['middleware']) : '';
            $data[] = [
                'path' => $route->path,
                'handler' => $route->name,
                'method' => implode('|', $route->allows),
                'extras' => $extras,
                'attr' => $route->attributes
            ];            
        }

        $result = [ 'total' => 0, 'skipped' => 0 ];
        $seeder = new SeedHelpTable($data, false);
        $seeder->run();
        $result = ['total' => $seeder->cntRowsTotal, 'skipped' => $seeder->cntRowsSkipped];        
        return $result;
    }


    /**
     * Get a module setting. Return a user or Module default if the setting is not set.
     * extends getPreference
     *
     * @param string $setting_name
     * @param string $default
     *
     * @return string
     */
    public function getPref(string $setting_name, string $default = ''): string
    {
        $result = $this->getPreference($setting_name, $default);
        return trim(isset($result) && $result != '' ? $result : self::DEFAULT_PREFERENCES[$setting_name] ?? '');
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
    public function setPref(string $setting_name, string $setting_value): void {
        //allow user to blank a setting, also if we have a DEFAULT_PREFERENCE
        $setting_value = ((self::DEFAULT_PREFERENCES[$setting_name] ?? false) && !$setting_value ? ' ': $setting_value);
        $this->setPreference($setting_name, $setting_value);
    }

    /**
     * Wrapper for init javascript when document is ready
     * 
     * @param string $initJs
     *
     * @return string
     */
    public function getInitJavascript(string $initJs): string
    {
        return $initJs ? "<script>document.addEventListener('DOMContentLoaded', function(event) { " . $initJs . "});</script>" : '';
    }

    /**
     * Wrapper for iife javascript statements, the innerJs is embedded in try-catch with eval in order to intercept also SyntaxErrors
     * caused by user configuration. So there is no impact to other components.
     * 
     * @param string $innerJs            javascript code to be wrapped in iife
     * @param string $iife_paramnames    parameter definition for iife
     * @param string $iife_paramvalues   values that are passed in to the innerJs
     * @param string $errorhint          additional error message, e.g. component name
     * @return string
     */
    public function getIifeJavascript(string $innerJs, string $iife_paramnames = '', string $iife_paramvalues = '', string $errorhint = ''): string
    {
        if (!$innerJs) return '';
        
        $iife = "(({$iife_paramnames}) => {" . $innerJs . "})({$iife_paramvalues})";       
        $iife = json_encode($iife);
        $errorhint = json_encode($errorhint);
        return "<script>try{eval({$iife});} catch (e){console.error('LE-Mod Error: check parameter', {$errorhint}, e);}</script>";
    }    

    /**
     * Raw content, to be added at the end of the <head> element.
     * Typically, this will be <link> and <meta> elements.
     *
     * @return string
     */
    public function headContent(): string
    {
        $cfg_home_type = intval($this->getPref(self::PREF_HOME_LINK_TYPE)); // 0=off, 1=Home, 2=My-Page
        $cfg_home_active = boolval($cfg_home_type);
        $cfg_wthb_active = boolval($this->getPref(self::PREF_WTHB_ACTIVE));
        $cfg_js_debug_console = boolval($this->getPref(self::PREF_JS_DEBUG_CONSOLE));
        $cfg_mde_active = boolval($this->getPref(self::PREF_MDE_ACTIVE));
        $cfg_link_active = boolval($this->getPref(self::PREF_LINKSPP_ACTIVE));
        $cfg_img_active = boolval($this->getPref(self::PREF_MD_IMG_ACTIVE));
        

        if (!$cfg_home_active && !$cfg_wthb_active && ! $cfg_mde_active && !$cfg_link_active) {
            return '';
        }
        
        $request = Registry::container()->get(ServerRequestInterface::class);
        //ressources to include
        $bundleShortcuts = [];
        $includeRes = '';
        $initJs = ''; // init on document ready

        $theme = Session::get('theme');
        $palette = Session::get('palette', '');
        
        $activeRouteInfo = $this->getActiveRoute($request);
        if ($cfg_js_debug_console) {
            $initJs .= "console.debug('LE-Mod theme:', '$theme'" . ($palette ? ", 'palette=$palette'" : '') . ");";
            $initJs .= "console.debug('LE-Mod active route:', " . json_encode($activeRouteInfo) .");";
        }

        // --- Webtrees Handbuch Link
        if ($cfg_wthb_active) {
            $jsfile = $this->resourcesFolder() . 'js/bundle-wthb-link.min.js';
            if (file_exists($jsfile)) {
                $cfg_wthb_faicon = boolval($this->getPref(self::PREF_WTHB_FAICON)) ? 'true' : 'false';

                $help = $this->getContextHelp($activeRouteInfo);
                if ($cfg_js_debug_console) {
                    $initJs .= "console.debug('LE-Mod help:', " . json_encode($help) . ");";
                }

                $help_url = gettype($help) == 'string' ? $help : $help->first()->url;
                
                // link to Webtrees Manual in GenWiki or external link?
                $wiki_url = $this->getPref(self::PREF_GENWIKI_LINK, self::STDLINK_GENWIKI);
                $help_title = str_starts_with($help_url, $wiki_url) ? I18N::translate('Webtrees manual') : /*I18N: webtrees.pot */ I18N::translate('Help');
                
                $help_url_e = e($help_url);
                $includeRes .= $this->getIifeJavascript(
                    file_get_contents($jsfile),
                    "help_title, help_url, faicon",
                    "'{$help_title}', '{$help_url_e}', {$cfg_wthb_faicon}",
                    "wthb-link"
                );                
            } else {
                // TODO error flash?
            }
        }        

        // === admin backend - only if patch P002 for administration.phtml was applied; default: headContent of custom modules is not called on the admin backend
        // TODO - is it possible to determine the underlying page layout or should the info for backend pages be stored in DB?!
        $action = strtolower(($request->getAttribute('action') ?? ''));

        if (str_starts_with($activeRouteInfo['path'], '/admin') 
            || str_contains($activeRouteInfo['extras'], 'AuthAdministrator')
            || (str_contains($activeRouteInfo['extras'], 'AuthManager') && !str_contains($activeRouteInfo['path'], '/tree-page-'))
            || (str_starts_with($activeRouteInfo['path'], '/module') && str_starts_with($action, 'admin'))
        ) {
                if ($cfg_wthb_active) {
                    return $includeRes . $this->getInitJavascript($initJs);
                }
                return ''; # other stuff is of no use in admin backend
        }


        $tree = Validator::attributes($request)->treeOptional();

        // === include on all pages
        // --- I18N for JS MDE and enhanced links
        if ($cfg_link_active || $cfg_mde_active) {
            $includeRes .= "<script>window.I18N = " . $this->getJsonI18N() . "; </script>";
        }
        // --- Home Link
        if ($cfg_home_active && $tree != null) {
            $params = [ 'tree' => $tree->name()];
            $url = $cfg_home_type == 1 ? route(TreePage::class, $params) : route(HomePage::class, $params);
            $initJs .= '$(".wt-site-title").wrapInner(`<a class="' . self::STDCLASS_HOME_LINK .'" href="' . e($url) . '"></a>`);';

            $cfg_home_link_json = trim($this->getPref(self::PREF_HOME_LINK_JS));
            if ($cfg_home_link_json) {
                $theme_palette = $theme . ($palette ? "_{$palette}" : ''); // palette is also set with other themes than colors
                $json = json_decode($cfg_home_link_json, true);
                if ($json) {
                    $stylerules = $json[$theme_palette] ?? $json[$theme] ?? $json['*'] ?? null;
                    if ($stylerules) {
                        $includeRes .= "<style>{$stylerules}</style>";
                    } elseif ($cfg_js_debug_console) {
                        $initJs .= "console.debug('LE-Mod home link: JSON contains no matching style rule for current theme');";
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
            $lecfg = $this->getPref(self::PREF_LINKSPP_JS);
            $initJs .= "LinkEnhMod.initLE($lecfg);";
        }

        // === include selectively
        // --- markdown support
        if ($tree != null && $tree->getPreference('FORMAT_TEXT') == 'markdown') {
            if ($cfg_img_active) {
                // markdown image support
                $bundleShortcuts[] = 'img';
            }

            if ($cfg_mde_active) {
                // --- TinyMDE -- only nessary on edit pages
                $route = Validator::attributes($request)->route();
                $routename = basename(strtr($route->name ?? '/', ['\\' => '/']));
        
                if (in_array($routename, ['EditFactPage', 'EditNotePage', 'AddNewFact'])) {
                    $fact = '';
                    try {
                        $fact = Validator::attributes($request)->string('fact');
                    } catch (Exception $e) {
                    }

                    if (($routename == 'AddNewFact' && $fact == 'NOTE') || $routename != 'AddNewFact') {
                        $bundleShortcuts[] = 'mde';
                        $initJs .= 'window.LEhelp = "' . e(route('module', ['module' => $this->name(), 'action' => 'help'])) . '";';
                        
                        $linkSupport = [];
                        if (! $cfg_link_active) $linkSupport[] = "href:0";
                        if (! $cfg_img_active) $linkSupport[] = "src:0";
                        $linkCfg = implode(',', $linkSupport);
                        $linkCfg = $linkCfg ? '{' . $linkCfg . '}' : '';
                        $initJs .= "LinkEnhMod.installMDE($linkCfg);";
                    }
                }
            }
        }
        
        
        if ($bundleShortcuts) {
            asort($bundleShortcuts);
            $infix = implode("-",$bundleShortcuts);
            $assetFile = $this->resourcesFolder() . "css/bundle-{$infix}.min.css";
            if (file_exists($assetFile)) {
                if (filesize($assetFile) > 500) {
                    $includeRes .= '<link rel="stylesheet" type="text/css" href="' . $this->assetUrl("css/bundle-{$infix}.min.css") . '">';
                } else {
                    $includeRes .= '<style>' . file_get_contents($assetFile) . '</style>';
                }
            }
            
            $bundleShortcuts = array_filter($bundleShortcuts, function($var) { return $var !== 'img'; });
            if ($bundleShortcuts) { // markdown image support - only css
                $infix = implode("-", $bundleShortcuts);
                $includeRes .= '<script src="' . $this->assetUrl("js/bundle-{$infix}.min.js") . '"></script>';
            }
        }

        return $includeRes . $this->getInitJavascript($initJs);
    }

    /**
     * Raw content, to be added at the end of the <body> element.
     * Typically, this will be <script> elements.
     *
     * @return string
     */
    public function bodyContent(): string {
        $cfg_mde_active = boolval($this->getPref(self::PREF_MDE_ACTIVE));

        if ($cfg_mde_active) {
            $request = Registry::container()->get(ServerRequestInterface::class);
            $route = Validator::attributes($request)->route();
            
            if (strstr($route->name, 'EditNotePage')) {
                //fix - should be included in resources/views/edit/shared-note.phtml
                return view('modals/ajax');
            }
            
        }
        return '';
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
        return redirect($this->getConfigLink());
    }    

    /**
     * Download context help mapping table as CSV
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    public function getAdminImportRoutesAction(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $result = $this->importRoutes();
            FlashMessages::addMessage(
                I18N::translate('Routes imported (Total: %s / skipped: %s)', $result['total'], $result['skipped']),
                'success'
            );          
        } catch (Exception $ex) {
            FlashMessages::addMessage(
                $ex->getMessage(),
                'danger'
            );
        }
        return redirect($this->getConfigLink());
    }

    private function getSeparator(ServerRequestInterface $request) :string {
        //$sepsallowed = [';', ',', '|', ':', '\\t'];
        $separator = trim(Validator::parsedBody($request)->string('csv-separator'));
        $separator = $separator == '\\t' ? "\t" : $separator;
        if (strlen($separator) === 0) {
            throw new Exception(I18N::translate('Separator is not set.'));
        }
        if (strlen($separator) > 1) {
            throw new Exception(I18N::translate('For the separator is only a single character allowed.'));
        }
        return $separator;
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
        $headers = [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . addcslashes($filename, '"') . '"',
        ];

        try {
            $separator = $this->getSeparator($request);
            if (!DB::schema()->hasTable(self::HELP_TABLE)) {
                throw new Exception(I18N::translate('Table for context help is missing - nothing to do.'));
            }

            $data = DB::table(self::HELP_TABLE)->get();

            if (count($data) == 0) {
                throw new Exception(I18N::translate('No data available for export.'));
            }

            ob_start();
            $file = fopen('php://output', 'w');
            $columns = array_keys(get_object_vars($data->first()));
            fputcsv($file, $columns, $separator, "\"", "\\", "\n");
            foreach ($data as $datarow) {
                $row = [];
                foreach ($columns as $column) {
                    $row[] = $datarow->$column;
                }
                fputcsv($file, $row, $separator, "\"", "\\", "\n");
            }
            fclose($file);
            $csv = ob_get_clean();

            return response($csv, 200, $headers);

        } catch (Exception $ex) {
            FlashMessages::addMessage(
                /*I18N: webtrees.pot */I18N::translate('Export failed') . '<hr><samp dir="ltr">' . $ex->getMessage() . '</samp>',
                'danger'
            );
            return redirect($this->getConfigLink());
        }
    }

    public function postAdminCsvImportAction(ServerRequestInterface $request): ResponseInterface {
        $encodings = ['' => ''] + Registry::encodingFactory()->list();
        $encoding = Validator::parsedBody($request)->isInArrayKeys($encodings)->string('encoding');

        try {
            if (!DB::schema()->hasTable(self::HELP_TABLE)) {
                throw new Exception(I18N::translate('Table for context help is missing - nothing to do.'));
            }

            $separator = $this->getSeparator($request);

            $truncate = Validator::parsedBody($request)->boolean('table-truncate', false);

            $csv_file = $request->getUploadedFiles()['csv-file'] ?? null;
            if ($csv_file === null || $csv_file->getError() === UPLOAD_ERR_NO_FILE) {
                throw new Exception(I18N::translate('No CSV file was received.'));
            }
            if ($csv_file->getError() !== UPLOAD_ERR_OK) {
                throw new FileUploadException($csv_file);
            }
            //app/Services/TreeService.php
            $stream = $csv_file->getStream();
            //$stream = $stream->detach();

            $result = $this->importCsv2HelpTable($stream, $separator, $truncate, $encoding);
            FlashMessages::addMessage(
                I18N::translate('Routes imported (Total: %s / skipped: %s)', $result['total'], $result['skipped']),
                'success'
            );
        } catch (Exception $ex) {
            FlashMessages::addMessage(
                /*I18N: webtrees.pot */ I18N::translate('Import failed') . '<hr><samp dir="ltr">' . $ex->getMessage() . '</samp>',
                'danger'
            );
        }
        return redirect($this->getConfigLink());   
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

        $preferences = array_keys(self::DEFAULT_PREFERENCES);
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
        

        $response['tablerows'] = $this->getHelpTableCount();


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


        return $response;
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

            $preferences = array_keys(self::DEFAULT_PREFERENCES);
            foreach ($preferences as $preference) {
                $this->setPref($preference, trim(Validator::parsedBody($request)->string($preference)));
            }

            FlashMessages::addMessage(/*I18N: webtrees.pot */I18N::translate(
                'The preferences for the module “%s” have been updated.',
                $this->title()
            ), 'success');
        }
        return redirect($this->getConfigLink());
    }


    /**
     * wrap I18N strings needed by javascript routines in a json object
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    public function getJsonI18N() : string {
        return json_encode([
            // MDE
            'bold'             => /*I18N: JS MDE */ I18N::translate('bold'),
            'italic'           => /*I18N: JS MDE */ I18N::translate('italic'),
            'format as code'   => /*I18N: JS MDE */ I18N::translate('format as code'),
            'Level 1 heading'  => /*I18N: JS MDE */ I18N::translate('Level 1 heading'),
            'Bulleted list'    => /*I18N: JS MDE */ I18N::translate('Bulleted list'),
            'Numbered list'    => /*I18N: JS MDE */ I18N::translate('Numbered list'),
            'Insert link'      => /*I18N: JS MDE */ I18N::translate('Insert link'),
            'Insert image'     => /*I18N: JS MDE */ I18N::translate('Insert image'),
            'hr'               => /*I18N: JS MDE */ I18N::translate('Horizontal rule'),
            'Undo'             => /*I18N: JS MDE */ I18N::translate('Undo'),
            'Redo'             => /*I18N: JS MDE */ I18N::translate('Redo'),
            'Help'             => /*I18N: webtrees.pot */ I18N::translate('Help'),
            'Link destination' => /*I18N: JS MDE */ I18N::translate('Link destination'),
            'Insert table'     => /*I18N: JS MDE */ I18N::translate('Insert table'),
            'queryTableCnR'    => /*I18N: JS MDE */ I18N::translate('How many columns and rows should the table have (input: [number] [number])?'),
            // enhanced links 
            'cross reference'  => /*I18N: JS enhanced link */ I18N::translate('cross reference'),
            'oofb'             => /*I18N: JS enhanced link, %s name of location */ I18N::translate('Online Local heritage book of %s at CompGen', '%s'),
            'gov'              => /*I18N: JS enhanced link */ I18N::translate('The Historic Geo Information System (GOV)'),
            'www'              => /*I18N: JS enhanced link */ I18N::translate('wer-wir-waren.at'),
            'ewp'              => /*I18N: JS enhanced link */ I18N::translate('Residents database - Family research in West Prussia'),
            'Interactive tree' => /*I18N: webtrees.pot */ I18N::translate('Interactive tree'),
            'syntax error'     => /*I18N: JS enhanced link */ I18N::translate('Syntax error'),
            'wt-help1'         => /*I18N: JS enhanced link wt1 - %s=rectypes*/ I18N::translate('standard link to note (available record types: %s) with XREF in active tree', '%s'),
            'wt-help2'         => /*I18N: JS enhanced link wt2 */ I18N::translate('link to record type individual with XREF from "othertree" and also link to'),
            'osm-help1'        => /*I18N: JS enhanced link osm1 */ I18N::translate('zoom/lat/lon for locating map'),
            'osm-help2'        => /*I18N: JS enhanced link osm2 */ I18N::translate('same as before with additional marker'),
            'osm-help3'        => /*I18N: JS enhanced link osm3 */ I18N::translate('show also node/way/relation, see also'),
            'osm-help4'        => /*I18N: JS enhanced link osm4 */ I18N::translate('show also node/way/relation - alternative notation'),
            'ofb-help1'        => /*I18N: JS enhanced link ofb1 */ I18N::translate('link to Online Local heritage book at CompGen with given uid'),
            'wp-help1'         => /*I18N: JS enhanced link wp1 */ I18N::translate('open the article in the german wikipedia'),
            'wp-help2'         => /*I18N: JS enhanced link wp2 */ I18N::translate('open the english version of the article'),
        ]);
    }

   /**
     * Markdown examples for help screen
     *
     * @param ServerRequestInterface $request
     * @return array
     */
    public function getMarkdownHelpExamples(ServerRequestInterface $request) : array {
        $base_url = Validator::attributes($request)->string('base_url');
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
                'md' => '# ' . I18N::translate('Level 1 heading'),
                'html' => '<h1>' . I18N::translate('Level 1 heading') . '</h1>'
            ],
            [
                'md' => '`' . I18N::translate('format as code') . '`',
                'html' => '<code>' . I18N::translate('format as code') . '</code>'
            ],
            [
                'md' => str_repeat('- ' . I18N::translate('Bulleted list') . "\n", 2),
                'html' => "<ul>\n" . str_repeat('  <li>' . I18N::translate('Bulleted list') . "</li>\n", 2) . '</ul>'
            ],
            [
                'md' => str_repeat('1. ' . I18N::translate('Numbered list') . "\n", 2),
                'html' => "<ol>\n" . str_repeat('  <li>' . I18N::translate('Numbered list') . "</li>\n", 2) . '</ol>'
            ],
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
        ];

        return $mdsyntax;
    }

    /**
     * Serve help page.
     *
     * @param ServerRequestInterface $request
     *
     * @return ResponseInterface
     */
    public function getHelpAction(ServerRequestInterface $request): ResponseInterface {
        // resources/views/edit/shared-note.phtml doesn't include < ?= view('modals/ajax') ? >
        // see also app/Http/RequestHandlers/HelpText.php
        //$topic = $request->getAttribute('topic');

        $title = /*I18N: webtrees.pot */ I18N::translate('Help') . ' - Markdown';
        $text  = view($this->name() . '::help-md', [
            'link_active'       => boolval($this->getPref(self::PREF_LINKSPP_ACTIVE)),
            'mdimg_active'      => boolval($this->getPref(self::PREF_MD_IMG_ACTIVE)),
            'mdsyntax'          => $this->getMarkdownHelpExamples($request),
            'mdimg_css_class1'  => $this->getPref(self::PREF_MD_IMG_STDCLASS),
            'mdimg_css_class2'  => $this->getPref(self::PREF_MD_IMG_TITLE_STDCLASS)
        ]);

        $html = view('modals/help', [
            'title' => $title,
            'text' => $text,
        ]);

        return response($html);        
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