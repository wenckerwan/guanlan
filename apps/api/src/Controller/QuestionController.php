<?php

declare(strict_types=1);

namespace App\Controller;

use App\Resource\QuestionResource;
use App\Service\QuestionService;
use App\Support\ApiResponse;
use Hyperf\HttpServer\Contract\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class QuestionController
{
    public function __construct(
        private QuestionService $service,
        private RequestInterface $request
    ) {
    }

    public function index(): ResponseInterface
    {
        $page = max(1, (int) $this->request->input('page', 1));
        $perPage = min(100, max(1, (int) $this->request->input('perPage', 20)));

        $result = $this->service->search(
            (string) $this->request->input('module', ''),
            (string) $this->request->input('type', ''),
            trim((string) $this->request->input('q', '')),
            ((int) $this->request->input('year', 0)) ?: null,
            $page,
            $perPage
        );

        return ApiResponse::data([
            'items' => QuestionResource::collection($result['items'], true),
            'total' => $result['total'],
            'page' => $page,
            'perPage' => $perPage,
        ]);
    }

    public function show(int $id): ResponseInterface
    {
        $question = $this->service->find($id);
        if (! $question) {
            return ApiResponse::message('题目不存在', 404);
        }

        $reveal = (string) $this->request->input('reveal', '1') !== '0';

        return ApiResponse::data(QuestionResource::make($question, $reveal));
    }
}
