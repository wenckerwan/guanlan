<?php

declare(strict_types=1);

namespace App\Exception\Handler;

use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\ExceptionHandler\ExceptionHandler;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Psr\Http\Message\ResponseInterface;
use Swow\Psr7\Message\ResponsePlusInterface;
use Throwable;

/**
 * 兜底异常处理器：记录错误日志并返回统一 JSON 500。
 *
 * 注意：基类是 Hyperf\ExceptionHandler\ExceptionHandler（3.1），
 * 不是 Hyperf\HttpServer\Exception\Handler\ExceptionHandler（该命名空间下只有 HttpExceptionHandler）。
 */
class AppExceptionHandler extends ExceptionHandler
{
    public function __construct(protected StdoutLoggerInterface $logger)
    {
    }

    /**
     * @param ResponsePlusInterface|ResponseInterface $response
     */
    public function handle(Throwable $throwable, $response)
    {
        $this->logger->error(sprintf(
            '%s[%s] in %s:%s',
            $throwable->getMessage(),
            $throwable->getCode(),
            $throwable->getFile(),
            $throwable->getLine()
        ));

        $this->stopPropagation();

        $payload = json_encode(
            ['message' => 'Internal Server Error'],
            JSON_UNESCAPED_UNICODE
        );

        if (method_exists($response, 'setStatus')) {
            return $response
                ->setStatus(500)
                ->setHeader('Content-Type', 'application/json; charset=utf-8')
                ->setBody(new SwooleStream($payload));
        }

        return $response
            ->withStatus(500)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withBody(new SwooleStream($payload));
    }

    public function isValid(Throwable $throwable): bool
    {
        return true;
    }
}
