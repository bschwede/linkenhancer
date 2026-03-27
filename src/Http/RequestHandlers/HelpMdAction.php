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
use Fisharebest\Webtrees\Validator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;


/**
 * Serve markdown and link++ help page (accessed by MDE command bar help icon) in given language
 */
class HelpMdAction implements RequestHandlerInterface
{
    /**
     * @param ServerRequestInterface $request
     *
     * @return ResponseInterface
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    { // referenced by javascript handler from top menu submenu "Webtrees manual"
        $prev_language_tag = Utils::checkRequestLanguage($request);
       
        $module = Registry::container()->get(LinkEnhancerModule::class);

        // resources/views/edit/shared-note.phtml doesn't include < ?= view('modals/ajax') ? >
        // see also app/Http/RequestHandlers/HelpText.php
        //$topic = $request->getAttribute('topic');
        $title = /*I18N: webtrees.pot */ I18N::translate('Help') . ' - Markdown';
        $mdsyntax = Utils::getMarkdownHelpExamples(
            Validator::attributes($request)->string('base_url'),
            $module->getPref(LinkEnhancerModule::PREF_MD_EXT_ACTIVE, true),
            $module->canActivateHighlightExtension(),
            $module->getPref(LinkEnhancerModule::PREF_MD_EXT_STRIKE_ACTIVE, true),
            $module->getPref(LinkEnhancerModule::PREF_MD_EXT_DL_ACTIVE, true),
            $module->getPref(LinkEnhancerModule::PREF_MD_EXT_FN_ACTIVE, true)
        );
        $text = view($module->name() . '::help-md', [
            'link_active' => $module->getPref(LinkEnhancerModule::PREF_LINKSPP_ACTIVE, true),
            'mdimg_active' => $module->getPref(LinkEnhancerModule::PREF_MD_IMG_ACTIVE, true),
            'mdsyntax' => $mdsyntax,
            'mdimg_css_class1' => $module->getPref(LinkEnhancerModule::PREF_MD_IMG_STDCLASS),
            'mdimg_css_class2' => $module->getPref(LinkEnhancerModule::PREF_MD_IMG_TITLE_STDCLASS)
        ]);

        $html = view('modals/help', [
            'title' => $title,
            'text' => $text,
        ]);

        if ($prev_language_tag !== '') { //reset language if necessary
            I18N::init($prev_language_tag);
        }

        return Utils::getCachedResponse($html);
    }
}
