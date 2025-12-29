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
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Media;
use Fisharebest\Webtrees\MediaFile;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\Validator;
use Fisharebest\Webtrees\Webtrees;
use Fisharebest\Webtrees\CommonMark\CensusTableExtension;
use Fisharebest\Webtrees\CommonMark\XrefExtension;
use Fisharebest\Webtrees\Factories\MarkdownFactory;
use Fisharebest\Webtrees\Http\Exceptions\HttpAccessDeniedException;
use Psr\Http\Message\ServerRequestInterface;
use League\CommonMark\MarkdownConverter;
use League\CommonMark\Environment\Environment;
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
        'footnote' => [
            'backref_class' => 'footnote-backref',
            'backref_symbol' => '↩',
            'container_add_hr' => true,
            'container_class' => 'footnotes',
            'ref_class' => 'footnote-ref',
            'ref_id_prefix' => 'fnref:',
            'footnote_class' => 'footnote',
            'footnote_id_prefix' => 'fn:',
        ],
    ];

    private null|Tree $tree;

    private string $public_url;

    private LinkEnhancerModule $module;

    private array $img_stdclassnames;

    private string $img_titleclassnames;


    public function __construct($module) {
        $this->module = $module;
        $this->img_stdclassnames = explode(' ', $this->module->getPref($this->module::PREF_MD_IMG_STDCLASS));
        $this->img_titleclassnames = $this->module->getPref($this->module::PREF_MD_IMG_TITLE_STDCLASS);
    }

    /**
     * Find <img src="#@">-Tags and replace it with reference to corresponding media object in tree. Return html string.
     *
     * Syntax:
     * - wt Media   : ![img alt text](#@id=@XREF@&w=100&h=200&cname=css-classname1+css-classname2 "img title")
     * - Public-file: ![img alt text](#@public=relpath/file.jpg&w=100&h=200&cname=css-classname1+css-classname2 "img title")
     * 
     * @param string $html
     * @param Tree|null $tree
     *
     * @return string
     */
    public function handleEnhancedImageSrc(string $html, Tree|null $tree = null): string
    {
        if ($tree == null) { // on admin pages there is no tree parameter
            return $html;
        }
        $this->tree = $tree;

        $request = Registry::container()->get(ServerRequestInterface::class);
        $base_url = Validator::attributes($request)->string('base_url');
        $this->public_url = $base_url . '/public/';

        $html = preg_replace_callback(
            '/<img[^>]*src="#@([^"]+)"[^>]*>/', 
            function ($imgmatch) {
                // 1: src-value without hash marker
                $hashvalue = htmlspecialchars_decode($imgmatch[1]);

                parse_str($hashvalue, $params);

                $classnames = isset($params['cname']) ? explode(' ',urldecode($params['cname'])) : [];
                $classnames = array_merge($classnames, $this->img_stdclassnames);
                $classnames = implode(' ', array_unique($classnames));

                if (!isset($params['id']) && !isset($params['public'])) {
                    return view($this->module->name() . '::error-img-svg', [
                        'text'       => /*I18N: MD img error precondition */I18N::translate("Neither a media object nor a file from the public directory was specified."),
                        'classnames' => $classnames,
                    ]);                    
                }
                if (isset($params['id']) && isset($params['public'])) {
                    return view($this->module->name() . '::error-img-svg', [
                        'text'       => /*I18N: MD img error precondition */I18N::translate("Only one media object OR one file from the public directory can be specified."),
                        'classnames' => $classnames,
                    ]);
                }
               
                $width  = isset($params['w']) && preg_match('/^\d+$/', $params['w'], $match) ? intval($params['w']) : 200;
                $height = isset($params['h']) && preg_match('/^\d+$/', $params['h'], $match) ? intval($params['h']) : 200;

                if (isset($params['id'])) {
                //--- XREF - alt_text and title taken from mediaobject;
                //    in webtrees media title is used for a[data-title] and img[alt]
                    if (preg_match('/^@(\w+)@$/',$params['id'], $match)) {
                        $xref = $match[1];
                        $record = Registry::mediaFactory()->make($xref, $this->tree);
                        //null if not media?! XREF not for Media or not existent
                        if ($record instanceof Media) {
                            try {
                                $record = Auth::checkMediaAccess($record);
                            } catch (HttpAccessDeniedException $e) {
                                return view($this->module->name() . '::error-img-svg', [
                                    'text'       => $e->getMessage() . " - XREF $xref",
                                    'classnames' => $classnames,
                                ]);
                            }
                
                            $media_file = $record->firstImageFile();
                            if ($media_file instanceof MediaFile) {
                                return view($this->module->name() . '::md-img-media', [
                                    'media_file'      => $media_file,
                                    'classnames'      => $classnames,
                                    'width'           => $width,
                                    'height'          => $height,
                                    'titleclassnames' => $this->img_titleclassnames,
                                ]);
                            } else {
                                return view($this->module->name() . '::error-img-svg', [
                                    'text'       => /*I18N: MD img error crossreference */I18N::translate("There is no image file available for display.") . " - XREF $xref",
                                    'classnames' => $classnames,
                                ]);
                            }
                        } else {
                            return view($this->module->name() . '::error-img-svg', [
                                'text'       => /*I18N: webtrees.pot */I18N::translate('This media object does not exist or you do not have permission to view it.') . " - XREF $xref",
                                'classnames' => $classnames,
                            ]);
                        }
                    }
                } else { 
                //--- public file - alt_text and title are used
                //    alt_text for a[data-title] and img[alt] and visible picture title,
                //    title for img[title]
                    $title = preg_match('/title="([^"]+)"/',$imgmatch[0], $match) ? $match[1] : '';
                    $alt_text = preg_match('/alt="([^"]+)"/', $imgmatch[0], $match) ? $match[1] : '';

                    $public_relpath = $params['public'];
                    $public_basedir = realpath(Webtrees::ROOT_DIR . 'public');
                    if (!$public_basedir) { //(!file_exists($public_file))
                        return view($this->module->name() . '::error-img-svg', [
                            'text' => "public-Ordner existiert nicht",
                            'classnames' => $classnames,
                        ]);
                    }

                    $public_file = realpath($public_basedir . DIRECTORY_SEPARATOR . $public_relpath);

                    if (! $public_file) { //(!file_exists($public_file))
                        return view($this->module->name() . '::error-img-svg', [
                            'text' => /*I18N: MD img error public file, %s the relative filename */I18N::translate("File ‘%s’ does not exist in the public folder", $public_relpath),
                            'classnames' => $classnames,
                        ]);
                    }

                    if (! strstr($public_file, $public_basedir)) {
                        return view($this->module->name() . '::error-img-svg', [
                            'text' => /*I18N: MD img error public file */I18N::translate("Only files within the public folder are supported") . " - '$public_relpath'",
                            'classnames' => $classnames,
                        ]);
                    }
                    
                    $public_type = mime_content_type($public_file);
                    if (! strstr($public_type, 'image/')) {
                        return view($this->module->name() . '::error-img-svg', [
                            'text' => /*I18N: MD img error public file, %s1=rel. filename, %s2=mimetype */I18N::translate("File ‘%s’ in the public folder is not an image but '%s'", $public_relpath, $public_type),
                            'classnames' => $classnames,
                        ]);
                    }
                    
                    return view($this->module->name() . '::md-img-public', [
                        'public_url'      => $this->public_url . $public_relpath,
                        'mime_type'       => $public_type,
                        'classnames'      => $classnames,
                        'alt_text'        => $alt_text,
                        'title'           => $title,
                        'width'           => $width,
                        'height'          => $height,
                        'titleclassnames' => $this->img_titleclassnames,
                    ]);
                }

                return $imgmatch[0];
            },
            $html
        );

        return $html;
    }

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
        //++

        $converter = new MarkDownConverter($environment);

        $html = $converter->convert($markdown)->getContent();

        // The markdown convert adds newlines, but not in a documented way.  Safest to ignore them.
        $html =  strtr($html, ["\n" => '']); //return

        //++ 
        $html = $this->handleEnhancedImageSrc($html, $tree);

        return $html;
    }
    
} 