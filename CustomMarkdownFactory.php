<?php
declare(strict_types=1);

namespace Schwendinger\Webtrees;

use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\Factories\MarkdownFactory;
use Fisharebest\Webtrees\Validator;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Media;
use Fisharebest\Webtrees\MediaFile;
use Psr\Http\Message\ServerRequestInterface;

class CustomMarkdownFactory extends MarkdownFactory {

    private null|Tree $tree;

    /**
     * Find <img src="#@">-Tags and replace it with reference to corresponding media object in tree. Return html string.
     *
     * @param string $html
     *
     * @return string
     */
    public function handleEnhancedImageSrc(string $html): string
    {
        $request = Registry::container()->get(ServerRequestInterface::class);
        $this->tree = Validator::attributes($request)->tree();

        $html = preg_replace_callback(
            '/<img([^>]*)src="#(@[^"]+)"([^>]*)>/', 
            function ($imgmatch) {
                // 1: pre src, 2: src-value, 3: post-src
                $hashvalue = $imgmatch[2];
                if (preg_match('/^@(\w+)@/',$hashvalue, $match)) {
                    $xref = $match[1];
                    $record = Registry::mediaFactory()->make($xref, $this->tree);
                    //null if not media?! XREF not for Media or not existent
                    if ($record instanceof Media) {
                        $record = Auth::checkMediaAccess($record);
            
                        $media_file = $record->firstImageFile();
                        if ($media_file instanceof MediaFile) {
                            return "<div>" . 
                                $media_file->displayImage(200, 200, 'contain', ['class' => 'img-thumbnail img-fluid'])
                                . '<br><a href="' . e($record->url()) . '">' . $record->fullName() . '</a>'
                                . "</div>";
                            //
                        } else {
                            return view(__DIR__ . '::error-img-svg', [
                                'text' => "XREF $xref - keine Bilddatei zur Anzeige vorhanden",
                            ]);
                        }
                    } else {
                        return view(__DIR__ . '::error-img-svg', [
                            'text'              => "XREF $xref ungültig oder Medienobjekt nicht mehr existent",
                        ]);                        
                    }
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