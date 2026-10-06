<?php

declare(strict_types=1);

namespace Schwendinger\Webtrees\Module\LinkEnhancer\Http\RequestHandlers;

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Validator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Schwendinger\Webtrees\Helpers\ClassName;
use Schwendinger\Webtrees\Helpers\Functions;
use Schwendinger\Webtrees\Helpers\MoreI18N;
use Schwendinger\Webtrees\Module\LinkEnhancer\Services\IndexRebuildScheduler;

use function response;

class DataFixRebuildIndexAction implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (!Auth::isAdmin()) {
            $class = ClassName::get(ClassName::EXCEPTION_HTTP_FORBIDDEN);
            throw new $class(MoreI18N::xlate('Admin only action')); // in ModuleAction without translation
        }

        $tree  = Validator::attributes($request)->tree();
        $index = (string) ($request->getParsedBody()['index'] ?? '');

        if ($index === 'link') {
            IndexRebuildScheduler::defer(['link' => true, 'uid' => false], $tree->id());
        } elseif ($index === 'uid') {
            IndexRebuildScheduler::defer(['link' => false, 'uid' => true], $tree->id());
        } else {
            return response(['error' => 'invalid index'], Functions::httpStatusCode(400));
        }

        return response(['ok' => true, 'queued' => $index]);
    }
}
