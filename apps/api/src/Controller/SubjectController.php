<?php

declare(strict_types=1);

namespace App\Controller;

use App\Resource\SubjectResource;
use App\Service\SubjectService;
use Hyperf\HttpServer\Contract\ResponseInterface as HttpResponse;

class SubjectController
{
    public function __construct(
        private SubjectService $service,
        private HttpResponse $response
    ) {
    }

    public function index(): array
    {
        return ['data' => SubjectResource::collection($this->service->list())];
    }

    public function show(string $slug): mixed
    {
        $subject = $this->service->findBySlug($slug);

        if (! $subject) {
            return $this->response
                ->json(['message' => 'subject not found'])
                ->withStatus(404);
        }

        return ['data' => SubjectResource::make($subject)];
    }
}
