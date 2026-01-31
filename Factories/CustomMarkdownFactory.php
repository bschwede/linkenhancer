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

namespace Schwendinger\Webtrees\Module\LinkEnhancer\Factories;

use Schwendinger\Webtrees\Module\LinkEnhancer\LinkEnhancerModule;
use Schwendinger\Webtrees\Module\LinkEnhancer\CommonMark\LeImageRenderer;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\CommonMark\CensusTableExtension;
use Fisharebest\Webtrees\CommonMark\XrefExtension;
use Fisharebest\Webtrees\Factories\MarkdownFactory;
use League\CommonMark\MarkdownConverter;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\DescriptionList\DescriptionListExtension;
use League\CommonMark\Extension\Footnote\FootnoteExtension;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
//use League\CommonMark\Extension\Highlight\HighlightExtension; //v2.8.0
use League\CommonMark\Extension\Strikethrough\StrikethroughExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\Extension\TableOfContents\TableOfContentsExtension;


enum ExtensionSettingOption {
    case TOC_STYLE;
    case TOC_NORMALIZE;
    case TOC_POSITION;
}

/**
 * Create a markdown converter.
 */
class CustomMarkdownFactory extends MarkdownFactory {

    public function __construct(private readonly LinkEnhancerModule $module) {
    }


    public static function getExtensionSettingOptions(ExtensionSettingOption $option):array {
        return match($option) {
            ExtensionSettingOption::TOC_STYLE => [
                'bullet'  => I18N::translate('unordered, bulleted list') . ' (<ul>)',
                'ordered' => I18N::translate('ordered list') . ' (<ol>)'
            ],
            ExtensionSettingOption::TOC_POSITION => [
                'top'             => I18N::translate('Insert at the very top of the document, before any content'),
                'before-headings' => I18N::translate('Insert just before the very first heading'),
                'placeholder'     => I18N::translate('Location is manually defined by a placeholder'),
            ],
            ExtensionSettingOption::TOC_NORMALIZE => [
                'relative' => I18N::translate('applies nesting, but handles edge cases'),
                'flat'     => I18N::translate('a flat, single-level list'),
                'as-is'    => I18N::translate('nesting exactly as headings occur within the document'),
            ]
        };
    }

