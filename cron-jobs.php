<?php

declare(strict_types=1);

// manifest is sourced by cronjob module
use Schwendinger\Webtrees\Helpers\MoreI18N;

return [
    'jobs' => [
        [
            'name'         => 'link-index',
            'title'        => MoreI18N::translate('Update the link index'),
            'triggers' => [
                ['type' => 'time', 'cron' => '*/5 * * * *'],
                ['type' => 'event', 'event' => 'linkenhancer:index-dirty'], // listen to your own event
            ],
            'command_type' => 'module',
            'command'      => 'modules_v4/linkenhancer/cli/build-link-index.php',
            'args'         => '--limit=5000',
            // 'enabled'      => false,  // default: created disabled (opt-in)
            // 'timeout_sec'  => 300,    // default
        ],
        [
            'name'         => 'uid-index',
            'title'        => MoreI18N::translate('Update the UID index'),
            'triggers' => [
                ['type' => 'time', 'cron' => '*/10 * * * *'],
                ['type' => 'event', 'event' => 'linkenhancer:uid-index-dirty'],
            ],
            'command_type' => 'module',
            'command'      => 'modules_v4/linkenhancer/cli/build-uid-index.php',
            'args'         => '--limit=5000',
        ],
        [
            'name'         => 'refresh-wthb-toc',
            'title'        => MoreI18N::translate('Refresh Webtrees manual table of contents translations'),
            'triggers'     => [
                ['type' => 'time', 'cron' => '0 3 * * *'],
            ],
            'command_type' => 'module',
            'command'      => 'modules_v4/linkenhancer/cli/refresh-wthb-toc.php',
            'args'         => '',
            'timeout_sec'  => 60,
            'enabled'      => false,
        ],
    ],
    'commands' => [
        [
            'command' => 'modules_v4/linkenhancer/cli/build-link-index.php',
            // 'command_type' => 'module',  // optional - derived from the command value
            'description' => MoreI18N::translate('Rebuild the link index incrementally.'),
            'params' => [
                ['name' => '--limit', 'optional' => true, 'default' => '5000', 'description' => MoreI18N::translate('max records per run')],
                ['name' => '--tree=ID', 'optional' => true, 'description' => MoreI18N::translate('number of the tree to be scanned - all if not set')],
                ['name' => '--rebuild', 'optional' => true, 'description' => MoreI18N::translate('force a full rebuild')],
                ['name' => '--flush', 'optional' => true, 'description' => MoreI18N::translate('empty the index and exit')],
            ],
        ],
        [
            'command' => 'modules_v4/linkenhancer/cli/build-uid-index.php',
            'description' => MoreI18N::translate('Rebuild the UID index incrementally.'),
            'params' => [
                ['name' => '--limit', 'optional' => true, 'default' => '5000', 'description' => MoreI18N::translate('max records per run')],
                ['name' => '--tree=ID', 'optional' => true, 'description' => MoreI18N::translate('number of the tree to be scanned - all if not set')],
                ['name' => '--rebuild', 'optional' => true, 'description' => MoreI18N::translate('force a full rebuild')],
                ['name' => '--flush', 'optional' => true, 'description' => MoreI18N::translate('empty the index and exit')],
            ],
        ],
        [
            'command'      => 'modules_v4/linkenhancer/cli/refresh-wthb-toc.php',
            'command_type' => 'module',
            'description'  => MoreI18N::translate('Download/refresh WTHB TOC translations from GitHub Pages. Scope is read from the module settings (all / active / custom).'),
            'params'       => [
                [
                    'name'        => '--all',
                    'optional'    => true,
                    'description' => MoreI18N::translate('Override: download all available languages.'),
                ],
                [
                    'name'        => '--lang=...',
                    'optional'    => true,
                    'description' => MoreI18N::translate('Override: download specific languages (comma-separated).'),
                ],
                [
                    'name'        => '--clear',
                    'optional'    => true,
                    'description' => MoreI18N::translate('Only clear the cache, no download.'),
                ],
            ],
        ],
        [
            'command'      => 'modules_v4/linkenhancer/cli/validate-wthb-toc.php',
            'command_type' => 'module',
            'description'  => MoreI18N::translate('Validate locally cached WTHB TOC data: schema check + coverage report.'),
            'params'       => [],
        ],
    ],
    'events' => [
        ['name' => 'index-dirty', 'description' => MoreI18N::translate('The link index is out of date.'), 'payload' => []],
        ['name' => 'uid-index-dirty', 'description' => MoreI18N::translate('The UID index is out of date.'), 'payload' => []],
    ],
];
