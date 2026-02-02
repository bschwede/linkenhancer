<?php

declare(strict_types=1);

/*
 * base code taken from: vendor/league/commonmark/src/Extension/TableOfContents/TableOfContentsRenderer.php
 * This file is part of the league/commonmark package.
 *
 * (c) Colin O'Dell <colinodell@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
------
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

//namespace League\CommonMark\Extension\TableOfContents;
namespace Schwendinger\Webtrees\Module\LinkEnhancer\CommonMark;

use Schwendinger\Webtrees\Module\LinkEnhancer\LinkEnhancerModule;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;
use League\CommonMark\Xml\XmlNodeRendererInterface;


final class LeTableOfContentsRenderer implements NodeRendererInterface, XmlNodeRendererInterface
{
    /**
     * @psalm-param NodeRendererInterface&XmlNodeRendererInterface $innerRenderer
     *
     * @phpstan-param NodeRendererInterface&XmlNodeRendererInterface $innerRenderer
     */
    public function __construct(
        private readonly NodeRendererInterface $innerRenderer,
        private readonly LinkEnhancerModule $module,
        private array $config
    )
    {
    }


    /**
     * {@inheritDoc}
     */
    public function render(Node $node, ChildNodeRendererInterface $childRenderer)
    {
        $html = $this->innerRenderer->render($node, $childRenderer);
        
        if (($this->config['dropdown'] ?? false) 
            && !is_null($html) 
            && ((string) $html !== '')
        ) {
            $html = view($this->module->name() . '::md-toc-dropdown', [
                'html' => (string) $html
            ]);
        }

        return $html;
    }

    public function getXmlTagName(Node $node): string
    {
        return 'table_of_contents';
    }

    /**
     * @return array<string, scalar>
     */
    public function getXmlAttributes(Node $node): array
    {
        return $this->innerRenderer->getXmlAttributes($node);
    }
}
