<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\QuestionService;
use App\Support\ApiResponse;
use Hyperf\HttpServer\Contract\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class StatsController
{
    public function __construct(
        private QuestionService $questions,
        private RequestInterface $request
    ) {
    }

    public function overview(): ResponseInterface
    {
        return ApiResponse::data([
            'modules' => $this->questions->moduleSummary(),
        ]);
    }
}
