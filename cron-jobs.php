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
    ],
    'commands' => [
        [
            'command' => 'modules_v4/linkenhancer/cli/build-link-index.php',
            // 'command_type' => 'module',  // optional - derived from the command value
            'description' => MoreI18N::translate('Rebuild the link index incrementally.'),
            'params' => [
                ['name' => '--limit', 'optional' => true, 'default' => '5000', 'description' => MoreI18N::translate('max records per run')],
                ['name' => '--since', 'optional' => true, 'description' => MoreI18N::translate('only changes since this timestamp')],
            ],
        ],
    ],
    'events' => [
        ['name' => 'index-dirty', 'description' => MoreI18N::translate('The link index is out of date.'), 'payload' => []],
    ],    
];