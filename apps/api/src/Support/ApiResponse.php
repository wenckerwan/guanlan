<?php

declare(strict_types=1);

namespace App\Support;

use Hyperf\HttpMessage\Stream\SwooleStream;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Psr\Http\Message\ResponseInterface as PsrResponse;

/**
 * 统一响应构造：成功 `{data: ...}`、失败 `{message, errors?}`。
 */
class ApiResponse
{
    public static function json(array $payload, int $status = 200): PsrResponse
    {
        $response = \Hyperf\Context\ApplicationContext::getContainer()
            ->get(ResponseInterface::class);

        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withBody(new SwooleStream(json_encode($payload, JSON_UNESCAPED_UNICODE)));
    }

    public static function data(mixed $data, int $status = 200): PsrResponse
    {
        return self::json(['data' => $data], $status);
    }

    public static function message(string $message, int $status, array $errors = []): PsrResponse
    {
        $payload = ['message' => $message];
        if ($errors !== []) {
            $payload['errors'] = $errors;
        }
        return self::json($payload, $status);
    }
}
