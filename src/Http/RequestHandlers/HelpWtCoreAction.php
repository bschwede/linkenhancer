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
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;


/**
 * Serve overview for webtrees core context help in given language
 */
class HelpWtCoreAction implements RequestHandlerInterface
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
        
        $title = /*I18N: webtrees.pot */ I18N::translate('Help')
            . ' - '
            . I18N::translate("webtrees help topics (included)");
        $text = view($module->name() . '::help-wt-helptext');

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
