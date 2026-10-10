<?php

declare(strict_types=1);

namespace Schwendinger\Webtrees\Module\LinkEnhancer\Services;

use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\TreeService;
use Schwendinger\Webtrees\Module\LinkEnhancer\LinkEnhancerModule;

use function count;
use function file_exists;
use function file_get_contents;
use function print_r;
use function route;
use function strval;

/**
 * Builds the data array for the admin settings page.
 * Extracted from LinkEnhancerModule::getInitializedOptions().
 */
final class AdminSettingsBuilder
{
    public function __construct(private readonly LinkEnhancerModule $module) {}

    public function build(): array
    {
        $module = $this->module;
        $response = [];

        $response['title'] = $module->title();
        $response['description'] = $module->description();

        $preferences = array_keys(LinkEnhancerModule::PREFERENCES_SCHEMA);
        foreach ($preferences as $preference) {
            $response['prefs'][$preference] = $module->getPref($preference);
        }

        $jsfile = $module->resourcesFolder() . 'js' . DIRECTORY_SEPARATOR . 'bundle-le-config.js';
        $jscode = '';
        if (file_exists($jsfile)) {
            $jscode = strval(file_get_contents($jsfile));
        }
        $response['jscode_linkpp'] = $jscode;

        $response['links'] = [];
        $response['links']['csvexport'] = route('module', [
            'module' => $module->name(),
            'action' => 'AdminCsvExport'
        ]);
        $response['links']['csvimport'] = route('module', [
            'module' => $module->name(),
            'action' => 'AdminCsvImport'
        ]);
        $response['links']['routeimport'] = route('module', [
            'module' => $module->name(),
            'action' => 'AdminImportRoutes'
        ]);
        $response['links']['resetroutes'] = route('module', [
            'module' => $module->name(),
            'action' => 'AdminResetRoutes'
        ]);
        $response['links']['csvexportcmm'] = ($module->wthb()->isCmmAvailable() ?
            route('module', [
                'module' => $module->name(),
                'action' => 'AdminCmmConfig2Csv'
            ])
            : ''
        );
        $response['links']['resetaccess_params'] = [
            'module' => $module->name(),
            'action' => 'AdminResetAccessOverwrites'
        ];

        $response['tablerows'] = $module->wthb()->getHelpTableCount();

        $tree_service = Registry::container()->get(TreeService::class);

        $trees = $tree_service->all();
        $trees_w_md = [];
        $trees_w_text = [];
        foreach ($trees as $tree) {
            if ($tree->getPreference('FORMAT_TEXT') === 'markdown') {
                $trees_w_md[] = $tree->name();
            } else {
                $trees_w_text[] = $tree->name();
            }
        }
        $cntTotal = count($trees);
        $response['mdcfg'] = [
            'total'        => $cntTotal,
            'activated'    => count($trees_w_md),
            'trees_w_md'   => $trees_w_md,
            'trees_w_text' => $trees_w_text
        ];

        $response['vesta_common_enabled'] = $module->vesta_common_enabled;

        $response['uid_index_status'] = UidIndexService::indexStatus();

        $mde_rules = $module->mde()->getAllRules();
        $response['mde_custom'] = $mde_rules['custom'] ?? false ? print_r($mde_rules['custom'], true) : '';

        return $response;
    }
}
