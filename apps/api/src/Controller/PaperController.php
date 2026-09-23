<?php

declare(strict_types=1);

namespace App\Controller;

use App\Resource\PaperResource;
use App\Resource\QuestionResource;
use App\Service\QuestionService;
use App\Support\ApiResponse;
use Hyperf\HttpServer\Contract\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class PaperController
{
    public function __construct(
        private QuestionService $service,
        private RequestInterface $request
    ) {
    }

    public function index(): ResponseInterface
    {
        $year = (int) $this->request->input('year', 0);
        $module = (string) $this->request->input('module', '');
        $keyword = trim((string) $this->request->input('q', ''));

        $papers = $this->service->papers($year ?: null, $module, $keyword);

        return ApiResponse::data(PaperResource::collection($papers));
    }

    public function show(string $pid): ResponseInterface
    {
        $paper = $this->service->paper($pid);
        if (! $paper) {
            return ApiResponse::message('试卷不存在', 404);
        }

        $reveal = (string) $this->request->input('reveal', '1') !== '0';

        return ApiResponse::data([
            'paper' => PaperResource::make($paper),
            'questions' => QuestionResource::collection($this->service->paperQuestions($pid), $reveal),
        ]);
    }

    public function modules(): ResponseInterface
    {
        return ApiResponse::data($this->service->moduleSummary());
    }
}
