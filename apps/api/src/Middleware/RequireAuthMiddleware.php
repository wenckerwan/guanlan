<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Support\ApiResponse;
use App\Support\Auth;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 要求已登录，否则 401。
 */
class RequireAuthMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (! Auth::user()) {
            return ApiResponse::message('未登录或登录已失效', 401);
        }

        return $handler->handle($request);
    }
}
