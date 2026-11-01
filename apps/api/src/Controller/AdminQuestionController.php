<?php
declare(strict_types=1);

namespace App\Controller;

use App\Resource\QuestionResource;
use App\Service\AdminQuestionService;
use App\Support\ApiResponse;
use Hyperf\HttpServer\Contract\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class AdminQuestionController
{
    public function __construct(private AdminQuestionService $service, private RequestInterface $request) {}

    public function show(int $id): ResponseInterface
    {
        return $this->respond(fn () => $this->service->detail($id));
    }

    public function create(string $pid): ResponseInterface
    {
        return $this->respond(fn () => QuestionResource::make($this->service->create(rawurldecode($pid), $this->request->all())), 201);
    }

    public function update(int $id): ResponseInterface
    {
        return $this->respond(fn () => QuestionResource::make($this->service->update($id, $this->request->all())));
    }

    private function respond(callable $operation, int $status = 200): ResponseInterface
    {
        try { return ApiResponse::data($operation(), $status); }
        catch (\Throwable $e) {
            $code = $e instanceof \RuntimeException && in_array($e->getCode(), [403, 404, 409, 422], true) ? $e->getCode() : 500;
            return ApiResponse::message($code === 500 ? '题目操作失败' : $e->getMessage(), $code);
        }
    }
}
