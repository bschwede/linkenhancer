<?php

declare(strict_types=1);

namespace Schwendinger\Webtrees\Module\LinkEnhancer\Services;

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\FlashMessages;
use Fisharebest\Webtrees\Http\RequestHandlers\HomePage;
use Fisharebest\Webtrees\Http\RequestHandlers\TreePage;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Session;
use Fisharebest\Webtrees\Validator;
use Psr\Http\Message\ServerRequestInterface;
use Schwendinger\Webtrees\Helpers\Functions;
use Schwendinger\Webtrees\Helpers\MoreI18N;
use Schwendinger\Webtrees\Module\LinkEnhancer\Http\RequestHandlers\HelpMdAction;
use Schwendinger\Webtrees\Module\LinkEnhancer\Http\RequestHandlers\HelpWtCoreAction;
use Schwendinger\Webtrees\Module\LinkEnhancer\Http\RequestHandlers\HelpWthbAction;
use Schwendinger\Webtrees\Module\LinkEnhancer\Http\RequestHandlers\XrefDetailData;
use Schwendinger\Webtrees\Module\LinkEnhancer\LinkEnhancerModule;
use Schwendinger\Webtrees\Module\LinkEnhancer\LinkEnhancerUtils as Utils;
use Fisharebest\Webtrees\Module\ModuleTabInterface;

use function boolval;
use function e;
use function in_array;
use function is_array;
use function json_decode;
use function json_encode;
use function route;
use function str_contains;
use function str_replace;
use function strtoupper;
use function view;

/**
 * Builds the head content (CSS/JS) and body content (modals) for all pages.
 * Extracted from LinkEnhancerModule — encapsulates the request-scoped state
 * (bundleShortcuts, docReadyJs, initJs, needAjax) that was previously on the module.
 */
final class ContentBuilder
{
    private array $bundleShortcuts = [];
    private string $docReadyJs = '';
    private string $initJs = '';
    private bool $needAjax = false;

    public function __construct(private readonly LinkEnhancerModule $module) {}

