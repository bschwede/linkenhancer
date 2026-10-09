<?php

declare(strict_types=1);

namespace Schwendinger\Webtrees\Module\LinkEnhancer\DataFix;

use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\TreeService;
use Fisharebest\Webtrees\Tree;

trait TreeLookupTrait
{
    /** @var array<string, Tree|null> */
    private array $tree_cache = [];

    private function findTree(string $name): ?Tree
    {
        if (array_key_exists($name, $this->tree_cache)) {
            return $this->tree_cache[$name];
        }
        try {
            $tree = Registry::container()->get(TreeService::class)->all()->get($name);
            $this->tree_cache[$name] = $tree;
            return $tree;
        } catch (\Throwable) {
            $this->tree_cache[$name] = null;
            return null;
        }
    }

    private function findTreeById(int $tree_id): ?Tree
    {
        try {
            return Registry::container()->get(TreeService::class)->find($tree_id);
        } catch (\Throwable) {
            return null;
        }
    }
}
