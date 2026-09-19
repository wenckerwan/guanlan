<?php

declare(strict_types=1);

namespace App\Controller;

use App\Resource\DocumentResource;
use App\Resource\HotspotResource;
use App\Resource\SubjectSummaryResource;
use App\Service\HomeService;

class HomeController
{
    public function __construct(private HomeService $service)
    {
    }

    public function index(): array
    {
        return [
            'data' => [
                'hotspots' => HotspotResource::collection($this->service->hotspots()),
                'documents' => DocumentResource::collection($this->service->documents()),
                'subjects' => SubjectSummaryResource::collection($this->service->subjects()),
            ],
        ];
    }
}
