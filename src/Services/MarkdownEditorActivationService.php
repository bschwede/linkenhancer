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

namespace Schwendinger\Webtrees\Module\LinkEnhancer\Services;

use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\Validator;
use Fisharebest\Webtrees\Webtrees;
use Fisharebest\Webtrees\Services\ModuleService;
use Psr\Http\Message\ServerRequestInterface;
use Schwendinger\Webtrees\Module\LinkEnhancer\LinkEnhancerModule;
use Schwendinger\Webtrees\Module\LinkEnhancer\SettingInterface;


final class MarkdownEditorActivationService
{
    private array $rules = [];

    
    public function __construct(private SettingInterface $storage)
    {
        $this->rules = $this->storage->loadSetting(self::class);
    }

    /**
     * Standard Rules for webtrees core
     *
     * @return array
     */
    public function getDefaultRules() : array
    {
        $default = [
            'handler' => [ // wt core edit pages
                'EditFactPage',
                'EditMainFieldsPage',
                'EditNotePage',
                'EditRecordPage',
                'AddChildToFamilyPage',
                'AddChildToIndividualPage',
                'AddNewFact',
                'AddParentToIndividualPage',
                'AddSpouseToFamilyPage',
                'AddSpouseToIndividualPage',
                'AddUnlinkedPage',                
            ],
            'filter' => [ // elements the mde should be applied to
                "textarea[id$='NOTE']",
                "textarea[id$='note']",
            ]
        ];
        if (version_compare(Webtrees::VERSION, '2.2.5', '>=')) {
            $default['filter'][] = "textarea[id$='_TODO']";
        }
        return $default;
    }

    /**
     * Set or delete ruleset for cutsom module
     * @param string $key      custom module name
     * @param array $handler   last name part of handler class name for edit page
     * @param array $filter    element selector expression(s)
     * @return void
     */
    public function setCustomRule(string $key, array $handler = [], array $filter = []): void
    {
        if ($handler === [] && $filter === []) {
            unset($this->rules['custom'][$key]);
        } else {
            $this->rules['custom'][$key] = [
                'handler' => array_values($handler),
                'filter' => array_values($filter),
            ];
        }

        $this->rules['effective'] = $this->getEffectiveRules(true);
        //$this->storage->saveSetting(self::class, $this->rules); //not necessary - persistence already with getEffectiveRules(true)
    }

    /**
     * get rule set for custom module
     * @param string $key
     * @return array
     */
    public function getCustomRule(string $key): array
    {
        return $this->rules['custom'][$key] ?? [];
    }

    /**
     * merged array of standard and custom rules
     * @param bool $force    force merging rules, otherwise if set the cached array will be returned
     * @return array{handler: array<string>, filter: array<string>}
     */
    public function getEffectiveRules(bool $force = false): array
    {
        if ($force || !isset($this->rules['effective'])) {
            $merged = $this->getDefaultRules();

            foreach (($this->rules['custom'] ?? []) as $item) {
                $merged['handler'] = array_values(array_unique(array_merge($merged['handler'], $item['handler'])));
                $merged['filter'] = array_values(array_unique(array_merge($merged['filter'], $item['filter'])));
            }

            $this->rules['effective'] = $merged;
            $this->storage->saveSetting(self::class, $this->rules);
        }

        return $this->rules['effective'];
    }

    public function getAllRules(): array
    {
        return $this->rules;
    }

    /**
     * is it a request for an edit page
     *
     * @param ServerRequestInterface $request
     * @return bool
     */
    public function isEditPage(ServerRequestInterface|null $request = null): bool
    {
        if (!$request) {
            $request = Registry::container()->get(ServerRequestInterface::class);
        }
        $route = Validator::attributes($request)->route();
        $routename = basename(strtr($route->name ?? '/', ['\\' => '/']));

        $handler = $this->getEffectiveRules()['handler'];

        return in_array($routename, $handler);
    }

    /**
     * Element filter for querySelector
     * @return string
     */
    public function getElementFilter():string
    {
        $filter = $this->getEffectiveRules()['filter'];
        return implode(', ', $filter);
    }

    /**
     * check if all conditions are met to apply the md editor
     * @return string empty '' if it should be enabled, otherwise localized text info
     */    
    public function adminDiagnostics(null|Tree $tree = null) : string {
        $module_service = Registry::container()->get(ModuleService::class);
        /**
         * @var  LinkEnhancerModule $linkenhancer
         */
        $linkenhancer = $module_service->findByName('_linkenhancer_', true);

        if (!($linkenhancer !== null && $linkenhancer->isEnabled())) {
            return /*I18N: webtrees.pot */ I18N::translate('The module “%s” has been disabled.', $linkenhancer->title());
        } 

        if (!$linkenhancer->getPref(LinkEnhancerModule::PREF_MD_ACTIVE, true)) {
            return I18N::translate('Option is disabled') . ': ' . I18N::translate('Markdown enhancements');
        }

        if (!$linkenhancer->getPref(LinkEnhancerModule::PREF_MDE_ACTIVE, true)) {
            return I18N::translate('Option is disabled') . ': ' . I18N::translate('Markdown editor for note textareas');
        }

        if ($tree) {
            if ($tree->getPreference('FORMAT_TEXT') !== 'markdown') {
                return /*I18N: MDE */ I18N::translate("Precondition: Tree preference '%s' need to be set to markdown.", /*I18N: webtrees.pot */ I18N::translate('Format text and notes'));
            }
        }
        
        return '';
    }
    
}