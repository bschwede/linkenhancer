<?php

declare(strict_types=1);

namespace Schwendinger\Webtrees\Module\LinkEnhancer\Http\RequestHandlers;

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Validator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Schwendinger\Webtrees\Module\LinkEnhancer\LinkEnhancerModule;

use function response;

class DataFixBlocksAction implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (!Auth::isAdmin()) {
            return response('Forbidden', 403);
        }

        $tree   = Validator::attributes($request)->tree();
        $params = $request->getQueryParams();

        $module = Registry::container()->get(LinkEnhancerModule::class);
        $result = $module->dataFixDispatcher()->processBlocks($tree, $params);

        return response($result);
    }
}
