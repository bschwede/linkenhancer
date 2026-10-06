<?php

declare(strict_types=1);

namespace Schwendinger\Webtrees\Module\LinkEnhancer\Http\RequestHandlers;

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Validator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Schwendinger\Webtrees\Module\LinkEnhancer\Services\IndexRebuildScheduler;

use function response;

class DataFixRebuildIndexAction implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (!Auth::isAdmin()) {
            return response('Forbidden', 403);
        }

        $tree  = Validator::attributes($request)->tree();
        $index = (string) ($request->getParsedBody()['index'] ?? '');

        if ($index === 'link') {
            IndexRebuildScheduler::defer(['link' => true, 'uid' => false], $tree->id());
        } elseif ($index === 'uid') {
            IndexRebuildScheduler::defer(['link' => false, 'uid' => true], $tree->id());
        } else {
            return response(['error' => 'invalid index'], 400);
        }

        return response(['ok' => true, 'queued' => $index]);
    }
}
