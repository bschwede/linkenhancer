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
    public const CUSTOM_VERSION = '0.1';
    public const CUSTOM_LAST = 'https://raw.githubusercontent.com/' . self::GITHUB_USER . '/' .
        self::CUSTOM_MODULE . '/main/latest-version.txt';


    public const PREF_HOME_LINK_TYPE = 'HOME_LINK_TYPE'; // home link type: 0=off, 1=tree, 2=my-page
    public const PREF_WTHB_ACTIVE = 'WTHB_LINK_ACTIVE'; // link to GenWiki "Webtrees Handbuch"
    public const PREF_MDE_ACTIVE = 'MDE_ACTIVE'; // enable markdown editor for note textareas
    public const PREF_LINKSPP_ACTIVE = 'LINKSPP_ACTIVE'; // enable links++
    public const PREF_LINKSPP_JS = 'LINKSPP_JS'; // Javascript

    public const PREF_MD_IMG_ACTIVE = 'MD_IMG_ACTIVE'; // enable enhanced markdown img syntax
    public const PREF_MD_IMG_STDCLASS = 'MD_IMG_STDCLASS'; // standard classname(s) for div wrapping img- and link-tag    
    public const PREF_MD_IMG_TITLE_STDCLASS = 'MD_IMG_TITLE_STDCLASS'; // standard classname(s) for picture subtitle
    public const STDCLASS_MD_IMG = 'md-img';
    public const STDCLASS_MD_IMG_TITLE = 'md-img-title';
    
    protected const DEFAULT_PREFERENCES = [
        self::PREF_HOME_LINK_TYPE        => '1', //int triple-state, 0=off, 1=tree, 2=my-page
        self::PREF_WTHB_ACTIVE           => '1', //bool
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
        return /*I18N: Module title */I18N::translate("LinkEnhancer");
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

    
    public function getActiveRoute() : string {
        $request = Registry::container()->get(ServerRequestInterface::class);
/*        $routerContainer = Registry::container()->get(Router::class);
        $matcher = $routerContainer->getMatcher();

        // .. and try to match the request to a route.
        $route = $matcher->match($request);
*/
        $route = $request->getAttribute('route');
        if ($route) {
            $extras = is_array($route->extras) && isset($route->extras['middleware']) ? implode('|', $route->extras['middleware']) : '';
            return json_encode( [
                $route->path,
                $route->name, //name entspricht als String handler; gettype($route->handler) == 'object' ? get_class($route->handler) : $route->handler,
                implode('|', $route->allows),
                $extras
            ]);

        }
        return '';
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
        return (isset($result) && $result != '' ? $result : self::DEFAULT_PREFERENCES[$setting_name] ?? '');
    }

    /**
     * Raw content, to be added at the end of the <head> element.
     * Typically, this will be <link> and <meta> elements.
     *
     * @return string
     */
    public function headContent(): string
    {
        //self::exportRoutes();

        $cfg_home_type = intval($this->getPref(self::PREF_HOME_LINK_TYPE)); // 0=off, 1=Home, 2=My-Page
        $cfg_home_active = boolval($cfg_home_type);
        $cfg_wthb_active = boolval($this->getPref(self::PREF_WTHB_ACTIVE));
        $cfg_mde_active = boolval($this->getPref(self::PREF_MDE_ACTIVE));
        $cfg_link_active = boolval($this->getPref(self::PREF_LINKSPP_ACTIVE));

        if (!$cfg_home_active && !$cfg_wthb_active && ! $cfg_mde_active && !$cfg_link_active) {
            return '';
        }

        $request = Registry::container()->get(ServerRequestInterface::class);
        $includeRes = '';
        $initJs = '';
        $tree = Validator::attributes($request)->treeOptional();

        $initJs .= 'window.LEhelp = "' . e(route('module', [ 'module' => $this->name(), 'action' => 'help' ])) . '";';

        $activeRouteInfo = $this->getActiveRoute();
        $initJs .= "console.log('Active route:', $activeRouteInfo);";

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
            $includeRes .= '<link rel="stylesheet" type="text/css" href="' . $this->assetUrl('css/linkenhancer.css') . '">';
            $includeRes .= '<script src="' . $this->assetUrl('js/linkenhancer.js') . '"></script>';
            $lecfg = $this->getPref(self::PREF_LINKSPP_JS);
            $initJs .= "initLE($lecfg);";
        }

        // --- Webtrees Handbuch Link
        if ($cfg_wthb_active) {
            $initJs .= <<< "EOD"
jQuery('.wt-user-menu').prepend('<li class="nav-item menu-wthb"><a class="nav-link" href="https://wiki.genealogy.net/Webtrees_Handbuch"><i class="fa-solid fa-circle-question"></i> Webtrees-Handbuch</a></li>');
EOD;
        }
        
        // --- TinyMDE
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
                    $includeRes .= '<link rel="stylesheet" type="text/css" href="' . $this->assetUrl('css/tiny-mde.min.css') . '">';
                    $includeRes .= '<link rel="stylesheet" type="text/css" href="' . $this->assetUrl('css/tiny-mde-wt.css') . '">';
                    $includeRes .= '<script src="' . $this->assetUrl('js/tiny-mde.min.js') . '"></script>';
                    $includeRes .= '<script src="' . $this->assetUrl('js/tiny-mde-wt.js') . '"></script>';
                    $initJs .= 'installMDE();';
                }
            }
        }
        
        return $includeRes . ($initJs ? "<script>document.addEventListener('DOMContentLoaded', function(event) { " . $initJs . "});</script>" :'');
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
                //fix - should in be included in resources/views/edit/shared-note.phtml
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

        $jsfile = $this->resourcesFolder() . 'js' . DIRECTORY_SEPARATOR . 'linkenhancer.js';
        $jscode = '';
        if (file_exists($jsfile)) {
            $text = file($jsfile);
            if (gettype($text) == 'array') {
                $inSnippet = false;
                foreach ($text as $line) {
                    if ($inSnippet) {
                        if (boolval(preg_match('/\/\/ *-{3,} *code-snippet/i', $line, $match))) break;
                        $jscode .= $line;
                    } else {
                        $inSnippet = boolval(preg_match('/\/\/ *\+{3,} *code-snippet/i', $line, $match));
                    }
                }
            }
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
                $this->setPreference($preference, trim(Validator::parsedBody($request)->string($preference)));
            }

            FlashMessages::addMessage(/*I18N: webtrees.pot */I18N::translate(
                'The preferences for the module “%s” have been updated.',
                $this->title()
            ), 'success');
        }
        return redirect($this->getConfigLink());
    }


    /**
     * Save the user preferences in the database
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
            // enhanced links 
            'cross reference'  => /*I18N: JS enhanced link */ I18N::translate('cross reference'),
            'oofb'             => /*I18N: JS enhanced link, %s name of location */ I18N::translate('Online Local heritage book of %s at CompGen', '%s'),
            'gov'              => /*I18N: JS enhanced link */ I18N::translate('The Historic Geo Information System (GOV)'),
            'dbfam'            => /*I18N: JS enhanced link */ I18N::translate('Family Book of Dornbirn'),
            'ewp'              => /*I18N: JS enhanced link */ I18N::translate('Residents database - Family research in West Prussia'),
            'Interactive tree' => /*I18N: webtrees.pot */ I18N::translate('Interactive tree'),
            'syntax error'     => /*I18N: JS enhanced link */ I18N::translate('Syntax error'),
        ]);
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

        $mdsyntax = [
            [ 
                'md'   => '*' . I18N::translate('italic') .'*',
                'html' => '<em>' . I18N::translate('italic') . '</em>'
            ],
            [
                'md' => '**' . I18N::translate('bold') .'**',
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
                'md' => '[' . I18N::translate('Insert link') . '](#anchor)',
                'html' => '<a href="#anchor">' . I18N::translate('Insert link') . '</a>'
            ],
            [
                'md' => I18N::translate('Horizontal rule') . "\n\n---",
                'html' => I18N::translate('Horizontal rule') . '<hr />'
            ],

        ];

        $title = /*I18N: webtrees.pot */ I18N::translate('Help') . ' - Markdown';
        $text  = view($this->name() . '::help-md', [
            'link_active'  => boolval($this->getPref(self::PREF_LINKSPP_ACTIVE)),
            'mdimg_active' => boolval($this->getPref(self::PREF_MD_IMG_ACTIVE)),
            'mdsyntax'     => $mdsyntax,
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
}