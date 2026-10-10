<?php
declare(strict_types=1);
namespace App\Controller;
use App\Service\AdminArticleBatchService;
use App\Support\ApiResponse;
use Hyperf\HttpServer\Contract\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class AdminArticleBatchController
{
    public function __construct(private AdminArticleBatchService $batch, private RequestInterface $request) {}
    public function updateStatus(string $kind): ResponseInterface
    {
        try {return ApiResponse::data($this->batch->updateStatus($kind,$this->request->all()));}
        catch(\RuntimeException $e) {
            if(!in_array($e->getCode(),[403,422],true)) throw $e;
            return ApiResponse::message($e->getMessage(),$e->getCode());
        }
    }
}
