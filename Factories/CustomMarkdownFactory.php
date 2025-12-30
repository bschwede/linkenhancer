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
//use League\CommonMark\Extension\Highlight\HighlightExtension; //v2.8.0
use League\CommonMark\Extension\Strikethrough\StrikethroughExtension;
use League\CommonMark\Extension\Table\TableExtension;


/**
 * Create a markdown converter.
 */
class CustomMarkdownFactory extends MarkdownFactory {

    protected const array CONFIG_MARKDOWN_EXT = [
        ...self::CONFIG_MARKDOWN,
        'footnote' => [ // default settings from https://commonmark.thephpleague.com/2.x/extensions/footnotes/
            'backref_class' => 'footnote-backref',
            'backref_symbol' => '↩',
            'container_add_hr' => true,
            'container_class' => 'footnotes',
            'ref_class' => 'footnote-ref',
            'ref_id_prefix' => 'fnref_',     // also used in resources/js/index-img.js
            'footnote_class' => 'footnote',
            'footnote_id_prefix' => 'fn_',   // also used in resources/js/index-img.js
        ],
    ];

    private LinkEnhancerModule $module;

    public function __construct($module) {
        $this->module = $module;
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
        $environment = $this->createEnvironment($tree, static::CONFIG_MARKDOWN_EXT);

        //++ Additional extensions
        $environment->addExtension(new StrikethroughExtension()); // fisharebest/webtrees#5113
        $environment->addExtension(new DescriptionListExtension());
        //$environment->addExtension(new HighlightExtension()); //v2.8.0
        $environment->addExtension(new FootnoteExtension());
        // maybe also https://commonmark.thephpleague.com/2.x/extensions/table-of-contents/ ?!
        //++

        $environment->addRenderer(Image::class, new LeImageRenderer($this->module, $tree), 10);        

        $converter = new MarkDownConverter($environment);

        $html = $converter->convert($markdown)->getContent();

        // The markdown convert adds newlines, but not in a documented way.  Safest to ignore them.
        $html =  strtr($html, ["\n" => '']); //return

        return $html;
    }
    
} 