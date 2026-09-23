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
 * 要求管理员，否则 401/403。
 */
class RequireAdminMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $user = Auth::user();
        if (! $user) {
            return ApiResponse::message('未登录或登录已失效', 401);
        }
        if (! $user->isAdmin()) {
            return ApiResponse::message('需要管理员权限', 403);
        }

        return $handler->handle($request);
    }
}
