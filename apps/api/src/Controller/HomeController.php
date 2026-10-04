<?php

declare(strict_types=1);

namespace App\Controller;

use App\Resource\DocumentResource;
use App\Resource\SubjectSummaryResource;
use App\Service\HomeService;
use App\Support\ApiResponse;
use Psr\Http\Message\ResponseInterface;

class HomeController
{
    public function __construct(private HomeService $service)
    {
    }

    public function index(): ResponseInterface
    {
        return ApiResponse::data([
            'documents' => DocumentResource::collection($this->service->documents()),
            'subjects' => SubjectSummaryResource::collection($this->service->subjects()),
            'stats' => $this->service->stats(),
        ]);
    }
}
