<?php

/**
 * webtrees: online genealogy
 * Copyright (C) 2026 webtrees development team
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace Schwendinger\Webtrees\Module\LinkEnhancer\Http\RequestHandlers;

use Schwendinger\Webtrees\Module\LinkEnhancer\LinkEnhancerModule;
use Schwendinger\Webtrees\Module\LinkEnhancer\LinkEnhancerUtils as Utils;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Session;
use Fisharebest\Webtrees\Validator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Exception;

/**
 * Serve help page for webtrees manual full-text search and table of contents
 * in given language (toc is not translated only headings and labels)
 */
class HelpWthbAction implements RequestHandlerInterface
{
    /**
     * @param ServerRequestInterface $request
     *
     * @return ResponseInterface
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    { // referenced by javascript handler from top menu submenu "Webtrees manual"
        $language_session = Session::get('language');
        $language = Validator::attributes($request)->string('language', $language_session);
        $language_tag = '';
        if (preg_match('/^[a-z]{2}(?:-[a-zA-Z]{1,10})?$/', $language) // only accept codes like "de-DE"
            && I18N::languageTag() !== $language
        ) {
            try {
                I18N::init($language);
                $language_tag = I18N::languageTag();
            } catch (Exception $e) { // language code not found - silently use session language
            }
        }

        $module = Registry::container()->get(LinkEnhancerModule::class);
        $title = /*I18N: webtrees.pot */ I18N::translate('Help') . ' - ' . I18N::translate('Webtrees manual');
        $tochtml = view($module->name() . '::help-wthb-toc');
        $text = view($module->name() . '::help-wthb', [
            'toc_url' => LinkEnhancerModule::STDLINK_WTHB_TOC,
            'toc_html' => $tochtml,
            'search' => Utils::getSearchEngines(),
        ]);

        $html = view('modals/help', [
            'title' => $title,
            'text' => $text,
        ]);

        if ($language_tag !== '') { //reset language if necessary
            I18N::init($language_tag);
        }

        return response($html)
            ->withHeader('Cache-Control', 'public, max-age=86400, immutable')
            ->withHeader('Expires', gmdate('D, d M Y H:i:s', time() + 86400) . ' GMT') // force caching for Firefox
            ->withHeader('ETag', md5($html));
    }
}
