<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\PracticeStudyService;
use App\Support\ApiResponse;
use App\Support\Auth;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

class PracticeController
{
    public function __construct(private PracticeStudyService $service) {}

    public function bank(string $slug): ResponseInterface
    {
        try {
            return ApiResponse::data($this->service->bank($slug, Auth::user()));
        } catch (RuntimeException $error) {
            return ApiResponse::message($error->getMessage(), $error->getCode() ?: 500);
        }
    }
}
