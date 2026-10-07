<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\VisitService;
use App\Support\ApiResponse;
use Hyperf\HttpServer\Contract\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;
use function Hyperf\Support\env;

class VisitController
{
    private const COOKIE = 'guanlan_browser';

    public function __construct(private VisitService $service, private RequestInterface $request)
    {
    }

    public function overview(): ResponseInterface
    {
        if ($this->request->getHeaderLine('Sec-Fetch-Site') === 'cross-site') {
            return $this->error(403, '访问来源无效');
        }
        try {
            $response = ApiResponse::data($this->service->overview())->withHeader('Cache-Control', 'no-store');
            if ($this->browserId() === null) {
                $secure = env('APP_ENV', 'production') === 'production' ? '; Secure' : '';
                $response = $response->withHeader('Set-Cookie', self::COOKIE . '=' . bin2hex(random_bytes(32))
                    . '; Path=/; Max-Age=31536000; HttpOnly; SameSite=Lax' . $secure);
            }
            return $response;
        } catch (Throwable) {
            return $this->error(503, '访问统计暂不可用');
        }
    }

    public function record(): ResponseInterface
    {
        if ($this->request->getHeaderLine('Sec-Fetch-Site') === 'cross-site') {
            return $this->error(403, '访问来源无效');
        }
        $id = $this->browserId();
        if ($id === null) {
            return $this->error(428, '请先初始化浏览器标识');
        }
        try {
            return ApiResponse::data($this->service->record($id))->withHeader('Cache-Control', 'no-store');
        } catch (Throwable) {
            return $this->error(503, '访问统计暂不可用');
        }
    }

    private function browserId(): ?string
    {
        $id = $this->request->cookie(self::COOKIE);
        return is_string($id) && preg_match('/\A[a-f0-9]{64}\z/', $id) === 1 ? $id : null;
    }

    private function error(int $status, string $message): ResponseInterface
    {
        return ApiResponse::message($message, $status)->withHeader('Cache-Control', 'no-store');
    }
}
