<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Support\ApiResponse;
use App\Support\Auth;
use Hyperf\HttpServer\Contract\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 解析 Bearer 令牌并写入上下文；未登录不拦截（由 RequireAuthMiddleware 决定是否拒绝）。
 */
class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(private RequestInterface $request)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        Auth::setUser(Auth::resolve(Auth::tokenFrom($this->request)));

        return $handler->handle($request);
    }
}