    private function initConfig(): array {
        $config = [...self::CONFIG_MARKDOWN];
        $extensionclasses = [];

        if (!$this->module->getPref(LinkEnhancerModule::PREF_MD_EXT_ACTIVE, true)) {
            $config['_extensions'] = [];
            return $config;
        }

        //++ Additional extensions
        if ($this->module->getPref(LinkEnhancerModule::PREF_MD_EXT_STRIKE_ACTIVE, true)) {
            // https://commonmark.thephpleague.com/2.x/extensions/strikethrough/
            // fisharebest/webtrees#5113
            $extensionclasses[] = StrikethroughExtension::class;
        }

        if ($this->module->getPref(LinkEnhancerModule::PREF_MD_EXT_DL_ACTIVE, true)) {
            // https://commonmark.thephpleague.com/2.x/extensions/description-lists/
            $extensionclasses[] = DescriptionListExtension::class;
        }

        if ($this->module->canActivateHighlightExtension()) {
            // https://commonmark.thephpleague.com/2.x/extensions/highlight/
            // HighlightExtension is available with CommonMark v2.8.0 in webtrees 2.2.5
            $extensionclasses[] = 'League\CommonMark\Extension\Highlight\HighlightExtension';
        }

        if ($this->module->getPref(LinkEnhancerModule::PREF_MD_EXT_FN_ACTIVE, true)) {
            $extensionclasses[] = FootnoteExtension::class;
            $config['footnote'] = [ // default settings from https://commonmark.thephpleague.com/2.x/extensions/footnotes/
                'backref_class' => 'footnote-backref',
                'backref_symbol' => $this->module->getPref(LinkEnhancerModule::PREF_MD_EXT_FN_BACKREF_CHAR),
                'container_add_hr' => $this->module->getPref(LinkEnhancerModule::PREF_MD_EXT_FN_ADD_HR, true),
                'container_class' => 'footnotes',
                'ref_class' => 'footnote-ref',
                'ref_id_prefix' => 'fnref_',     // also used in resources/js/index-img.js and resources/css/index-img.css
                'footnote_class' => 'footnote',
                'footnote_id_prefix' => 'fn_',   // also used in resources/js/index-img.js
            ];
        }

        if ($this->module->getPref(LinkEnhancerModule::PREF_MD_EXT_TOC_ACTIVE, true)) {
            $extensionclasses[] = HeadingPermalinkExtension::class;
            $extensionclasses[] = TableOfContentsExtension::class;
            $config['table_of_contents'] = [ // see https://commonmark.thephpleague.com/2.x/extensions/table-of-contents/
                'html_class' => $this->module->getPref(LinkEnhancerModule::PREF_MD_EXT_TOC_CSSCLASS),
                'position' => $this->module->getPref(LinkEnhancerModule::PREF_MD_EXT_TOC_POS),
                'style' => $this->module->getPref(LinkEnhancerModule::PREF_MD_EXT_TOC_STYLE),
                'min_heading_level' => 1,
                'max_heading_level' => 6,
                'normalize' => $this->module->getPref(LinkEnhancerModule::PREF_MD_EXT_TOC_NORMALIZE),
                'placeholder' => $this->module->getPref(LinkEnhancerModule::PREF_MD_EXT_TOC_PLACEHOLDER),
            ];
            $config['heading_permalink'] = [ // used by toc-ext -  see https://commonmark.thephpleague.com/2.x/extensions/heading-permalinks/
                'html_class' => 'heading-permalink',
                'id_prefix' => 'mdnote',
                'apply_id_to_heading' => false,
                'heading_class' => '',
                'fragment_prefix' => 'mdnote', // also used in resources/js/index-img.js and resources/css/index-img.css
                'insert' => 'after',
                'min_heading_level' => 1,
                'max_heading_level' => 6,
                'title' => I18N::translate('Permalink'),
                'symbol' => $this->module->getPref(LinkEnhancerModule::PREF_MD_EXT_TOC_PERMALINK_CHAR),
                'aria_hidden' => true,
            ];
        }
        
        $config['_extensions'] = $extensionclasses;
        return $config;
    } 

    /**
     * create commonmark environment for markdown routine
     * @param Tree|null $tree
     * @param array|null $config
     *
     * @return Environment
     */
    private function createEnvironment(Tree|null $tree = null, array|null $config = null): Environment
    {
        $config ??= static::CONFIG_MARKDOWN;
        
        // code copy from parent::markdown
        $environment = new Environment($config); //static::CONFIG_MARKDOWN);
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new TableExtension());

        // Convert webtrees 1.x style census tables to commonmark format.
        $environment->addExtension(new CensusTableExtension());

        // Optionally create links to other records.
        if ($tree instanceof Tree) {
            $environment->addExtension(new XrefExtension($tree));
        }

        //+++ additional extensions
        if (isset($config['_extensions']) && is_array($config['_extensions'])) {
            foreach ($config['_extensions'] as $class) {
                $environment->addExtension(new $class());
            }
        }

        if ($this->module->getPref(LinkEnhancerModule::PREF_MD_IMG_ACTIVE, true)) {
            $environment->addRenderer(Image::class, new LeImageRenderer($this->module, $tree), 10);
        }
        //+++

        return $environment;
    }

    /**
     * @param string    $markdown
     * @param Tree|null $tree
     *
     * @return string
     */
    public function markdown(string $markdown, Tree|null $tree = null): string
    {
        $config = $this->initConfig();
        $environment = $this->createEnvironment($tree, $config);

        $converter = new MarkDownConverter($environment);

        $html = $converter->convert($markdown)->getContent();

        // The markdown convert adds newlines, but not in a documented way.  Safest to ignore them.
        $html =  strtr($html, ["\n" => '']); //return

        return $html;
    }
    
} 