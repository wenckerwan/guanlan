<?php

declare(strict_types=1);

namespace App\Controller;

use App\Resource\ArticleResource;
use App\Resource\UserResource;
use App\Service\AdminService;
use App\Support\ApiResponse;
use Hyperf\HttpServer\Contract\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class AdminController
{
    public function __construct(
        private AdminService $service,
        private RequestInterface $request
    ) {
    }

    public function overview(): ResponseInterface
    {
        return ApiResponse::data($this->service->overview());
    }

    public function users(): ResponseInterface
    {
        $users = $this->service->users(trim((string) $this->request->input('q', '')));
        return ApiResponse::data(UserResource::collection($users));
    }

    public function updateUser(int $id): ResponseInterface
    {
        $mistakeCode = $this->request->input('mistakeCode');
        $mistakeCode = $mistakeCode === null ? null : (string) $mistakeCode;

        try {
            $user = $this->service->updateUser(
                $id,
                (string) $this->request->input('role', ''),
                (string) $this->request->input('status', ''),
                $mistakeCode
            );
        } catch (\RuntimeException $exception) {
            return ApiResponse::message($exception->getMessage(), 422);
        }

        return $user
            ? ApiResponse::data(UserResource::make($user))
            : ApiResponse::message('用户不存在', 404);
    }

    public function attempts(): ResponseInterface
    {
        $limit = min(500, max(1, (int) $this->request->input('limit', 100)));
        $rows = $this->service->attempts($limit);

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id' => (int) $row->id,
                'userId' => (int) $row->user_id,
                'source' => (string) $row->source,
                'sourceRef' => (string) $row->source_ref,
                'questionRef' => (string) $row->question_ref,
                'module' => (string) $row->module,
                'chosen' => (string) $row->chosen,
                'correct' => (string) $row->correct,
                'isRight' => (bool) $row->is_right,
                'createdAt' => (string) $row->created_at,
            ];
        }

        return ApiResponse::data($out);
    }

    public function mistakes(): ResponseInterface
    {
        return ApiResponse::data($this->service->mistakeOverview());
    }

    public function hotspots(): ResponseInterface
    {
        return ApiResponse::data(ArticleResource::collection($this->service->hotspots()));
    }

    public function createHotspot(): ResponseInterface
    {
        if (trim((string) $this->request->input('title', '')) === '') {
            return ApiResponse::message('请求校验失败', 422, ['title' => '标题不能为空']);
        }

        return ApiResponse::data(ArticleResource::detail($this->service->saveHotspot($this->request->all())), 201);
    }

    public function updateHotspot(int $id): ResponseInterface
    {
        $hotspot = $this->service->saveHotspot($this->request->all(), $id);
        return ApiResponse::data(ArticleResource::detail($hotspot));
    }

    public function deleteHotspot(int $id): ResponseInterface
    {
        return $this->service->deleteHotspot($id)
            ? ApiResponse::data(['ok' => true])
            : ApiResponse::message('记录不存在', 404);
    }

    public function analysis(): ResponseInterface
    {
        return ApiResponse::data(ArticleResource::collection($this->service->analysis()));
    }

    public function createAnalysis(): ResponseInterface
    {
        if (trim((string) $this->request->input('title', '')) === '') {
            return ApiResponse::message('请求校验失败', 422, ['title' => '标题不能为空']);
        }

        return ApiResponse::data(ArticleResource::detail($this->service->saveAnalysis($this->request->all())), 201);
    }

    public function updateAnalysis(int $id): ResponseInterface
    {
        return ApiResponse::data(ArticleResource::detail($this->service->saveAnalysis($this->request->all(), $id)));
    }

    public function deleteAnalysis(int $id): ResponseInterface
    {
        return $this->service->deleteAnalysis($id)
            ? ApiResponse::data(['ok' => true])
            : ApiResponse::message('记录不存在', 404);
    }
}