    public function buildHead(): string
    {
        $module = $this->module;

        $cfg_home_type   = $module->getPref(LinkEnhancerModule::PREF_HOME_LINK_TYPE, true);
        $cfg_home_active = boolval($cfg_home_type);
        $cfg_wthb_active = $module->getPref(LinkEnhancerModule::PREF_WTHB_ACTIVE, true);
        $cfg_link_active = $module->getPref(LinkEnhancerModule::PREF_LINKSPP_ACTIVE, true);
        $cfg_md_active   = $module->getPref(LinkEnhancerModule::PREF_MD_ACTIVE, true);

        if (!$cfg_home_active && !$cfg_wthb_active && ! $cfg_md_active && !$cfg_link_active) {
            return '';
        }

        $cfg_md_editor_active = $module->getPref(LinkEnhancerModule::PREF_MDE_ACTIVE, true, true);
        $cfg_md_img_active    = $module->getPref(LinkEnhancerModule::PREF_MD_IMG_ACTIVE, true, true);
        $cfg_md_ext_active    = $module->getPref(LinkEnhancerModule::PREF_MD_EXT_ACTIVE, true, true);
        $cfg_js_debug_console = $module->getPref(LinkEnhancerModule::PREF_JS_DEBUG_CONSOLE, true);

        $request = Registry::container()->get(ServerRequestInterface::class);
        $this->bundleShortcuts = [];
        $includeRes = '';
        $this->docReadyJs = '';
        $this->initJs = '';

        $activeRouteInfo = Utils::getActiveRoute($request);
        if ($cfg_js_debug_console) {
            $this->docReadyJs .= "console.debug('LE-Mod active route:', " . json_encode($activeRouteInfo) .");";
        }

        // --- Webtrees Handbuch Link
        if ($cfg_wthb_active) {
            $this->bundleShortcuts[] = 'wthb';

            $withSubcontext = $module->getPref(LinkEnhancerModule::PREF_WTHB_SUBCONTEXT, true);
            $help = $module->wthb()->getContextHelp($activeRouteInfo, $withSubcontext, $cfg_js_debug_console);
            if ($cfg_js_debug_console) {
                if (is_array($help)) {
                    $this->docReadyJs .= "console.debug('LE-Mod help rows:', " . json_encode($help['result']) . ");";
                    $this->docReadyJs .= "console.debug('LE-Mod help sql:', " . json_encode($help['sql']) . ");";
                    if ($withSubcontext) $this->docReadyJs .= "console.debug('LE-Mod help subcontext:', " . json_encode($help['subcontext']) . ");";
                } else {
                    $this->docReadyJs .= "console.debug('LE-Mod help:', " . json_encode($help) . ");";
                }
            }

            $help_url = $help['help_url'] ?? $help;
            $linksJsonString = match($module->getPref(LinkEnhancerModule::PREF_WTHB_LINKS_TYPE, true)) {
                1 => $module->getPref(LinkEnhancerModule::PREF_WTHB_LINKS_JSON, true),
                2 => LinkEnhancerModule::STD_WTHB_LINKS_JSON,
                default => ''
            };

            $options = [
                'I18N'            => Utils::getJsI18N('wthb', $module),
                'help_url'        => $help_url,
                'faicon'          => $module->getPref(LinkEnhancerModule::PREF_WTHB_FAICON, true),
                'wiki_url'        => $module->getPref(LinkEnhancerModule::PREF_GENWIKI_LINK),
                'wthb_url'        => $module->getPref(LinkEnhancerModule::PREF_WTHB_STD_LINK),
                'dotranslate'     => $module->getPref(LinkEnhancerModule::PREF_WTHB_TRANSLATE, true),
                'subcontext'      => $withSubcontext && is_array($help) ? $help['subcontext'] : [],
                'tocnsearch_url'  => ($module->getPref(LinkEnhancerModule::PREF_WTHB_TOCNSEARCH, true) ? route(HelpWthbAction::class, ['language' => I18N::languageTag()]) : ''),
                'openInNewTab'    => $module->getPref(LinkEnhancerModule::PREF_WTHB_OPEN_IN_NEW_TAB, true, true),
                'splitNavlink'    => $module->getPref(LinkEnhancerModule::PREF_WTHB_SPLIT_TOPMENU, true),
                'wtcorehelp_url'  => ($module->getPref(LinkEnhancerModule::PREF_WTHB_WTCOREHELP, true) ? route(HelpWtCoreAction::class, ['language' => I18N::languageTag()]) : ''),
                'linksJson'       => Utils::getWthbLinksJsonStringTranslated($linksJsonString),
                'admin_url'       => (Auth::isAdmin() ? route('module', ['module' => $module->name(), 'action' => 'Admin']) : ''),
            ];

            $this->initJs .= "LinkEnhMod.initWthb(" . json_encode($options) . ");";
        }

        // === admin backend short-circuit
        if (Utils::isAdminPage($request)) {
            if ($cfg_wthb_active) {
                $includeRes .= Utils::getIncludeWebressourceString($module, $this->bundleShortcuts, \Schwendinger\Webtrees\Module\LinkEnhancer\WebRessource::CssAndJs);
                $includeRes .= Utils::getJavascriptWrapper($this->docReadyJs, $this->initJs);
                return $includeRes;
            }
            return '';
        }

        $tree = Validator::attributes($request)->treeOptional();

        // --- Home Link
        $isHomeLinkActive = $cfg_home_active && $tree != null;
        if ($isHomeLinkActive) {
            $params = ['tree' => $tree->name()];
            $url = "#";
            $target = '';
            switch ($cfg_home_type) {
                case 1:
                    $url = route(TreePage::class, $params);
                    break;
                case 2:
                    $url = route(HomePage::class, $params);
                    break;
                case 3:
                    $url = $module->getPref(LinkEnhancerModule::PREF_HOME_LINK_URL);
                    $url = $url !== '' ? $url : '#';
                    $target = $module->getPref(LinkEnhancerModule::PREF_HOME_LINK_OPEN_IN_NEW_TAB, true, true) ? ' target="_blank"' : '';
                    break;
            }
            $this->docReadyJs .= 'document.querySelectorAll(".wt-site-title").forEach(el => el.innerHTML = `<a class="' . LinkEnhancerModule::STDCLASS_HOME_LINK . '" href="' . e($url) . '"' . $target . '>` + el.innerHTML + "</a>");';
        }

        // --- UID search in quick search field
        if ($module->getPref(LinkEnhancerModule::PREF_UID_ACTIVE, true) && $tree !== null) {
            $uid_route = route('le.goto-uid.tree', ['tree' => $tree->name(), 'uid' => '__UID__']);
            $this->docReadyJs .= '
(function(){
    var f = document.querySelector("form.wt-header-search-form");
    if (!f) return;
    f.addEventListener("submit", function(e) {
        var q = (f.querySelector("input[name=query]") || {}).value || "";
        q = q.trim();
        if (!q) return;
        var uid = null;
        if (q.toLowerCase().startsWith("uid:")) {
            uid = q.slice(4).trim();
        } else if (/^(?:[0-9a-f]{8}-?[0-9a-f]{4}-?[0-9a-f]{4}-?[0-9a-f]{4}-?[0-9a-f]{12}|[0-9a-f]{36,38})$/i.test(q)) {
            uid = q;
        }
        if (uid) {
            e.preventDefault();
            window.location.href = "' . e($uid_route) . '".replace("__UID__", encodeURIComponent(uid));
        }
    });
})();';
        }

        // --- Link++
        if ($cfg_link_active) {
            $this->bundleShortcuts[] = 'le';

            $lecfg = $module->getPref(LinkEnhancerModule::PREF_LINKSPP_JS);
            $lecfg = $lecfg != '' ? $lecfg : '{}';
            $treename = ($tree !== null ? $tree->name() : '');

            $options = [
                'I18N'         => Utils::getJsI18N('le', $module),
                'thisXref'     => Validator::attributes($request)->isXref()->string('xref', ''),
                'openInNewTab' => $module->getPref(LinkEnhancerModule::PREF_LINKSPP_OPEN_IN_NEW_TAB, true),
                'uidActive'    => $module->getPref(LinkEnhancerModule::PREF_UID_ACTIVE, true),
                'tree'         => $treename,
                'baseurl'      => route(TreePage::class, ['tree' => $treename]),
                'urlmode'      => (Validator::attributes($request)->boolean('rewrite_urls', false) ? 'pretty' : 'default'),
            ];
            $this->docReadyJs .= "LinkEnhMod.initLE($lecfg, " . json_encode($options) . ");";

            // --- Cross-reference detail tab
            $xref_attr = Validator::attributes($request)->isXref()->string('xref', '');
            $handler_key = Functions::canonicalHandlerKey($activeRouteInfo['handler'] ?? '');
            $record_type = LinkEnhancerModule::XREF_DETAIL_HANDLER_KEYS[$handler_key] ?? null;
            if ($record_type !== null && $xref_attr !== '' && $tree !== null
                && $module->accessLevel($tree, ModuleTabInterface::class) >= Auth::accessLevel($tree, Validator::attributes($request)->user())) {
                $url_params = [
                    'tree' => $tree->name(),
                    'xref' => $xref_attr,
                ];
                if ($record_type === 'FAM') {
                    $url_params['modal'] = 1;
                }

                $this->initJs .= "LinkEnhMod.initXrefDetailTab(" . json_encode([
                    'url' => route(XrefDetailData::class, $url_params),
                    'rectype'  => $record_type,
                    'tabTitle' => $module->tabTitle(),
                ]) . ");";
            }
        }

        // === markdown support
        if ($cfg_md_active && $tree != null && $tree->getPreference('FORMAT_TEXT') == 'markdown') {
            if ($cfg_md_img_active || $cfg_md_ext_active) {
                $this->bundleShortcuts[] = 'img';

                $options = [
                    'I18N'      => Utils::getJsI18N('img', $module),
                    'ext_fn'    => $module->getPref(LinkEnhancerModule::PREF_MD_EXT_FN_ACTIVE, true),
                    'ext_toc'   => $module->getPref(LinkEnhancerModule::PREF_MD_EXT_TOC_ACTIVE, true),
                    'td_h_ctrl' => $module->getPref(LinkEnhancerModule::PREF_MD_TD_H_CTRL_TYPE, true),
                    'td_h_cb'   => $module->getPref(LinkEnhancerModule::PREF_MD_TD_H_CB_VISIBLE, true),
                ];
                $this->docReadyJs .= "LinkEnhMod.initMd(" . json_encode($options) . ");";
            }

            if ($cfg_md_editor_active) {
                if ($module->mde()->isEditPage($request)) {
                    $this->bundleShortcuts[] = 'mde';

                    $options = [
                        'I18N'         => Utils::getJsI18N('mde', $module),
                        'href'         => $cfg_link_active,
                        'src'          => $cfg_md_img_active,
                        'ext'          => $cfg_md_ext_active,
                        'ext_mark'     => $module->canActivateHighlightExtension(),
                        'ext_fn'       => $module->getPref(LinkEnhancerModule::PREF_MD_EXT_FN_ACTIVE, true),
                        'ext_strike'   => $module->getPref(LinkEnhancerModule::PREF_MD_EXT_STRIKE_ACTIVE, true),
                        'query_filter' => $module->mde()->getElementFilter(),
                        'helpmd_url'   => route(HelpMdAction::class, ['language' => I18N::languageTag()]),
                    ];
                    $this->docReadyJs .= "LinkEnhMod.installMDE(" . json_encode($options) . ");";
                }
            }
        }

        $includeRes .= Utils::getIncludeWebressourceString($module, $this->bundleShortcuts, \Schwendinger\Webtrees\Module\LinkEnhancer\WebRessource::CssAndJs);
        $includeRes .= $this->themeSpecificCss($cfg_js_debug_console, $isHomeLinkActive);
        $includeRes .= Utils::getJavascriptWrapper($this->docReadyJs, $this->initJs);
        return $includeRes;
    }

