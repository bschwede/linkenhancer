<?php
declare(strict_types=1);

namespace Schwendinger\Webtrees\Module\LinkEnhancer;

use Fisharebest\Webtrees\Webtrees;
use Schwendinger\Webtrees\Module\LinkEnhancer\LinkEnhancerModule;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\Factories\MarkdownFactory;
use Fisharebest\Webtrees\Validator;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Media;
use Fisharebest\Webtrees\MediaFile;
use Psr\Http\Message\ServerRequestInterface;
use Fisharebest\Webtrees\Http\Exceptions\HttpAccessDeniedException;

class CustomMarkdownFactory extends MarkdownFactory {

    private null|Tree $tree;

    private string $public_url;

    private LinkEnhancerModule $module;


    public function __construct($module) {
        $this->module = $module;
    }

    /**
     * Find <img src="#@">-Tags and replace it with reference to corresponding media object in tree. Return html string.
     *
     * Syntax:
     * - wt Media   : ![img alt text](#@id=@XREF@&w=100&h=200&cname=css-classname1+css-classname2 "img title")
     * - Public-file: ![img alt text](#@public=relpath/file.jpg&w=100&h=200&cname=css-classname1+css-classname2 "img title")
     * 
     * @param string $html
     *
     * @return string
     */
    public function handleEnhancedImageSrc(string $html): string
    {
        $request = Registry::container()->get(ServerRequestInterface::class);
        $this->tree = Validator::attributes($request)->tree();
        $base_url = Validator::attributes($request)->string('base_url');
        $this->public_url = $base_url . '/public/';

        $html = preg_replace_callback(
            '/<img[^>]*src="#@([^"]+)"[^>]*>/', 
            function ($imgmatch) {
                // 1: src-value without hash marker
                $hashvalue = htmlspecialchars_decode($imgmatch[1]);

                parse_str($hashvalue, $params);
                //return "<pre>$hashvalue\n" . print_r($params, true) . "</pre>";
                $classnames = isset($params['cname']) ? explode(' ',urldecode($params['cname'])) : [];
                $stdclassnames = explode(' ', $this->module->getPreference($this->module::PREF_MD_IMG_STDCLASS, $this->module::STDCLASS_MD_IMG));
                $classnames = array_merge($classnames, $stdclassnames);
                $classnames = implode(' ', array_unique($classnames));

                if (!isset($params['id']) && !isset($params['public'])) {
                    return view($this->module->name() . '::error-img-svg', [
                        'text'       => "Es wurde weder ein Medienobjekt noch eine Datei aus dem public-Verzeichnis angegeben.",
                        'classnames' => $classnames,
                    ]);                    
                }
                if (isset($params['id']) && isset($params['public'])) {
                    return view($this->module->name() . '::error-img-svg', [
                        'text'       => "Es kann nur ein Medienobjekt ODER eine Datei aus dem public-Verzeichnis angegeben werden.",
                        'classnames' => $classnames,
                    ]);
                }
               
                $width  = isset($params['w']) && preg_match('/^\d+$/', $params['w'], $match) ? intval($params['w']) : 200;
                $height = isset($params['h']) && preg_match('/^\d+$/', $params['h'], $match) ? intval($params['h']) : 200;

                if (isset($params['id'])) {
                //--- XREF - alt_text and title taken from mediaobject

                    if (preg_match('/^@(\w+)@$/',$params['id'], $match)) {
                        $xref = $match[1];
                        $record = Registry::mediaFactory()->make($xref, $this->tree);
                        //null if not media?! XREF not for Media or not existent
                        if ($record instanceof Media) {
                            try {
                                $record = Auth::checkMediaAccess($record);
                            } catch (HttpAccessDeniedException $e) {
                                return view($this->module->name() . '::error-img-svg', [
                                    'text'       => "XREF $xref - " . $e->getMessage(),
                                    'classnames' => $classnames,
                                ]);
                            }
                
                            $media_file = $record->firstImageFile();
                            if ($media_file instanceof MediaFile) {
                                return view($this->module->name() . '::md-img-media', [
                                    'media_file' => $media_file,
                                    'classnames' => $classnames,
                                    'width'      => $width,
                                    'height'     => $height,
                                ]);
                            } else {
                                return view($this->module->name() . '::error-img-svg', [
                                    'text'       => "XREF $xref - keine Bilddatei zur Anzeige vorhanden",
                                    'classnames' => $classnames,
                                ]);
                            }
                        } else {
                            return view($this->module->name() . '::error-img-svg', [
                                'text'       => "XREF $xref ungültig oder Medienobjekt nicht mehr existent", //$message = I18N::translate('This media object does not exist or you do not have permission to view it.');
                                'classnames' => $classnames,
                            ]);
                        }
                    }
                } else { 
                //--- public file - alt_text and title effective
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

                    $public_file = realpath("$public_basedir/" . $public_relpath);

                    if (! $public_file) { //(!file_exists($public_file))
                        return view($this->module->name() . '::error-img-svg', [
                            'text' => "Datei '$public_relpath' existiert nicht im public-Ordner - basedir=$public_basedir file=$public_file",
                            'classnames' => $classnames,
                        ]);
                    }

                    if (! strstr($public_file, $public_basedir)) {
                        return view($this->module->name() . '::error-img-svg', [
                            'text' => "Es werden nur Dateien innerhalb des public-Ordners unterstützt - '$public_relpath'",
                            'classnames' => $classnames,
                        ]);
                    }
                    
                    $public_type = mime_content_type($public_file);
                    if (! strstr($public_type, 'image/')) {
                        return view($this->module->name() . '::error-img-svg', [
                            'text' => "Datei '$public_relpath' im public-Ordner ist kein Bild sondern '$public_type'",
                            'classnames' => $classnames,
                        ]);
                    }
                    
                    return view($this->module->name() . '::md-img-public', [
                        'public_url' => $this->public_url . $public_relpath,
                        'mime_type'  => $public_type,
                        'classnames' => $classnames,
                        'alt_text'   => $alt_text,
                        'title'      => $title,
                        'width'      => $width,
                        'height'     => $height,
                    ]);
                }

                return $imgmatch[0];
            },
            $html
        );

        return $html;
    }

    public function markdown(string $markdown, Tree|null $tree = null): string
    {
        $html = parent::markdown($markdown, $tree);
        
        $html = $this->handleEnhancedImageSrc($html);

        return $html;
    }
    
} 