<?php

declare(strict_types=1);

namespace App\Controller;

use App\Resource\FavoriteResource;
use App\Resource\NoteResource;
use App\Resource\ProgressResource;
use App\Service\StudyService;
use App\Support\ApiResponse;
use App\Support\Auth;
use App\Support\Validator;
use Hyperf\HttpServer\Contract\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class StudyController
{
    public function __construct(
        private StudyService $service,
        private RequestInterface $request
    ) {
    }

    public function favorites(): ResponseInterface
    {
        $user = Auth::user();
        $items = $this->service->favorites($user, (string) $this->request->input('targetType', ''));

        return ApiResponse::data(FavoriteResource::collection($items));
    }

    public function toggleFavorite(): ResponseInterface
    {
        $validator = new Validator($this->request->all());
        $validator->required('targetType', '收藏类型')
            ->required('targetId', '收藏对象')
            ->in('targetType', StudyService::TARGET_TYPES, '收藏类型');

        if ($validator->fails()) {
            return ApiResponse::message('请求校验失败', 422, $validator->errors());
        }

        $result = $this->service->toggleFavorite(
            Auth::user(),
            $validator->string('targetType'),
            $validator->string('targetId'),
            $validator->string('title'),
            $validator->string('url')
        );

        return ApiResponse::data($result);
    }

    public function removeFavorite(int $id): ResponseInterface
    {
        $removed = $this->service->removeFavorite(Auth::user(), $id);
        return $removed
            ? ApiResponse::data(['ok' => true])
            : ApiResponse::message('收藏不存在', 404);
    }

    public function notes(): ResponseInterface
    {
        $items = $this->service->notes(
            Auth::user(),
            (string) $this->request->input('targetType', ''),
            (string) $this->request->input('targetId', '')
        );

        return ApiResponse::data(NoteResource::collection($items));
    }

    public function createNote(): ResponseInterface
    {
        $validator = new Validator($this->request->all());
        $validator->required('targetType', '笔记类型')
            ->required('targetId', '笔记对象')
            ->required('content', '笔记内容')
            ->in('targetType', StudyService::TARGET_TYPES, '笔记类型')
            ->max('content', 20000, '笔记内容');

        if ($validator->fails()) {
            return ApiResponse::message('请求校验失败', 422, $validator->errors());
        }

        $note = $this->service->addNote(
            Auth::user(),
            $validator->string('targetType'),
            $validator->string('targetId'),
            (string) $this->request->input('content', ''),
            $validator->string('title')
        );

        return ApiResponse::data(NoteResource::make($note), 201);
    }

    public function removeNote(int $id): ResponseInterface
    {
        $removed = $this->service->removeNote(Auth::user(), $id);
        return $removed
            ? ApiResponse::data(['ok' => true])
            : ApiResponse::message('笔记不存在', 404);
    }

    public function createAttempt(): ResponseInterface
    {
        $validator = new Validator($this->request->all());
        $validator->required('source', '来源')
            ->required('questionRef', '题号')
            ->in('source', ['paper', 'mock', 'mistake', 'question'], '来源');

        if ($validator->fails()) {
            return ApiResponse::message('请求校验失败', 422, $validator->errors());
        }

        $attempt = $this->service->recordAttempt(
            Auth::user(),
            $validator->string('source'),
            $validator->string('sourceRef'),
            $validator->string('questionRef'),
            $validator->string('module'),
            $validator->string('chosen'),
            $validator->string('correct')
        );

        return ApiResponse::data([
            'id' => (int) $attempt->id,
            'isRight' => (bool) $attempt->is_right,
            'correct' => (string) $attempt->correct,
        ], 201);
    }

    public function stats(): ResponseInterface
    {
        return ApiResponse::data($this->service->stats(Auth::user()));
    }

    public function progress(): ResponseInterface
    {
        return ApiResponse::data(ProgressResource::collection($this->service->progress(Auth::user())));
    }

    public function saveProgress(): ResponseInterface
    {
        $validator = new Validator($this->request->all());
        $validator->required('scope', '范围')
            ->required('ref', '对象');

        if ($validator->fails()) {
            return ApiResponse::message('请求校验失败', 422, $validator->errors());
        }

        $record = $this->service->upsertProgress(
            Auth::user(),
            $validator->string('scope'),
            $validator->string('ref'),
            $validator->string('label'),
            $validator->string('status', 'reading'),
            $validator->int('progress')
        );

        return ApiResponse::data(ProgressResource::make($record));
    }
}
