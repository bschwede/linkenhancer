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

use Schwendinger\Webtrees\Module\LinkEnhancer\Factories\CustomMarkdownFactory;
use Schwendinger\Webtrees\Module\LinkEnhancer\LinkEnhancerUtils as Utils;
use Schwendinger\Webtrees\Module\LinkEnhancer\Services\WthbService;
use Fisharebest\Localization\Translation;
use Fisharebest\Webtrees\FlashMessages;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Session;
use Fisharebest\Webtrees\Validator;
use Fisharebest\Webtrees\View;
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
    public const CUSTOM_VERSION = '1.1.1';
    public const CUSTOM_LAST = 'https://raw.githubusercontent.com/' . self::GITHUB_USER . '/' .
        self::CUSTOM_MODULE . '/main/latest-version.txt';


    public const PREF_HOME_LINK_TYPE = 'HOME_LINK_TYPE'; // home link type: 0=off, 1=tree, 2=my-page
    public const PREF_HOME_LINK_JSON = 'HOME_LINK_JS'; // string; javascript object { '*': stylerules-string, 'theme': stylerules-string}
    public const EXAMPLE_HOME_LINK_JSON = '{ "*": ".homelink { color: #039; }",  "colors_nocturnal": ".homelink { color: antiquewhite; }" }';
    public const PREF_WTHB_ACTIVE = 'WTHB_LINK_ACTIVE'; // link to GenWiki "Webtrees Handbuch"
    public const PREF_WTHB_FAICON = 'WTHB_FAICON'; // prepend fa icon to help link
    public const PREF_WTHB_UPDATE = 'WTHB_UPDATE'; // auto refresh table on module update
    public const PREF_WTHB_LASTHASH = 'WTHB_LASTHASH'; // last csv hash used for import
    public const PREF_WTHB_STD_LINK = 'WTHB_STD_LINK'; // standard link to GenWiki "Webtrees Handbuch"
    public const PREF_WTHB_TRANSLATE = 'WTHB_TRANSLATE'; // use translation service for webtrees manual pages
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

    public const HELP_CSV = __DIR__ . DIRECTORY_SEPARATOR . 'Schema' . DIRECTORY_SEPARATOR . 'SeedHelpTable.csv';

    public const HELP_SCHEMA_TARGET_VERSION = 1; //2

    protected const DEFAULT_PREFERENCES = [
        self::PREF_HOME_LINK_TYPE        => '1', //int triple-state, 0=off, 1=tree, 2=my-page
        self::PREF_HOME_LINK_JSON        => '', // string; json object { '*': stylerules-string, 'theme': stylerules-string}
        self::PREF_WTHB_ACTIVE           => '1', //bool
        self::PREF_WTHB_FAICON           => '1', //bool
        self::PREF_WTHB_UPDATE           => '1', //bool
        self::PREF_JS_DEBUG_CONSOLE      => '0', //bool
        self::PREF_WTHB_STD_LINK         => self::STDLINK_WTHB, //string
        self::PREF_GENWIKI_LINK          => self::STDLINK_GENWIKI, //string
        self::PREF_WTHB_TRANSLATE        => '1', //int triple-state. 0=off, 1=user defined, 2=on
        self::PREF_MDE_ACTIVE            => '1', //bool
        self::PREF_LINKSPP_ACTIVE        => '1', //bool
        self::PREF_LINKSPP_JS            => '',  //string
        self::PREF_MD_IMG_ACTIVE         => '1', //bool
        self::PREF_MD_IMG_STDCLASS       => self::STDCLASS_MD_IMG, //string
        self::PREF_MD_IMG_TITLE_STDCLASS => self::STDCLASS_MD_IMG_TITLE, //string
    ];

    protected WthbService $wthb;

    public function __construct()
    {
        $std_url = $this->getPref(self::PREF_WTHB_STD_LINK, self::STDLINK_WTHB);
        $wiki_url = rtrim($this->getPref(self::PREF_GENWIKI_LINK, self::STDLINK_GENWIKI), '/') . '/';

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

        $importOnUpdate = false;
        $this_hash = null;
        $cfg_wthb_update = boolval($this->getPref(self::PREF_WTHB_UPDATE));
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

        // Register a namespace for our views.
        View::registerNamespace($this->name(), $this->resourcesFolder() . 'views/');

        if (boolval($this->getPref(self::PREF_MD_IMG_ACTIVE))) {
            Registry::markdownFactory(new CustomMarkdownFactory($this));
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
        $docReadyJs = ''; // init on document ready
        $initJs = '';

        $theme = Session::get('theme');
        $palette = Session::get('palette', '');
        
        $activeRouteInfo = $this->wthb->getActiveRoute($request);
        if ($cfg_js_debug_console) {
            $docReadyJs .= "console.debug('LE-Mod theme:', '$theme'" . ($palette ? ", 'palette=$palette'" : '') . ");";
            $docReadyJs .= "console.debug('LE-Mod active route:', " . json_encode($activeRouteInfo) .");";
        }

        // --- Webtrees Handbuch Link
        if ($cfg_wthb_active) {
            $jsfile = $this->resourcesFolder() . 'js/bundle-wthb-link.min.js';
            if (file_exists($jsfile)) {
                $help = $this->wthb->getContextHelp($activeRouteInfo);
                if ($cfg_js_debug_console) {
                    $docReadyJs .= "console.debug('LE-Mod help rows:', " . json_encode($help['result']) . ");";
                    $docReadyJs .= "console.debug('LE-Mod help sql:', " . json_encode($help['sql']) . ");";
                    $docReadyJs .= "console.debug('LE-Mod help subcontext:', " . json_encode($help['subcontext']) . ");";
                }

                $help_url = $help['help_url']; //gettype(value: $help) == 'string' ? $help : $help->first()->url;
                
                // link to Webtrees Manual in GenWiki or external link?
                $wiki_url = $this->getPref(self::PREF_GENWIKI_LINK, self::STDLINK_GENWIKI);
                //$iswthb = str_starts_with($help_url, $wiki_url);
                
                $options = [
                    'I18N' => [
                        'help_title_wthb'   => I18N::translate('Webtrees manual'),
                        'help_title_ext'    => /*I18N: webtrees.pot */ I18N::translate('Help'),
                        'help_tooltip' => /*I18N: wthb link user setting epilogue 2 */ I18N::translate('The link to the setting dialog is displayed when you hover the mouse cursor over the help link for a few seconds.'),
                        'cfg_tooltip' => /*I18N: wthb link user setting title */ I18N::translate('Webtrees manual link - user setting'),
                    ],
                    'help_url'     => e($help_url),
                    'faicon'       => boolval($this->getPref(self::PREF_WTHB_FAICON)),
                    'wiki_url'     => $wiki_url,
                    'dotranslate'  => intval($this->getPref(self::PREF_WTHB_TRANSLATE)), // 0=off, 1=user defined, 2=on
                    'subcontext'   => json_encode($help['subcontext']),
                ];
                $includeRes .= Utils::getIifeJavascript(
                    file_get_contents($jsfile),
                    "options",
                    json_encode($options),
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
                    return $includeRes . Utils::getJavascriptWrapper($docReadyJs, $initJs);
                }
                return ''; # other stuff is of no use in admin backend
        }


        $tree = Validator::attributes($request)->treeOptional();

        // === include on all pages
        // --- I18N for JS MDE and enhanced links
        if ($cfg_link_active || $cfg_mde_active) {
            $includeRes .= "<script>window.I18N = " . Utils::getJsonI18N() . "; </script>";
        }
        // --- Home Link
        if ($cfg_home_active && $tree != null) {
            $params = [ 'tree' => $tree->name()];
            $url = $cfg_home_type == 1 ? route(TreePage::class, $params) : route(HomePage::class, $params);
            $docReadyJs .= '$(".wt-site-title").wrapInner(`<a class="' . self::STDCLASS_HOME_LINK .'" href="' . e($url) . '"></a>`);';

            $cfg_home_link_json = trim($this->getPref(self::PREF_HOME_LINK_JSON));
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
            $lecfg = $this->getPref(self::PREF_LINKSPP_JS);
            $docReadyJs .= "LinkEnhMod.initLE($lecfg);";
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
        
                if (in_array($routename, [
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
                ])) {
                    $bundleShortcuts[] = 'mde';
                    $docReadyJs .= 'window.LEhelp = "' . e(route('module', ['module' => $this->name(), 'action' => 'help'])) . '";';
                    
                    $linkSupport = [];
                    if (! $cfg_link_active) $linkSupport[] = "href:0";
                    if (! $cfg_img_active) $linkSupport[] = "src:0";
                    $linkCfg = implode(',', $linkSupport);
                    $linkCfg = $linkCfg ? '{' . $linkCfg . '}' : '';
                    $docReadyJs .= "LinkEnhMod.installMDE($linkCfg);";
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

        return $includeRes . Utils::getJavascriptWrapper($docReadyJs, $initJs);
    }

    /**
     * Raw content, to be added at the end of the <body> element.
     * Typically, this will be <script> elements.
     *
     * @return string
     */
    public function bodyContent(): string {
        $cfg_mde_active = boolval($this->getPref(self::PREF_MDE_ACTIVE));
        $cfg_wthb_active = boolval($this->getPref(self::PREF_WTHB_ACTIVE));

        $html = '';
        
        if ($cfg_mde_active) {
            $request = Registry::container()->get(ServerRequestInterface::class);
            $route = Validator::attributes($request)->route();
            
            if (strstr($route->name, 'EditNotePage')) {
                //fix - should be included in resources/views/edit/shared-note.phtml
                $html .= view('modals/ajax');
            }
            
        }
        if ($cfg_wthb_active) {
            $html .= view($this->name() . '::wthb-modal');
        }
        
        return $html;
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
     * import in webtrees registered routes into help table
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    public function getAdminImportRoutesAction(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $result = $this->wthb->importRoutesAction($request);
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

            $preferences = array_keys(self::DEFAULT_PREFERENCES);
            foreach ($preferences as $preference) {
                $this->setPref($preference, trim(Validator::parsedBody($request)->string($preference)));
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
        try {
            $result = $this->wthb->importCsv(self::HELP_CSV);
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
    public function setPref(string $setting_name, string $setting_value): void
    {
        //allow user to blank a setting, also if we have a DEFAULT_PREFERENCE
        $setting_value = ((self::DEFAULT_PREFERENCES[$setting_name] ?? false) && !$setting_value ? ' ' : $setting_value);
        $this->setPreference($setting_name, $setting_value);
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
    public function getHelpAction(ServerRequestInterface $request): ResponseInterface {
        // resources/views/edit/shared-note.phtml doesn't include < ?= view('modals/ajax') ? >
        // see also app/Http/RequestHandlers/HelpText.php
        //$topic = $request->getAttribute('topic');

        $title = /*I18N: webtrees.pot */ I18N::translate('Help') . ' - Markdown';
        $text  = view($this->name() . '::help-md', [
            'link_active'       => boolval($this->getPref(self::PREF_LINKSPP_ACTIVE)),
            'mdimg_active'      => boolval($this->getPref(self::PREF_MD_IMG_ACTIVE)),
            'mdsyntax'          => Utils::getMarkdownHelpExamples(Validator::attributes($request)->string('base_url')),
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