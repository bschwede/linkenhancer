<?php

declare(strict_types=1);

namespace Schwendinger\Webtrees\Module\LinkEnhancer\Http\RequestHandlers;

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Validator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Schwendinger\Webtrees\Helpers\ClassName;
use Schwendinger\Webtrees\Helpers\MoreI18N;
use Schwendinger\Webtrees\Module\LinkEnhancer\LinkEnhancerModule;

use function response;

class DataFixBlocksAction implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (!Auth::isAdmin()) {
            $class = ClassName::get(ClassName::EXCEPTION_HTTP_FORBIDDEN);
            throw new $class(MoreI18N::xlate('Admin only action')); // in ModuleAction without translation
        }

        $tree   = Validator::attributes($request)->tree();
        $params = $request->getQueryParams();

        $module = Registry::container()->get(LinkEnhancerModule::class);
        $result = $module->dataFixDispatcher()->processBlocks($tree, $params);

        return response($result);
    }
}
