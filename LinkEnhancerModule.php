<?php

/**
 * 
 */

declare(strict_types=1);

namespace Schwendinger\Webtrees\Module\LinkEnhancer;

use Fisharebest\Webtrees\Http\Middleware\Router;
use Schwendinger\Webtrees\Module\LinkEnhancer\CustomMarkdownFactory;
use Exception;
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


/**
 * Class LinkEnhancerModule
 *
 * support short-links in html block and markdown text
 * - cross references (wt=i@I100@othertree+dia)
 * - self defined keys (osm, wpde...) for calling external websites with id
 * 
 * home link for tree title in site head
 * 
 * Integration of https://github.com/jefago/tiny-markdown-editor
 * for visual editing of markdown fields (notes)
 *
 * 
 */
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
    public const CUSTOM_VERSION = '0.0.3';
    public const CUSTOM_LAST = 'https://raw.githubusercontent.com/' . self::GITHUB_USER . '/' .
        self::CUSTOM_MODULE . '/main/latest-version.txt';


    public const PREF_HOME_LINK_TYPE = 'HOME_LINK_TYPE'; // home link type: 0=off, 1=tree, 2=my-page
    public const PREF_WTHB_ACTIVE = 'WTHB_LINK_ACTIVE'; // link to GenWiki "Webtrees Handbuch"
    public const PREF_WTHB_STD_LINK = 'WTHB_STD_LINK'; // standard link to GenWiki "Webtrees Handbuch"
    public const PREF_MDE_ACTIVE = 'MDE_ACTIVE'; // enable markdown editor for note textareas
    public const PREF_LINKSPP_ACTIVE = 'LINKSPP_ACTIVE'; // enable links++
    public const PREF_LINKSPP_JS = 'LINKSPP_JS'; // Javascript

    public const PREF_MD_IMG_ACTIVE = 'MD_IMG_ACTIVE'; // enable enhanced markdown img syntax
    public const PREF_MD_IMG_STDCLASS = 'MD_IMG_STDCLASS'; // standard classname(s) for div wrapping img- and link-tag    
    public const PREF_MD_IMG_TITLE_STDCLASS = 'MD_IMG_TITLE_STDCLASS'; // standard classname(s) for picture subtitle
    public const PREF_GENWIKI_LINK = 'GENWIKI_LINK'; // base link to GenWiki
    public const STDCLASS_MD_IMG = 'md-img';
    public const STDCLASS_MD_IMG_TITLE = 'md-img-title';
    public const STDLINK_GENWIKI = 'https://wiki.genealogy.net/';
    public const STDLINK_WTHB = 'https://wiki.genealogy.net/Webtrees_Handbuch';
    
    protected const DEFAULT_PREFERENCES = [
        self::PREF_HOME_LINK_TYPE        => '1', //int triple-state, 0=off, 1=tree, 2=my-page
        self::PREF_WTHB_ACTIVE           => '1', //bool
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
        return /*I18N: Module description */I18N::translate('Cross-references to Gedcom datasets, Markdown editor, context-sensitive link to the GenWiki Webtrees manual');
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
        // Register a namespace for our views.
        View::registerNamespace($this->name(), $this->resourcesFolder() . 'views/');

        if (boolval($this->getPref(self::PREF_MD_IMG_ACTIVE))) {
            Registry::markdownFactory(new CustomMarkdownFactory($this));
        }
    }

    
    public function getActiveRoute() : array {
        $request = Registry::container()->get(ServerRequestInterface::class);
/*        $routerContainer = Registry::container()->get(Router::class);
        $matcher = $routerContainer->getMatcher();

        // .. and try to match the request to a route.
        $route = $matcher->match($request);
*/
        $route = $request->getAttribute('route');
        if ($route) {
            $extras = is_array($route->extras) && isset($route->extras['middleware']) ? implode('|', $route->extras['middleware']) : '';
            return [
                'path'    => $route->path,
                'handler' => $route->name, //name entspricht als String handler; gettype($route->handler) == 'object' ? get_class($route->handler) : $route->handler,
                'method'  => implode('|', $route->allows),
                'extras'  => $extras
            ];

        }
        return [];
    }

    public function exportRoutes() : void
    {
        $router = Registry::routeFactory()->routeMap();
        $existingRoutes = $router->getRoutes();
        $csv = fopen(__DIR__ . "/wt-routes.csv", "w");
        
        fputcsv($csv, ['Path', 'Handler', 'Method', 'Middleware']);
        foreach ($existingRoutes as $route) {
            //$extras = isset($route->extras) ? json_encode($route->extras) : '';
            $extras = is_array($route->extras) && isset($route->extras['middleware']) ? implode('|', $route->extras['middleware']) :'';
            fputcsv($csv, [
                $route->path,
                $route->name, //name entspricht als String handler; gettype($route->handler) == 'object' ? get_class($route->handler) : $route->handler,
                implode('|', $route->allows),
                $extras
            ]);
        }
        fclose($csv);
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
        $setting_value = (self::DEFAULT_PREFERENCES[$setting_name] && !$setting_value ? ' ': $setting_value);
        $this->setPreference($setting_name, $setting_value);
    }

    /**
     * Wrapper for javascript for init
     * 
     * @param string $initJs
     *
     * @return string
     */
    public function getInitJavascript(string $initJs): string
    {
        return "<script>document.addEventListener('DOMContentLoaded', function(event) { " . $initJs . "});</script>";
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

        $activeRouteInfo = $this->getActiveRoute();
        $initJs .= "console.log('LE-Mod active route:', " . json_encode($activeRouteInfo) . ");"; //TODO debug output route - optional per setting?!

        // --- Webtrees Handbuch Link
        if ($cfg_wthb_active) {
            $initJs .= "jQuery('ul.wt-user-menu, ul.nav.small').prepend('<li class=\"nav-item menu-wthb\"><a class=\"nav-link\" href=\"https://wiki.genealogy.net/Webtrees_Handbuch\"><i class=\"fa-solid fa-circle-question\"></i> Webtrees-Handbuch</a></li>');";
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
                    return $this->getInitJavascript($initJs);
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
            $initJs .= '$(".wt-site-title").wrapInner(`<a href="' . e($url) . '"></a>`);';
        }
        
        // --- Link++
        if ($cfg_link_active) {
            $bundleShortcuts[] = 'le';
            $lecfg = $this->getPref(self::PREF_LINKSPP_JS);
            $initJs .= "LinkEnhMod.initLE($lecfg);";
        }

        // === include selectively
        // --- TinyMDE -- only nessary on edit pages
        if ($cfg_mde_active && $tree != null && $tree->getPreference('FORMAT_TEXT') == 'markdown') {
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
        
        
        if ($bundleShortcuts) {
            asort($bundleShortcuts);
            $infix = implode("-",$bundleShortcuts);
            $includeRes .= '<link rel="stylesheet" type="text/css" href="' . $this->assetUrl("css/bundle-{$infix}.min.css") . '">';
            $includeRes .= '<script src="' . $this->assetUrl("js/bundle-{$infix}.min.js") . '"></script>';
        }

        return $includeRes . ($initJs ? $this->getInitJavascript($initJs) :'');
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
            'dbfam'            => /*I18N: JS enhanced link */ I18N::translate('Family Book of Dornbirn'),
            'ewp'              => /*I18N: JS enhanced link */ I18N::translate('Residents database - Family research in West Prussia'),
            'Interactive tree' => /*I18N: webtrees.pot */ I18N::translate('Interactive tree'),
            'syntax error'     => /*I18N: JS enhanced link */ I18N::translate('Syntax error'),
            'wt-help1'         => /*I18N: JS enhanced link wt1 - %s=rectypes*/ I18N::translate('standard link to note (available record types: %s) with XREF in active tree', '%s'),
            'wt-help2'         => /*I18N: JS enhanced link wt2 */ I18N::translate('link to record type individual with XREF from "othertree" and also link to'),
            'osm-help1'        => /*I18N: JS enhanced link osm1 */ I18N::translate('zoom/lat/lon for locating map'),
            'osm-help2'        => /*I18N: JS enhanced link osm2 */ I18N::translate('same as before with additional marker'),
            'osm-help3'        => /*I18N: JS enhanced link osm3 */ I18N::translate('show also node/way/relation, see also'),
            'osm-help4'        => /*I18N: JS enhanced link osm4 */ I18N::translate('show also node/way/relation - alternative notation'),
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
/* TODO for routehelpmapping table - taken from modules_v4/vesta_common/VestaModuleTrait.php
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
            /** @var MigrationInterface $migration * /
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
*/
/*

- i.d.R. ist für einen Handler nur eine Route vorhanden
- Sonderfall stellt ModuleAction als Standard-Proxy für die CustomModules dar, der die Zugriffe mind. aufs Backend regelt:
  eigentlichen Handler über Attribut {module} ( => könnte man als generische Regel eintragen, Wert dann als handler)
  im Handler der aktuellen Route steht "module-no-tree"
  bei Vesta LAF Badges: "module-tree", da es einen tree-Attribut hat
- vermutlich


SQL-Abfrage:
1. spezifischer Match
   a) für handler und method (ev. auch path?!)
   b) bei path ^=/module und handler ^=module müssten die Attribute module und ggf. action mit Berücksichtigung finden
2. Fallback: extras=Auth* und die anderen Felder sind null
3. wenn überhaupt nichts passt einfach auf die Hauptseite verweisen

*/
}