    public function buildBody(): string
    {
        $module = $this->module;
        $cfg_md_active        = $module->getPref(LinkEnhancerModule::PREF_MD_ACTIVE, true);
        $cfg_md_editor_active = $cfg_md_active ? $module->getPref(LinkEnhancerModule::PREF_MDE_ACTIVE, true) : false;
        $cfg_wthb_active      = $module->getPref(LinkEnhancerModule::PREF_WTHB_ACTIVE, true);
        $cfg_wthb_tocnsearch  = $module->getPref(LinkEnhancerModule::PREF_WTHB_TOCNSEARCH, true);
        $cfg_wthb_wtcorehelp  = $module->getPref(LinkEnhancerModule::PREF_WTHB_WTCOREHELP, true);

        $html = '';
        $this->needAjax = false;

        if ($cfg_wthb_active) {
            $html .= view($module->name() . '::wthb-modal');
            $this->needAjax = $cfg_wthb_tocnsearch || $cfg_wthb_wtcorehelp;
        }

        $this->needAjax = ($this->needAjax || ($cfg_md_editor_active && $module->mde()->isEditPage()));

        return $html;
    }

    public function needsAjax(): bool
    {
        return $this->needAjax;
    }

    private function themeSpecificCss(bool $jsDebugMsg, bool $isHomeLinkActive): string
    {
        $module = $this->module;
        $theme = Session::get('theme');
        $palette = Session::get('palette', '');
        $theme_palette = $theme . ($palette ? "_{$palette}" : '');

        $includeRes = "";

        if ($jsDebugMsg) {
            $this->docReadyJs .= "console.debug('LE-Mod theme:', '$theme'" . ($palette ? ", 'palette=$palette'" : '') . ");";
        }

        // links++
        if (in_array('le', $this->bundleShortcuts) && in_array($theme, ['webtrees', 'clouds', 'colors', 'xenea'])) {
            $includeRes .= "<style>.menu-list-xrefs::before {
                content: \"🔗\";
                display: inline-block;
                vertical-align: middle !important;
                margin-right: 0.25em;
                }</style>\n";
        }

