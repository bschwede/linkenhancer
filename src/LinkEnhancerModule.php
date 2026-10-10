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

use DomainException;
use Exception;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Enums\AccessLevel; //wt2.3
use Fisharebest\Webtrees\FlashMessages;
use Fisharebest\Webtrees\GedcomRecord;
use Fisharebest\Webtrees\Http\RequestHandlers\HomePage;
use Fisharebest\Webtrees\Http\RequestHandlers\TreePage;
use Fisharebest\Webtrees\I18N;
use Schwendinger\Webtrees\Helpers\Functions;
use Schwendinger\Webtrees\Helpers\MoreI18N;
use Fisharebest\Webtrees\Module\AbstractModule;
use Fisharebest\Webtrees\Module\ModuleConfigInterface;
use Fisharebest\Webtrees\Module\ModuleDataFixInterface;
use Fisharebest\Webtrees\Module\ModuleConfigTrait;
use Fisharebest\Webtrees\Module\ModuleCustomInterface;
use Schwendinger\Webtrees\Traits\ModuleCustomTrait;
use Fisharebest\Webtrees\Module\ModuleGlobalInterface;
use Fisharebest\Webtrees\Module\ModuleGlobalTrait;
use Fisharebest\Webtrees\Module\ModuleListInterface;
use Fisharebest\Webtrees\Module\ModuleListTrait;
use Fisharebest\Webtrees\Module\ModuleTabInterface;
use Fisharebest\Webtrees\Module\ModuleTabTrait;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\User;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\AdminService;
use Fisharebest\Webtrees\Services\TimeoutService;
use Fisharebest\Webtrees\Services\TreeService;
use Fisharebest\Webtrees\Session;
use Fisharebest\Webtrees\Validator;
use Fisharebest\Webtrees\View;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Collection;
use Schwendinger\Webtrees\Module\LinkEnhancer\DataFix\DataFixDispatcher;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Nyholm\Psr7\Stream;
use Schwendinger\Webtrees\Module\LinkEnhancer\Factories\CustomMarkdownFactory;
use Schwendinger\Webtrees\Module\LinkEnhancer\Http\RequestHandlers\AdminXrefOverviewData;
use Schwendinger\Webtrees\Module\LinkEnhancer\Http\RequestHandlers\DataFixBlocksAction;
use Schwendinger\Webtrees\Module\LinkEnhancer\Http\RequestHandlers\DataFixRebuildIndexAction;
use Schwendinger\Webtrees\Module\LinkEnhancer\Http\RequestHandlers\GotoIdAction;
use Schwendinger\Webtrees\Module\LinkEnhancer\Http\RequestHandlers\GotoUidAction;
use Schwendinger\Webtrees\Module\LinkEnhancer\Http\RequestHandlers\GotoXrefAction;
use Schwendinger\Webtrees\Module\LinkEnhancer\Http\RequestHandlers\HelpMdAction;
use Schwendinger\Webtrees\Module\LinkEnhancer\Http\RequestHandlers\HelpWtCoreAction;
use Schwendinger\Webtrees\Module\LinkEnhancer\Http\RequestHandlers\HelpWthbAction;
use Schwendinger\Webtrees\Module\LinkEnhancer\Http\RequestHandlers\XrefDetailData;
use Schwendinger\Webtrees\Module\LinkEnhancer\Http\RequestHandlers\XrefOverviewListData;
use Schwendinger\Webtrees\Module\LinkEnhancer\Http\RequestHandlers\WthbAdminHandler;
use Schwendinger\Webtrees\Module\LinkEnhancer\Http\RequestHandlers\RenumberActionHandler;
use Schwendinger\Webtrees\Module\LinkEnhancer\LinkEnhancerUtils as Utils;
use Schwendinger\Webtrees\Module\LinkEnhancer\Services\IndexRebuildScheduler;
use Schwendinger\Webtrees\Module\LinkEnhancer\Services\AdminSettingsBuilder;
use Schwendinger\Webtrees\Module\LinkEnhancer\Services\MarkdownEditorActivationService;
use Schwendinger\Webtrees\Module\LinkEnhancer\Services\RenumberWithLinksService;
use Schwendinger\Webtrees\Module\LinkEnhancer\Services\ContentBuilder;
use Schwendinger\Webtrees\Module\LinkEnhancer\Services\UidIndexService;
use Schwendinger\Webtrees\Module\LinkEnhancer\Services\WthbService;
use Schwendinger\Webtrees\Module\LinkEnhancer\Services\XrefDetailService;
use Schwendinger\Webtrees\Module\LinkEnhancer\Services\XrefsService;
use Schwendinger\Webtrees\Module\LinkEnhancer\SettingInterface;
use Schwendinger\Webtrees\Helpers\ClassName;
use Schwendinger\Webtrees\Services\ModuleLog;

use function array_key_exists, boolval, count, strval, is_array, intval, route, trim;
use Throwable;

enum OverwriteMode
{ // pref schema cascading setting - overwrite setting value with parent if...
    case ParentIsZero;   // parent is bool - if component is active, subordinated settings can be evaluated
    case ParentIsNotOne; // parent is int triple state
}

