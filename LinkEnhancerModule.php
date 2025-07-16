<?php

/**
 * 
 */

declare(strict_types=1);

namespace Schwendinger\Webtrees;
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
use Fisharebest\Webtrees\Http\RequestHandlers\TreePage;
use Fisharebest\Webtrees\Http\RequestHandlers\HomePage;
use Fisharebest\Webtrees\Validator;
use Psr\Http\Message\ServerRequestInterface;


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
class LinkEnhancerModule extends AbstractModule implements ModuleCustomInterface, ModuleGlobalInterface {


    // For every module interface that is implemented, the corresponding trait *should* also use be used.
    use ModuleCustomTrait;
    use ModuleGlobalTrait;

    /**
     * list of const for module administration
     */
    public const CUSTOM_TITLE = 'Link Enhancement';
    public const CUSTOM_MODULE = 'linkenhancer';
    public const CUSTOM_AUTHOR = 'Bernd Schwendinger';
    public const GITHUB_USER = 'bschwede';
    public const CUSTOM_WEBSITE = 'https://github.com/' . self::GITHUB_USER . '/' . self::CUSTOM_MODULE . '/';
    public const CUSTOM_VERSION = '0.1';
    public const CUSTOM_LAST = 'https://raw.githubusercontent.com/' . self::GITHUB_USER . '/' .
        self::CUSTOM_MODULE . '/main/latest-version.txt';


    public const PREF_HOME_TYPE = 'HOME_TYPE';
    public const PREF_WTHB_ACTIVE = 'WTHB_ACTIVE';
    public const PREF_MDE_ACTIVE = 'MDE_ACTIVE';
    public const PREF_LINK_ACTIVE = 'LINK_ACTIVE';

  
    /**
     * How should this module be identified in the control panel, etc.?
     *
     * @return string
     */
    public function title(): string
    {
        return I18N::translate(self::CUSTOM_TITLE);
    }

    /**
     * A sentence describing what this module does.
     *
     * @return string
     */
    public function description(): string
    {
        return I18N::translate('This module does not do anything');
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
        return [];
    }


    /**
     * Called for all *enabled* modules.
     */
    public function boot(): void
    {
        // Register a namespace for our views.
        View::registerNamespace(__DIR__, $this->resourcesFolder() . 'views/');

        Registry::markdownFactory(new CustomMarkdownFactory());
    }

    
    public function exportRoutes() : void
    {
        $router = Registry::routeFactory()->routeMap();
        $existingRoutes = $router->getRoutes();
        $csv = fopen(__DIR__ . "/wt-routes.csv", "w");
        //fputcsv($csv, [ 'Name', 'Path', 'Handler', 'Method', 'Extras']);
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
     * Raw content, to be added at the end of the <head> element.
     * Typically, this will be <link> and <meta> elements.
     *
     * @return string
     */
    public function headContent(): string
    {
        //self::exportRoutes();

        $cfg_home_type = intval($this->getPreference(self::PREF_HOME_TYPE, '1')); // 0=off, 1=Home, 2=My-Page
        $cfg_home_active = boolval($cfg_home_type);
        $cfg_wthb_active = boolval($this->getPreference(self::PREF_WTHB_ACTIVE, '1'));
        $cfg_mde_active = boolval($this->getPreference(self::PREF_MDE_ACTIVE, '1'));
        $cfg_link_active = boolval($this->getPreference(self::PREF_LINK_ACTIVE, '1'));

        if (!$cfg_home_active && !$cfg_wthb_active && ! $cfg_mde_active && !$cfg_link_active) {
            return '';
        }

        $request = Registry::container()->get(ServerRequestInterface::class);
        $includeRes = '';
        $initJs = '';

        // Home Link
        if ($cfg_home_active) {
            $tree = Validator::attributes($request)->treeOptional();
            if ($tree != null) {
                $params = [ 'tree' => $tree->name()];
                $url = $cfg_home_type == 1 ? route(TreePage::class, $params) : route(HomePage::class, $params);
                $initJs .= '$(".wt-site-title").wrapInner(`<a href="' . e($url) . '"></a>`);';
            }
        }
        
        // -- Link++
        if ($cfg_link_active) {
            $includeRes .= '<link rel="stylesheet" type="text/css" href="' . $this->assetUrl('css/linkenhancer.css') . '">';
            $includeRes .= '<script src="' . $this->assetUrl('js/linkenhancer.js') . '"></script>';
            $initJs .= 'observeDomLinks();';
        }

        // --- Webtrees Handbuch Link
        if ($cfg_wthb_active) {
            $initJs .= <<< "EOD"
jQuery('.wt-user-menu').prepend('<li class="nav-item menu-wthb"><a class="nav-link" href="https://wiki.genealogy.net/Webtrees_Handbuch"><i class="fa-solid fa-circle-question"></i> Webtrees-Handbuch</a></li>');
EOD;
        }

        
        // --- TinyMDE
        if ($cfg_mde_active) {
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
                    //return "<script>console.log('LEM injecting CSS an JS');</script>";
                }
            }
        }
        
        return $includeRes . "<script>/* Module */ document.addEventListener('DOMContentLoaded', function(event) { " . $initJs . "});</script>";
    }
 
}