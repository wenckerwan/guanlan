<?php
declare(strict_types=1);
namespace Mayuan;
final class SecureCookieMiddleware implements \Psr\Http\Server\MiddlewareInterface {
 public function process(\Psr\Http\Message\ServerRequestInterface $request,\Psr\Http\Server\RequestHandlerInterface $handler):\Psr\Http\Message\ResponseInterface{$response=$handler->handle($request);$cookies=$response->getHeader('Set-Cookie');if(!$cookies)return $response;foreach($cookies as &$cookie){$cookie=preg_replace('/;\s*Domain=[^;]*/i','',$cookie);if(getenv('MAYUAN_MODE')!=='development'&&!preg_match('/;\s*Secure(?:;|$)/i',$cookie))$cookie.='; Secure';}return $response->withHeader('Set-Cookie',$cookies);}
}