class LinkEnhancerModule extends AbstractModule implements
    MiddlewareInterface,
    ModuleCustomInterface,
    ModuleGlobalInterface,
    ModuleConfigInterface,
    ModuleDataFixInterface,
    ModuleListInterface,
    ModuleTabInterface,
    SettingInterface
{


    // For every module interface that is implemented, the corresponding trait *should* also use be used.
    use ModuleCustomTrait;
    use ModuleGlobalTrait;
    use ModuleConfigTrait;
    use ModuleListTrait;
    use ModuleTabTrait;

    /**
     * list of const for module administration
     */
    public const CACHE_TTL_1D = 86400;
    public const LOG_ID = 'linkenhancer';
    public const CUSTOM_MODULE = 'linkenhancer';
    public const MODULE_NAME = '_linkenhancer_'; // webtrees module name (folder name, underscore-wrapped)
    public const CUSTOM_AUTHOR = 'Bernd Schwendinger';
    public const GITHUB_USER = 'bschwede';
    public const CUSTOM_WEBSITE = 'https://github.com/' . self::GITHUB_USER . '/' . self::CUSTOM_MODULE . '/';
    public const CUSTOM_VERSION = '1.5.2';
    public const CUSTOM_LAST = 'https://raw.githubusercontent.com/' . self::GITHUB_USER . '/' .
        self::CUSTOM_MODULE . '/main/latest-version.txt';


    public const PREF_HOME_LINK_TYPE = 'HOME_LINK_TYPE'; // home link type: 0=off, 1=tree, 2=my-page, 3=user defined
    public const PREF_HOME_LINK_URL = 'HOME_LINK_URL'; // user defined url
    public const PREF_HOME_LINK_OPEN_IN_NEW_TAB = 'HOME_LINK_OPEN_IN_NEW_TAB'; // only relevant for user defined url
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
    public const PREF_WTHB_OPEN_IN_NEW_TAB = 'WTHB_OPEN_IN_NEW_TAB';
    public const PREF_WTHB_SPLIT_TOPMENU = 'WTHB_SPLIT_TOPMENU';
    public const PREF_WTHB_WTCOREHELP = 'WTHB_WTCOREHELP';
    public const PREF_WTHB_LINKS_TYPE = 'WTHB_LINKS_TYPE'; // triple-state, 0=off, 1=user defined, 2=on
    public const PREF_WTHB_LINKS_JSON = 'WTHB_LINKS_JSON'; // additional links for webtrees manual top menu
    public const PREF_JS_DEBUG_CONSOLE = 'JS_DEBUG_CONSOLE'; // console.debug with active route info; 0=off, 1=on
    public const PREF_OPEN_IN_NEW_TAB = 'OPEN_IN_NEW_TAB'; // triple-state, 0=off, 1=user defined, 2=on
    public const PREF_UID_ACTIVE = 'UID_ACTIVE'; // activate UID lookup
    public const PREF_GENWIKI_LINK = 'GENWIKI_LINK'; // base link to GenWiki
    
    public const PREF_LINKSPP_ACTIVE = 'LINKSPP_ACTIVE'; // enable links++
    public const PREF_LINKSPP_JS = 'LINKSPP_JS'; // Javascript
    public const PREF_LINKSPP_OPEN_IN_NEW_TAB = 'LINKSPP_OPEN_IN_NEW_TAB'; // enable open link in new browser tab
    public const PREF_LINKSPP_OVERVIEW_MAX_ROWS = 'LINKSPP_OVERVIEW_MAX_ROWS'; // max rows for non-admin xref overview
    public const PREF_LINKSPP_OVERVIEW_ACCESS = 'LINKSPP_OVERVIEW_ACCESS'; // -1=Hidden, 0=Managers, 1=Members, 2=All
    public const PREF_LINKSPP_DETAIL_ACCESS = 'LINKSPP_DETAIL_ACCESS'; // -1=Hidden, 0=Managers, 1=Members, 2=All
    public const PREF_LINKSPP_DETAIL_INCLUDE_BLOCKS = 'LINKSPP_DETAIL_INCLUDE_BLOCKS'; // include block references in detail view
    public const PREF_DEBUG_LOG = 'DEBUG_LOG'; // PHP error_log debug output; 0=off, 1=on

    /**
     * Canonical handler keys (via Functions::canonicalHandlerKey) of the
     * non-INDI record detail pages that receive JS tab injection,
     * mapped to the GEDCOM record type.
     */
    public const XREF_DETAIL_HANDLER_KEYS = [
        'Note'       => 'NOTE',
        'Media'      => 'OBJE',
        'Source'     => 'SOUR',
        'Repository' => 'REPO',
        'Family'     => 'FAM',
        'Location'   => '_LOC',
    ];

    public const PREF_MD_ACTIVE = 'MD_ACTIVE'; // enable markdown enhancements
    public const PREF_MD_IMG_ACTIVE = 'MD_IMG_ACTIVE'; // enable enhanced markdown img syntax
    public const PREF_MD_IMG_STDCLASS = 'MD_IMG_STDCLASS'; // standard classname(s) for div wrapping img- and link-tag    
    public const PREF_MD_IMG_TITLE_STDCLASS = 'MD_IMG_TITLE_STDCLASS'; // standard classname(s) for picture subtitle
    public const PREF_MD_IMG_OPEN_IN_NEW_TAB = 'MD_IMG_OPEN_IN_NEW_TAB';
    public const PREF_MDE_ACTIVE = 'MDE_ACTIVE'; // enable markdown editor for note textareas
    public const PREF_MDE_RULES = 'MDE_RULES'; // serialized array of rules (handler and element filter) that define where to apply the mde
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
    public const PREF_MD_EXT_SMAPU_TYPE = 'MD_EXT_SMAPU_TYPE'; // markdown extension - smart punctuation triple-state. 0=off, 1=user defined, 2=on (auto)
    public const PREF_MD_EXT_SMAPU_DEF = 'MD_EXT_SMAPU_DEF'; // markdown extension - smart punctuation quote definition as json object { do,dc,so,sc }
    
    

    public const STDCLASS_HOME_LINK = 'homelink';
    public const STDCLASS_MD_CONTENT = 'md-content'; // section with rendered content; also used in index-img.js
    public const STDCLASS_MD_CONTENT_WT2_3 = 'wt-markdown'; // wt2.3 wraps rendered markdown in div.wt-markdown
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

    public const STD_WTHB_LINKS_JSON = '[
{"title":"webtrees FAQ", "url":"https://webtrees.net/faq/"}
,{"title":"webtrees Forum", "url":"https://www.webtrees.net/index.php/forum/recent"}
,{"title":"webtrees Forum - ask a question (account necessary)", "url":"https://www.webtrees.net/index.php/forum/webtrees-help-and-support/topic/create"}
,{"title":"CompGen Discourse", "url":"https://discourse.genealogy.net/c/webtrees/153"}
,{"title":"CompGen Discourse - ask a question (account necessary)", "url":"https://discourse.genealogy.net/new-topic?category=webtrees"}
,{"title":"GitHub - webtrees issues", "url":"https://github.com/fisharebest/webtrees/issues?q=is%3Aissue state%3Aopen sort%3Aupdated-desc"}
,{"title":"GitHub - webtrees related projects", "url":"https://github.com/topics/webtrees?o=desc&s=updated"}
]'; // standard additional links for webtrees manual top menu
    
    public const HELP_TABLE = 'le_route_help_map';

    public const HELP_CSV = __DIR__ . DIRECTORY_SEPARATOR . 'Schema' . DIRECTORY_SEPARATOR . 'SeedHelpTable.csv';

    public const int HELP_SCHEMA_TARGET_VERSION = 7;

    public const PREFERENCES_SCHEMA = [
        // required settings:
        // - type=int|string|bool
        // - default=string with default value (bool=0/1) 
        // optional settings for dependent options:
        // - parent=PREF_-Keyname 
        // - mode=define how value is overwritten py parent value
        self::PREF_HOME_LINK_TYPE            => [ 'type' => 'int',    'default' => '1' ], // triple-state, 0=off, 1=tree, 2=my-page
        self::PREF_HOME_LINK_URL             => [ 'type' => 'string', 'default' => ''],
        self::PREF_HOME_LINK_OPEN_IN_NEW_TAB => [ 'type' => 'bool',   'default' => '1', 'parent' => self::PREF_OPEN_IN_NEW_TAB, 'mode' => OverwriteMode::ParentIsNotOne],
        self::PREF_HOME_LINK_JSON            => [ 'type' => 'string', 'default' => '' ], // json object { '*': stylerules-string, 'theme': stylerules-string}
        self::PREF_WTHB_ACTIVE               => [ 'type' => 'bool',   'default' => '1' ],
        self::PREF_WTHB_SUBCONTEXT           => [ 'type' => 'bool',   'default' => '1' ],
        self::PREF_WTHB_TOCNSEARCH           => [ 'type' => 'bool',   'default' => '1' ],
        self::PREF_WTHB_FAICON               => [ 'type' => 'bool',   'default' => '1' ],
        self::PREF_WTHB_UPDATE               => [ 'type' => 'bool',   'default' => '1' ],
        self::PREF_WTHB_ADMINVIEWPATCH       => [ 'type' => 'bool',   'default' => '1' ],
        self::PREF_WTHB_LASTHASH             => [ 'type' => 'string' ], // no default needed, internal setting
        self::PREF_WTHB_OPEN_IN_NEW_TAB      => [ 'type' => 'bool',   'default' => '1', 'parent' => self::PREF_OPEN_IN_NEW_TAB, 'mode' => OverwriteMode::ParentIsNotOne ],
        self::PREF_WTHB_SPLIT_TOPMENU        => [ 'type' => 'bool',   'default' => '1' ],
        self::PREF_WTHB_WTCOREHELP           => [ 'type' => 'bool',   'default' => '1' ],
        self::PREF_WTHB_LINKS_TYPE           => [ 'type' => 'int',    'default' => '2' ], // triple-state, 0=off, 1=user defined, 2=on
        self::PREF_WTHB_LINKS_JSON           => [ 'type' => 'string', 'default' => self::STD_WTHB_LINKS_JSON ],
        self::PREF_JS_DEBUG_CONSOLE          => [ 'type' => 'bool',   'default' => '0' ],
        self::PREF_DEBUG_LOG                 => [ 'type' => 'bool',   'default' => '0' ],
        self::PREF_OPEN_IN_NEW_TAB           => [ 'type' => 'int',    'default' => '2' ], // triple-state, 0=off, 1=user defined, 2=on
        self::PREF_WTHB_STD_LINK             => [ 'type' => 'string', 'default' => self::STDLINK_WTHB ], // url
        self::PREF_GENWIKI_LINK              => [ 'type' => 'string', 'default' => self::STDLINK_GENWIKI ], // url
        self::PREF_WTHB_TRANSLATE            => [ 'type' => 'int',    'default' => '1' ], // triple-state. 0=off, 1=user defined, 2=on
        self::PREF_LINKSPP_ACTIVE            => [ 'type' => 'bool',   'default' => '1' ],
        self::PREF_LINKSPP_JS                => [ 'type' => 'string', 'default' => '' ],
        self::PREF_LINKSPP_OPEN_IN_NEW_TAB   => [ 'type' => 'bool',   'default' => '1', 'parent' => self::PREF_OPEN_IN_NEW_TAB, 'mode' => OverwriteMode::ParentIsNotOne ],
        self::PREF_LINKSPP_OVERVIEW_MAX_ROWS => [ 'type' => 'int',    'default' => '10000' ],
        self::PREF_LINKSPP_OVERVIEW_ACCESS  => [ 'type' => 'int',    'default' => '1' ], // -1=Hidden, 0=Managers, 1=Members, 2=All
        self::PREF_LINKSPP_DETAIL_ACCESS    => [ 'type' => 'int',    'default' => '1' ], // -1=Hidden, 0=Managers, 1=Members, 2=All
        self::PREF_LINKSPP_DETAIL_INCLUDE_BLOCKS => [ 'type' => 'bool', 'default' => '0' ],
        self::PREF_UID_ACTIVE                => [ 'type' => 'bool',   'default' => '1' ],
        // markdown
        self::PREF_MD_ACTIVE                 => [ 'type' => 'bool',   'default' => '1' ],
        self::PREF_MD_IMG_ACTIVE             => [ 'type' => 'bool',   'default' => '1', 'parent' => self::PREF_MD_ACTIVE, 'mode' => OverwriteMode::ParentIsZero ],
        self::PREF_MD_IMG_STDCLASS           => [ 'type' => 'string', 'default' => self::STDCLASS_MD_IMG ], // css class name
        self::PREF_MD_IMG_TITLE_STDCLASS     => [ 'type' => 'string', 'default' => self::STDCLASS_MD_IMG_TITLE ], // css class name
        self::PREF_MD_IMG_OPEN_IN_NEW_TAB    => [ 'type' => 'bool',   'default' => '1', 'parent' => self::PREF_OPEN_IN_NEW_TAB, 'mode' => OverwriteMode::ParentIsNotOne ],
        self::PREF_MDE_ACTIVE                => [ 'type' => 'bool',   'default' => '1', 'parent' => self::PREF_MD_ACTIVE, 'mode' => OverwriteMode::ParentIsZero ],
        self::PREF_MDE_RULES                 => [ 'type' => 'string'], // no default needed, internal setting
        self::PREF_MD_TD_H_CTRL_TYPE         => [ 'type' => 'int',    'default' => '1' ], // triple-state. 0=off, 1=available (default=off), 2=available (default=ON)
        self::PREF_MD_TD_H_CB_VISIBLE        => [ 'type' => 'bool',   'default' => '1' ],
        // markdown extensions
        self::PREF_MD_EXT_ACTIVE             => [ 'type' => 'bool',   'default' => '1', 'parent' => self::PREF_MD_ACTIVE, 'mode' => OverwriteMode::ParentIsZero ],
        self::PREF_MD_EXT_STRIKE_ACTIVE      => [ 'type' => 'bool',   'default' => '1', 'parent' => self::PREF_MD_EXT_ACTIVE, 'mode' => OverwriteMode::ParentIsZero ],
        self::PREF_MD_EXT_DL_ACTIVE          => [ 'type' => 'bool',   'default' => '1', 'parent' => self::PREF_MD_EXT_ACTIVE, 'mode' => OverwriteMode::ParentIsZero ],
        self::PREF_MD_EXT_MARK_ACTIVE        => [ 'type' => 'bool',   'default' => '1', 'parent' => self::PREF_MD_EXT_ACTIVE, 'mode' => OverwriteMode::ParentIsZero ],
        self::PREF_MD_EXT_FN_ACTIVE          => [ 'type' => 'bool',   'default' => '1', 'parent' => self::PREF_MD_EXT_ACTIVE, 'mode' => OverwriteMode::ParentIsZero ],
        self::PREF_MD_EXT_FN_BACKREF_CHAR    => [ 'type' => 'string', 'default' => '↩' ],
        self::PREF_MD_EXT_FN_ADD_HR          => [ 'type' => 'bool',   'default' => '1' ],
        self::PREF_MD_EXT_TOC_ACTIVE         => [ 'type' => 'bool',   'default' => '1', 'parent' => self::PREF_MD_EXT_ACTIVE, 'mode' => OverwriteMode::ParentIsZero ],
        self::PREF_MD_EXT_TOC_PERMALINK_CHAR => [ 'type' => 'string', 'default' => '#' ],
        self::PREF_MD_EXT_TOC_STYLE          => [ 'type' => 'string', 'default' => 'none' ],
        self::PREF_MD_EXT_TOC_NORMALIZE      => [ 'type' => 'string', 'default' => 'relative' ],
        self::PREF_MD_EXT_TOC_POS            => [ 'type' => 'string', 'default' => 'dropdown' ],
        self::PREF_MD_EXT_TOC_PLACEHOLDER    => [ 'type' => 'string', 'default' => '[TOC]'],
        self::PREF_MD_EXT_TOC_CSSCLASS       => [ 'type' => 'string', 'default' => '' ],
        self::PREF_MD_EXT_SMAPU_TYPE         => [ 'type' => 'int',    'default' => '2', 'parent' => self::PREF_MD_EXT_ACTIVE, 'mode' => OverwriteMode::ParentIsZero], // triple-state. 0=off, 1=user defined, 2=on (auto)
        self::PREF_MD_EXT_SMAPU_DEF          => [ 'type' => 'string', 'default' => '' ], //JSON
    ];

    private array|null $prefs_cache = null;

    protected WthbService $wthb;
    protected MarkdownEditorActivationService $mde;




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

        $this->mde = new MarkdownEditorActivationService($this);
        // By registering the service now it is available to other custom module in their boot methods. No impact due to unpredictable boot order of modules.
        // The service is also available when the module is disabled - however, this should not be a problem, as it only has an effect when this module is enabled.
        Registry::container()->set(MarkdownEditorActivationService::class, $this->mde);
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
     * Get the module logger instance.
     */
    public static function log(): ModuleLog
    {
        return ModuleLog::for(self::LOG_ID);
    }

    public function wthb(): WthbService
    {
        return $this->wthb;
    }

    public function mde(): MarkdownEditorActivationService
    {
        return $this->mde;
    }

    /**
     * Called for all *enabled* modules.
     */
    public function boot(): void
    {
        ModuleLog::for(self::LOG_ID, $this->getPref(self::PREF_DEBUG_LOG, true) === '1');

        Functions::updateSchema($this, '\Schwendinger\Webtrees\Module\LinkEnhancer\Schema', 'SCHEMA_VERSION', self::HELP_SCHEMA_TARGET_VERSION);

        $access_level = (int) $this->getPref(self::PREF_LINKSPP_DETAIL_ACCESS, true);
        $this->access_level = Functions::wtIsAtLeast2_3() ? AccessLevel::from($access_level) : $access_level;

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
            self::CACHE_TTL_1D
        );

        // Register a namespace for our views.
        View::registerNamespace($this->name(), $this->resourcesFolder() . 'views/');

        if ($this->getPref(self::PREF_WTHB_ACTIVE, true)) {
            if ($this->getPref(self::PREF_WTHB_ADMINVIEWPATCH, true)
                && !$this->vesta_common_enabled) 
            {
                // register patched administration layout if vesta common is not available
                View::registerCustomView('::layouts/administration', $this->name() . '::patched/layouts/administration');
            }

            if ($this->getPref(self::PREF_WTHB_TOCNSEARCH, true)) { // webtrees manual help (search and toc)
                Functions::registerRoute('/helpwthb/{language}', HelpWthbAction::class);

            }
            if ($this->getPref(self::PREF_WTHB_WTCOREHELP, true)) { // webtrees core help overview
                Functions::registerRoute('/helpwtcore/{language}', HelpWtCoreAction::class);
            }            
        }

        if ($this->getPref(self::PREF_MD_ACTIVE, true)) {
            Registry::markdownFactory(new CustomMarkdownFactory($this));
            
            if ($this->getPref(self::PREF_MDE_ACTIVE, true)) { // markdown and links++ help
                Functions::registerRoute('/helpmd/{language}', HelpMdAction::class);
            }
        }

        
        if ($this->getPref(self::PREF_LINKSPP_ACTIVE, true)) {
            Functions::registerRoute('/tree/{tree}/goto-xref/{xref}', GotoXrefAction::class);
            Functions::registerRoute('/xref-overview-list-data', XrefOverviewListData::class);
            Functions::registerRoute('/tree/{tree}/le-xref-detail/{xref}', XrefDetailData::class);
        }

        if ($this->getPref(self::PREF_UID_ACTIVE, true)) {
            // use unique custom name, if different routes use the same handler class
            Functions::registerRoute('/tree/{tree}/goto-uid/{uid}', 'le.goto-uid.tree', GotoUidAction::class);
            Functions::registerRoute('/goto-uid/{uid}', 'le.goto-uid.global', GotoUidAction::class);
            Functions::registerRoute('/tree/{tree}/goto-id/{id}', 'le.goto-id.tree', GotoIdAction::class);
            Functions::registerRoute('/goto-id/{id}', 'le.goto-id.global', GotoIdAction::class);
        }

        // XREF overview - server-side DataTables data endpoint (admin only)
        Functions::registerRoute('/admin-xref-overview-data', AdminXrefOverviewData::class);

        // Datafix: process block modules (AJAX POST)
        Functions::registerRoute('/admin/datafix-process-blocks/{tree}', 'le.datafix-process-blocks', DataFixBlocksAction::class, [], true);

        // Datafix: trigger index rebuild (AJAX POST)
        Functions::registerRoute('/admin/datafix-rebuild-index/{tree}', 'le.datafix-rebuild-index', DataFixRebuildIndexAction::class, [], true);
    }
 

    /**
     * Raw content, to be added at the end of the <head> element.
     * Typically, this will be <link> and <meta> elements.
     *
     * @return string
     */
    public function headContent(): string
    {
        return $this->contentBuilder()->buildHead();
    }

    /**
     * Raw content, to be added at the end of the <body> element.
     * Typically, this will be <script> elements.
     *
     * @return string
     */
    public function bodyContent(): string
    {
        return $this->contentBuilder()->buildBody();
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
        return $this->wthbAdminHandler()->resetRoutes($request);
    }    

    /**
     * import in webtrees registered routes into help table
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    public function getAdminImportRoutesAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->wthbAdminHandler()->importRoutes($request);
    }

    /**
     * export Custom Module Manager config as csv
     * Jefferson49\Webtrees\Module\CustomModuleManager\Configuration\ModuleUpdateServiceConfiguration
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    public function getAdminCmmConfig2CsvAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->wthbAdminHandler()->cmmConfig2Csv($request);
    }    

    /**
     * Download context help mapping table as CSV
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    public function postAdminCsvExportAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->wthbAdminHandler()->csvExport($request);
    }


    /**
     * import context help mapping from CSV into help table
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    public function postAdminCsvImportAction(ServerRequestInterface $request): ResponseInterface {
        return $this->wthbAdminHandler()->csvImport($request);
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
                } catch (Exception $ex) {
                    self::log()->error('pref ' . $preference . ' not saved: ' . $ex->getMessage(), 'Settings', flash: I18N::translate('Some settings could not be saved.'));
                }
            }

            FlashMessages::addMessage(MoreI18N::xlate(
                'The preferences for the module “%s” have been updated.',
                $this->title()
            ), 'success');
        }
        return redirect($this->getConfigLink());
    }


    /**
     * XREF Overview — admin page (server-side DataTables) listing all
     * records containing XREFs / linkenhancer links, with optional
     * filters (target XREF, record type, tree). The data endpoint is
     * AdminXrefOverviewData; the page itself stays cheap.
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    public function getAdminXrefOverviewAction(ServerRequestInterface $request): ResponseInterface
    {
        $this->layout = 'layouts/administration';

        $params  = Validator::queryParams($request);
        $xref    = trim((string) $params->string('xref', ''));
        $rectype = (string) $params->string('rectype', '');
        $tree_id = (int) $params->integer('tree', 0);
        $max_links = XrefsService::normalizeLinksPerClass((int) $params->integer('max_links', XrefsService::LINKS_PER_CLASS_DEFAULT));
        $live      = $params->boolean('live', false);
        $target    = $params->string('target', '');

        $index_status = XrefsService::indexStatus();

        $data_params = [];
        // The "referencing XREF" filter narrows the GEDCOM result set at the
        // SQL level (precise via index target_xref, coarse via regex in live).
        if ($xref !== '') {
            $data_params['xref'] = $xref;
        }
        if ($live) {
            $data_params['live'] = 1;
        }
        if ($rectype !== '') {
            $data_params['rectype'] = $rectype;
        }
        if ($tree_id > 0) {
            $data_params['tree'] = $tree_id;
        }
        if ($max_links !== XrefsService::LINKS_PER_CLASS_DEFAULT) {
            $data_params['max_links'] = $max_links;
        }
        // The "only broken targets" filter applies to both the index and the
        // live-scan path, so it is always forwarded when active.
        if ($target === 'problems') {
            $data_params['target'] = 'problems';
        }
        // D3: the "not unique" insight for UID targets (overview) is hard-gated
        // on the UID feature; the module is the source of truth and forwards it.
        $data_params['uid_active'] = (int) $this->getPref(self::PREF_UID_ACTIVE, true);

        return $this->viewResponse($this->name() . '::xref-overview', [
            'title' => I18N::translate('Cross-Reference Overview'),
            'module' => $this,
            'data_url' => route(AdminXrefOverviewData::class, $data_params),
            'xref' => $xref,
            'rectype' => $rectype,
            'tree_id' => $tree_id,
            'max_links' => $max_links,
            'live' => $live,
            'target' => $target,
            'rectypes' => XrefsService::supportedGedcomRecordKeys(),
            'block_rectypes' => XrefsService::BLOCKS,
            'trees' => Registry::container()->get(TreeService::class)->all(),
            'index_status' => $index_status,
            'limited_mode' => !XrefsService::supportsRegexp(),
        ]);
    }


    /**
     * Admin page: select a tree, preview its cross-tree XREF collisions,
     * and offer to renumber them (keeping le-links and the le_* index in sync).
     */
    public function getAdminRenumberAction(ServerRequestInterface $request): ResponseInterface
    {
        $this->layout = 'layouts/administration';
        [$view, $data] = $this->renumberHandler()->showPage($request);
        return $this->viewResponse($view, $data);
    }

    /**
     * Admin action: execute the single-pass renumber for the selected tree.
     */
    public function postAdminRenumberAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->renumberHandler()->execute($request);
    }

    // ─── ModuleDataFixInterface ─────────────────────────────────────────────

    private ?DataFixDispatcher $data_fix_dispatcher = null;
    private ?WthbAdminHandler $wthb_admin_handler = null;
    private ?RenumberActionHandler $renumber_handler = null;
    private ?ContentBuilder $content_builder = null;

    public function dataFixDispatcher(): DataFixDispatcher
    {
        return $this->data_fix_dispatcher ??= new DataFixDispatcher();
    }

    private function wthbAdminHandler(): WthbAdminHandler
    {
        return $this->wthb_admin_handler ??= new WthbAdminHandler($this->wthb, $this);
    }

    private function renumberHandler(): RenumberActionHandler
    {
        return $this->renumber_handler ??= new RenumberActionHandler($this);
    }

    private function contentBuilder(): ContentBuilder
    {
        return $this->content_builder ??= new ContentBuilder($this);
    }

    public function fixOptions(Tree $tree): string
    {
        $params = isset($_GET['fix_type']) ? ['fix_type' => (string) $_GET['fix_type']] : [];
        return $this->dataFixDispatcher()->optionsHtml($tree, $params);
    }

    public function recordsToFix(Tree $tree, array $params): Collection
    {
        return $this->dataFixDispatcher()->recordsToFix($tree, $params);
    }

    public function doesRecordNeedUpdate(GedcomRecord $record, array $params): bool
    {
        return $this->dataFixDispatcher()->needsUpdate($record, $params);
    }

    public function previewUpdate(GedcomRecord $record, array $params): string
    {
        return $this->dataFixDispatcher()->preview($record, $params);
    }

    public function updateRecord(GedcomRecord $record, array $params): void
    {
        $this->dataFixDispatcher()->apply($record, $params);
    }


    /**
     * Reset
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    public function getAdminResetAccessOverwritesAction(ServerRequestInterface $request): ResponseInterface
    {
        $params = Validator::queryParams($request);
        $type = null;
        try {
            $type = (string) $params->string('type', null);
            $type = strtolower($type);
        } catch (Throwable $e) {
            self::log()->debug('type param extract failed: ' . $e->getMessage(), 'AdminReset');
        }
        if (!$type || !in_array($type, ['list', 'tab'])) {
            return redirect($this->getConfigLink());
        }

        $access_level = null;
        try {
            $access_level = (int) $params->integer('access_level', null);
            $access_level = $access_level && ($access_level >= 0 && $access_level <= 2) ? $access_level : null;
        } catch (Throwable $e) {}
        
        $query = DB::table('module_privacy')
            ->where('interface', '=', ($type === 'list' ? ModuleListInterface::class : ModuleTabInterface::class))
            ->where('module_name', '=', self::MODULE_NAME);

        if ($access_level) {
            $query = $query->where('access_level', '=', $access_level);
        }

        $query->delete();

        return redirect($this->getConfigLink());
    }

    public function listIsEmpty(Tree $tree): bool
    {
        if (!$this->getPref(self::PREF_LINKSPP_ACTIVE, true)) {
            return true;
        }
        $default_level = (int) $this->getPref(self::PREF_LINKSPP_OVERVIEW_ACCESS, true);
        return !self::userHasOverviewAccess($tree, $default_level);
    }

    /**
     * Check if the current user has access to the xref overview for this tree.
     * Resolution: module_privacy (per-tree override) > module pref (default).
     * Uses Auth::isManager()/isMember() (bool) – no int/enum comparison.
     */
    public static function userHasOverviewAccess(Tree $tree, int $default_level): bool
    {
        $override = DB::table('module_privacy')
            ->where('gedcom_id', '=', $tree->id())
            ->where('interface', '=', ModuleListInterface::class)
            ->where('module_name', '=', self::MODULE_NAME)
            ->value('access_level');

        $level = ($override !== null) ? (int) $override : $default_level;

        return match ($level) {
            -1      => false,
            0       => Auth::isManager($tree),
            1       => Auth::isMember($tree),
            default => true,
        };
    }

    /**
     * row count of overwrites in module_privacy for list or tab (total and access level specific)
     * @param string $type   list or tab
     * @param null|int $access_level
     * @return array{"access_level": int, total: int}
     */
    public static function countAccessOverwrites(string $type, null|int $access_level): array
    {
        $class = (string) ($type === 'list' ? ModuleListInterface::class : ModuleTabInterface::class);
        return [
            'total' => DB::table('module_privacy')
                ->where('interface', '=', $class)
                ->where('module_name', '=', self::MODULE_NAME)
                ->count(),
            'access_level' => $access_level === null ?
                    0 :
                    DB::table('module_privacy')
                        ->where('interface', '=', $class)
                        ->where('module_name', '=', self::MODULE_NAME)
                        ->where('access_level', '=', $access_level)
                        ->count()
        ];
    }


    /**
     * The title for a specific instance of this list. (ModuleListInterface)
     * @return string
     */
    public function listTitle(): string
    {
        return I18N::translate('Cross-Reference Overview');
    }

    /**
     * The URL for a page showing list options. (ModuleListInterface)
     * @param Tree $tree
     * @param array $parameters
     * @return string
     */
    public function listUrl(Tree $tree, array $parameters = []): string
    {
        if (Auth::isAdmin()) {
            return route('module', ['module' => $this->name(), 'action' => 'AdminXrefOverview', 'tree' => $tree->name()]);
        }

        return route('module', [
                'module' => $this->name(),
                'action' => 'List',
                'tree'    => $tree->name(),
        ] + $parameters);
    }

    /**
     * CSS class for the menu (ModuleListInterface)
     * @return string
     */
    public function listMenuClass(): string
    {
        return 'menu-list-xrefs'; // css class is used in getThemeSpecificCss()
    }

    // ─── ModuleTabInterface ─────────────────────────────────────────────────

    public function tabTitle(): string
    {
        return I18N::translate('Cross-references');
    }

    public function defaultTabOrder(): int
    {
        return 10;
    }

    public function canLoadAjax(): bool
    {
        return true;
    }

    public function hasTabContent(\Fisharebest\Webtrees\Individual $individual): bool
    {
        return $this->getPref(self::PREF_LINKSPP_ACTIVE, true);
    }

    public function isGrayedOut(\Fisharebest\Webtrees\Individual $individual): bool
    {
        return false;
    }

    public function supportedFacts(): \Illuminate\Support\Collection
    {
        return new \Illuminate\Support\Collection();
    }

    public function getTabContent(\Fisharebest\Webtrees\Individual $individual): string
    {
        return $this->getXrefDetailViewContent($individual);
    }

    /**
     * xrefs (in-/outgoing) for a GEDCOM record - display in record detail tab
     * @param GedcomRecord $record
     * @return string                view content
     */
    public function getXrefDetailViewContent(GedcomRecord $record) : string {
        $service        = new XrefDetailService();
        $include_blocks = (bool) $this->getPref(self::PREF_LINKSPP_DETAIL_INCLUDE_BLOCKS, true);
        $outgoing_html  = $service->outgoingLinksHtml($record, 0);
        $incoming       = $service->incomingReferences($record->tree(), $record->xref(), $include_blocks);
        $total_count    = count($service->outgoingLinks($record)['entries'])
            + array_sum(array_column($incoming, 'link_count'));

        return view($this->name() . '::xref-detail-tab', [
            'record'        => $record,
            'outgoing_html' => $outgoing_html,
            'incoming'      => $incoming,
            'total_count'   => $total_count,
        ]);
    }
    // ─── ModuleListInterface: listAction ────────────────────────────────────

    /**
     * Per-tree cross-reference overview for non-admin users (members and above).
     * Privacy-filtered: no raw GEDCOM snippets, personal blocks only for owner.
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    public function getListAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree      = Validator::attributes($request)->tree();
        $params    = Validator::queryParams($request);

        $denied = ClassName::get(ClassName::EXCEPTION_HTTP_FORBIDDEN);
        $default_level = (int) $this->getPref(self::PREF_LINKSPP_OVERVIEW_ACCESS, true);
        if (!self::userHasOverviewAccess($tree, $default_level)) {
            throw new $denied();
        }

        $xref      = trim((string) $params->string('xref', ''));
        $rectype   = (string) $params->string('rectype', '');
        $max_links = XrefsService::normalizeLinksPerClass((int) $params->integer('max_links', XrefsService::LINKS_PER_CLASS_DEFAULT));
        $live      = $params->boolean('live', false);
        $target    = $params->string('target', '');
        $max_rows  = (int) $this->getPref(self::PREF_LINKSPP_OVERVIEW_MAX_ROWS, true);

        $index_status = XrefsService::indexStatus();

        $data_params = [
            'tree' => $tree->id(),
            'max_rows' => $max_rows,
        ];
        if ($xref !== '') {
            $data_params['xref'] = $xref;
        }
        if ($live) {
            $data_params['live'] = 1;
        }
        if ($rectype !== '') {
            $data_params['rectype'] = $rectype;
        }
        if ($max_links !== XrefsService::LINKS_PER_CLASS_DEFAULT) {
            $data_params['max_links'] = $max_links;
        }
        if ($target === 'problems') {
            $data_params['target'] = 'problems';
        }
        $data_params['uid_active'] = (int) $this->getPref(self::PREF_UID_ACTIVE, true);

        return $this->viewResponse($this->name() . '::xref-overview-list', [
            'title' => I18N::translate('Cross-Reference Overview'),
            'module' => $this,
            'tree' => $tree,
            'data_url' => route(XrefOverviewListData::class, $data_params),
            'xref' => $xref,
            'rectype' => $rectype,
            'max_links' => $max_links,
            'live' => $live,
            'target' => $target,
            'rectypes' => XrefsService::supportedGedcomRecordKeys(),
            'block_rectypes' => XrefsService::BLOCKS,
            'index_status' => $index_status,
            'limited_mode' => !XrefsService::supportsRegexp(),
        ]);
    }

    /**
     * import shipped csv route mapping
     */
    protected function importDeliveredCsv(): void
    {
        $this->wthbAdminHandler()->importDelivered();
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
     * @param bool   $typecasted      if true, return type cated value depending on PREFERENCES_SCHEMA type
     * @param bool   $resolveCascade
     *
     * @return mixed
     */
    public function getPref(string $setting_name, bool $typecasted = false, bool $resolveCascade = false): mixed
    {
        if ($this->prefs_cache === null) {
            $this->prefs_cache = $this->getAllPrefs();
        }
        $result = (array_key_exists($setting_name, $this->prefs_cache) ? $this->prefs_cache[$setting_name] : '');
        
        $setting_schema = (array_key_exists($setting_name, self::PREFERENCES_SCHEMA) ? self::PREFERENCES_SCHEMA[$setting_name] : []);

        $type = $setting_schema['type'] ?? 'string';

        $value = match ($type) {
            'int'   => $result === ' ' ? '0' : $result,
            'bool'  => $result === ' ' ? '0' : $result,
            default => $result,
        };

        $value = trim( (string) ($value !== '' ? $value : $setting_schema['default'] ?? ''));

        if ($resolveCascade 
            && ($setting_schema['parent'] ?? null) && array_key_exists($setting_schema['parent'], self::PREFERENCES_SCHEMA)
            && ($setting_schema['mode'] ?? null)
        ) {
            $parentvalue = trim((string) $this->getPref($setting_schema['parent'], false, true) );

            $value = match ($setting_schema['mode']) {
                OverwriteMode::ParentIsZero   => ($parentvalue == 0 ? $parentvalue : $value),
                OverwriteMode::ParentIsNotOne => ($parentvalue != 1 ? $parentvalue : $value),
                default => $value,
            };            
        }
        
        if ($typecasted) {
            $value = match ($type) {
                'int' => intval($value),
                'bool' => boolval($value), // we only have int (triple state values) to cast to bool; boolval has a more appropriate logic in this case than filter_var($value, FILTER_VALIDATE_BOOLEAN),
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
        $setting_value = ($setting_value === '' ? ' ' : $setting_value);

        //allow user to blank a setting, also if we have a DEFAULT_PREFERENCE
        if (array_key_exists($setting_name, self::PREFERENCES_SCHEMA)) {
            $setting_value = match(self::PREFERENCES_SCHEMA[$setting_name]['type'] ?? '') {
                'string' => ((self::PREFERENCES_SCHEMA[$setting_name]['default'] ?? false) && $setting_value === '' ? ' ' : $setting_value),
                'bool'   => boolval(trim($setting_value)) ? '1' : '0',
                'int'    => (string) intval(trim($setting_value)),
                default  => $setting_value
            };
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
        return (new AdminSettingsBuilder($this))->build();
    }


    public static function getDefaultPrefsAsJson():string  {
        $reduced = array_map(function ($sub) {
            return array_intersect_key($sub, array_flip(['type', 'default']));
        }, self::PREFERENCES_SCHEMA);

        $filtered = array_filter($reduced, function ($sub) {
            return array_key_exists('default', $sub);
        });
        return ((string) json_encode($filtered, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }


    // SettingInterface
    public function loadSetting(string $class): mixed
    {
        switch ($class) {
            case MarkdownEditorActivationService::class:
                $setting = $this->getPref(self::PREF_MDE_RULES);
                $setting = $setting ? (unserialize($setting, ['allowed_classes' => false]) ?: []) : [];
                return $setting ?? [];
                break;
            
            default:
                throw new Exception("loadSetting not implemented for class $class");
                break;
        }
    }
    
    public function saveSetting(string $class, mixed $data): void
    {
        switch ($class) {
            case MarkdownEditorActivationService::class:
                $this->setPref(self::PREF_MDE_RULES, serialize($data));
                break;

            default:
                throw new Exception("saveSetting not implemented for class $class");
                break;
        }
    }

    /**
     * Request handler for MiddlewareInterface
     * injects modal ajax view where needed
     * @param ServerRequestInterface $request
     * @param RequestHandlerInterface $handler
     * @return ResponseInterface
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        // include wt-ajax-modal if needed and not already present
        // only helpful on html pages requested by GET method
        if (!$this->contentBuilder()->needsAjax() || strtoupper($request->getMethod()) !== 'GET') {
            return $response;
        }

        $contentType = $response->getHeaderLine('Content-Type');
        if (!str_contains($contentType, 'text/html')) {
            return $response;
        }

        $body = (string) $response->getBody();

        $block = view('modals/ajax');
        if (!str_contains($body, $block)) {

            if (str_contains($body, '</body>')) {
                $body = str_replace('</body>', '<!-- ajax modal supplemented -->' . $block . '</body>', $body);
            }

            $response = $response->withBody(Stream::create($body));
        }

        return $response;
    }    
}