        // webtrees manual
        if (in_array('wthb', $this->bundleShortcuts)) {
            $themeStyles = [
                '_jc-theme-justlight_'    =>
                ".nav-item.dropdown.menu-wthb { line-height: 1.25; }
                .popover {
                  background-clip: padding-box;
                  background-color: hsl(0, 0%, 100%);;
                  border: 1px solid hwb(0 0% 100% / 0.18);
                  border-radius: 0.2rem;
                  text-align: start;
                  text-shadow: none;
                  z-index:1070;
                }
                .popover-body { padding: 0.5rem 0.5rem;}
                .helpsection .linkicon { background-size: 20px 20px !important; }",
                '_webtrees-primer-theme_' =>
                ".nav-item.dropdown.menu-wthb {
                  line-height: 1.75;
                  color: var(--fgColor-muted);
                }
                .nav-item.dropdown.menu-wthb svg { color: var(--fgColor-muted); }
                .helpsection .linkicon { background-size: 20px 20px !important; }",
            ];
            $stylerules = $themeStyles[$theme_palette] ?? $themeStyles[$theme] ?? '';
            $includeRes .= $stylerules ? "<style>{$stylerules}</style>\n" : '';
        }

        // home link
        if ($isHomeLinkActive) {
            $cfg_home_link_json = $module->getPref(LinkEnhancerModule::PREF_HOME_LINK_JSON);
            if ($cfg_home_link_json) {
                $json = json_decode($cfg_home_link_json, true);
                if ($json) {
                    $stylerules = $json[$theme_palette] ?? $json[$theme] ?? $json['*'] ?? null;
                    if ($stylerules) {
                        $includeRes .= "<style>{$stylerules}</style>\n";
                    } elseif ($jsDebugMsg) {
                        $this->docReadyJs .= "console.debug('LE-Mod home link: JSON contains no matching style rule for current theme');";
                    }
                } else {
                    FlashMessages::addMessage(
                        I18N::translate('Home link - JSON with CSS rules seems to be invalid.'),
                        'warning'
                    );
                }
            }
        }

        return $includeRes;
    }
}
