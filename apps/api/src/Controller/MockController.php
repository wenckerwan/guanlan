<?php

declare(strict_types=1);

namespace App\Controller;

use App\Resource\MockResource;
use App\Service\MockService;
use App\Support\ApiResponse;
use Hyperf\HttpServer\Contract\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class MockController
{
    public function __construct(
        private MockService $service,
        private RequestInterface $request
    ) {
    }

    public function index(): ResponseInterface
    {
        return ApiResponse::data(MockResource::collection($this->service->list()));
    }

    public function show(string $slug): ResponseInterface
    {
        $mock = $this->service->findBySlug($slug);
        if (! $mock) {
            return ApiResponse::message('模拟卷不存在', 404);
        }

        $reveal = (string) $this->request->input('reveal', '1') !== '0';

        return ApiResponse::data([
            'mock' => MockResource::make($mock),
            'questions' => MockResource::questions($this->service->questions((int) $mock->id), $reveal),
        ]);
    }
}
