<?php

declare(strict_types=1);

namespace App\Controller;

use App\Resource\MistakeResource;
use App\Service\MistakeService;
use App\Support\ApiResponse;
use App\Support\Validator;
use Hyperf\HttpServer\Contract\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class MistakeController
{
    public function __construct(
        private MistakeService $service,
        private RequestInterface $request
    ) {
    }

    public function students(): ResponseInterface
    {
        $out = [];
        foreach ($this->service->students() as $student) {
            $out[] = MistakeResource::student(
                $student,
                $this->service->moduleCounts((int) $student->id),
                $this->service->errorTypeCounts((int) $student->id)
            );
        }

        return ApiResponse::data($out);
    }

    public function show(string $code): ResponseInterface
    {
        $student = $this->service->student($code);
        if (! $student) {
            return ApiResponse::message('考生不存在', 404);
        }

        return ApiResponse::data(MistakeResource::student(
            $student,
            $this->service->moduleCounts((int) $student->id),
            $this->service->errorTypeCounts((int) $student->id)
        ));
    }

    public function items(string $code): ResponseInterface
    {
        $student = $this->service->student($code);
        if (! $student) {
            return ApiResponse::message('考生不存在', 404);
        }

        $page = max(1, (int) $this->request->input('page', 1));
        $perPage = min(100, max(1, (int) $this->request->input('perPage', 20)));
        $result = $this->service->items(
            (int) $student->id,
            (string) $this->request->input('module', ''),
            (string) $this->request->input('errorType', ''),
            $page,
            $perPage
        );

        return ApiResponse::data([
            'student' => MistakeResource::student(
                $student,
                $this->service->moduleCounts((int) $student->id),
                $this->service->errorTypeCounts((int) $student->id)
            ),
            'items' => MistakeResource::items($result['items']),
            'total' => $result['total'],
            'page' => $page,
            'perPage' => $perPage,
        ]);
    }

    public function handbooks(string $code): ResponseInterface
    {
        $student = $this->service->student($code);
        if (! $student) {
            return ApiResponse::message('考生不存在', 404);
        }

        return ApiResponse::data(MistakeResource::handbooks($this->service->handbooks((int) $student->id)));
    }

    public function updateAction(int $id): ResponseInterface
    {
        $item = $this->service->item($id);
        if (! $item) {
            return ApiResponse::message('错题不存在', 404);
        }

        $validator = new Validator($this->request->all());
        $validator->required('action', '行动建议')
            ->max('action', 20000, '行动建议');

        if ($validator->fails()) {
            return ApiResponse::message('请求校验失败', 422, $validator->errors());
        }

        return ApiResponse::data(
            MistakeResource::item($this->service->updateAction($item, $validator->string('action')))
        );
    }

    public function handbook(int $id): ResponseInterface
    {
        $handbook = $this->service->handbook($id);
        if (! $handbook) {
            return ApiResponse::message('提分手册不存在', 404);
        }

        $handbook->load('student');

        return ApiResponse::data(MistakeResource::handbook($handbook, true));
    }

    public function detail(string $code): ResponseInterface
    {
        $student = $this->service->student($code);
        if (! $student) {
            return ApiResponse::message('考生不存在', 404);
        }

        return ApiResponse::data([
            'code' => (string) $student->code,
            'name' => (string) $student->name,
            'relation' => (string) $student->relation,
            'html' => (string) $student->detail_html,
        ]);
    }
}
