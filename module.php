<?php

/**
 * LinkEnhancer
 */

declare(strict_types=1);

namespace Schwendinger\Webtrees\Module\LinkEnhancer;

require __DIR__ . '/CustomMarkdownFactory.php';
require __DIR__ . '/LinkEnhancerModule.php';
require __DIR__ . '/Schema/Migration0.php';
require __DIR__ . '/Schema/SeedHelpTable.php';

return new LinkEnhancerModule();