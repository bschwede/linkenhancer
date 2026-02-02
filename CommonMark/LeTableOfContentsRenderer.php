<?php

declare(strict_types=1);

/*
 * This file is part of the league/commonmark package.
 *
 * (c) Colin O'Dell <colinodell@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
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
