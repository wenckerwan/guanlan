<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\PracticeStudyService;
use App\Support\ApiResponse;
use App\Support\Auth;
use Hyperf\HttpServer\Contract\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

class StudyPracticeController
{
    public function __construct(private PracticeStudyService $service, private RequestInterface $request) {}

    public function submit(): ResponseInterface
    {
        try {
            $result = $this->service->submit(Auth::user(), $this->request->all());
            return ApiResponse::data($result, $result['duplicate'] ? 200 : 201);
        } catch (RuntimeException $error) {
            return ApiResponse::message($error->getMessage(), $error->getCode() ?: 500);
        }
    }

    public function state(string $slug): ResponseInterface
    {
        try {
            return ApiResponse::data($this->service->state(Auth::user(), $slug));
        } catch (RuntimeException $error) {
            return ApiResponse::message($error->getMessage(), $error->getCode() ?: 500);
        }
    }

    public function summary(): ResponseInterface
    {
        return ApiResponse::data($this->service->summary(Auth::user()));
    }

    public function history(): ResponseInterface
    {
        $filters = [];
        foreach (['bankId', 'page', 'perPage'] as $key) {
            $value = $this->request->input($key);
            if (! is_scalar($value) && $value !== null) {
                return ApiResponse::message('查询参数无效', 422);
            }
            $filters[$key] = $value;
        }
        return ApiResponse::data($this->service->history(Auth::user(), $filters));
    }
}
