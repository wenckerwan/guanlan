<?php

declare(strict_types=1);

namespace App\Exception\Handler;

use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Hyperf\HttpServer\Exception\Handler\ExceptionHandler;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * 兜底异常处理器：记录错误日志并返回统一 JSON 500。
 */
class AppExceptionHandler extends ExceptionHandler
{
    public function __construct(protected StdoutLoggerInterface $logger)
    {
    }

    public function handle(Throwable $throwable, ResponseInterface $response): ResponseInterface
    {
        $this->logger->error(sprintf(
            '%s[%s] in %s:%s',
            $throwable->getMessage(),
            $throwable->getCode(),
            $throwable->getFile(),
            $throwable->getLine()
        ));

        $this->stopPropagation();

        return $response
            ->withStatus(500)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withBody(new SwooleStream(json_encode(
                ['message' => 'Internal Server Error'],
                JSON_UNESCAPED_UNICODE
            )));
    }

    public function isValid(Throwable $throwable): bool
    {
        return true;
    }
}
