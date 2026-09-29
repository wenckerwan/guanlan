<?php

declare(strict_types=1);

namespace App\Controller;

use App\Resource\ArticleResource;
use App\Resource\MockResource;
use App\Resource\MistakeResource;
use App\Resource\PaperResource;
use App\Resource\QuestionResource;
use App\Resource\UserResource;
use App\Service\AdminAuditService;
use App\Service\AdminService;
use App\Support\ApiResponse;
use App\Support\Auth;
use App\Support\Validator;
use Hyperf\HttpServer\Contract\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class AdminController
{
    public function __construct(
        private AdminService $service,
        private AdminAuditService $audit,
        private RequestInterface $request
    ) {
    }

    private function page(): int
    {
        return max(1, (int) $this->request->input('page', 1));
    }

    private function perPage(): int
    {
        return min(100, max(1, (int) $this->request->input('perPage', 20)));
    }

    /** 后台只读：试卷列表（分页） */
    public function papers(): ResponseInterface
    {
        $result = $this->service->papers($this->page(), $this->perPage());

        return ApiResponse::data([
            'items' => PaperResource::collection($result['items']),
            'total' => $result['total'],
            'page' => $result['page'],
            'perPage' => $result['perPage'],
        ]);
    }

    /** 后台只读：某卷题目（分页） */
    public function paperQuestions(string $pid): ResponseInterface
    {
        $result = $this->service->paperQuestions($pid, $this->page(), $this->perPage());

        return ApiResponse::data([
            'items' => QuestionResource::collection($result['items']),
            'total' => $result['total'],
            'page' => $result['page'],
            'perPage' => $result['perPage'],
        ]);
    }

    /** 后台只读：模拟押题（分页） */
    public function mocks(): ResponseInterface
    {
        $result = $this->service->mocks($this->page(), $this->perPage());

        return ApiResponse::data([
            'items' => MockResource::collection($result['items']),
            'total' => $result['total'],
            'page' => $result['page'],
            'perPage' => $result['perPage'],
        ]);
    }

    /** 后台只读：时政预测（分页） */
    public function predictions(): ResponseInterface
    {
        $result = $this->service->predictions($this->page(), $this->perPage());

        return ApiResponse::data([
            'items' => ArticleResource::collection($result['items']),
            'total' => $result['total'],
            'page' => $result['page'],
            'perPage' => $result['perPage'],
        ]);
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

    /* ---------- 错题后台（阶段 B1） ---------- */

    /** 全部考生（含数据集考生），带计数与绑定账号 */
    public function mistakeStudents(): ResponseInterface
    {
        return ApiResponse::data($this->service->mistakeStudents());
    }

    /** 某考生错题条目（分页，只读浏览） */
    public function mistakeItems(string $code): ResponseInterface
    {
        $student = $this->service->mistakeStudent($code);
        if (! $student) {
            return ApiResponse::message('考生不存在', 404);
        }

        $result = $this->service->mistakeItems($code, $this->page(), $this->perPage());

        return ApiResponse::data([
            'code' => (string) $student->code,
            'name' => (string) $student->name,
            'items' => MistakeResource::items($result['items']),
            'total' => $result['total'],
            'page' => $result['page'],
            'perPage' => $result['perPage'],
        ]);
    }

    /** 当前错题分析 Markdown */
    public function mistakeProfile(string $code): ResponseInterface
    {
        $profile = $this->service->mistakeProfile($code);

        return $profile
            ? ApiResponse::data($profile)
            : ApiResponse::message('考生不存在', 404);
    }

    /** 上传/替换错题分析 Markdown（落库 + 审计） */
    public function saveMistakeProfile(string $code): ResponseInterface
    {
        $user = Auth::user();
        if (! $user) {
            return ApiResponse::message('未登录', 401);
        }

        $validator = new Validator($this->request->all());
        $validator->required('markdown', '错题分析内容')
            ->max('markdown', 200000, '错题分析内容');

        if ($validator->fails()) {
            return ApiResponse::message('请求校验失败', 422, $validator->errors());
        }

        $markdown = $validator->string('markdown');
        $sourceFile = trim((string) $this->request->input('sourceFile', '')) ?: 'admin-console.md';

        $profile = $this->service->saveMistakeProfile($user, $code, $markdown, $sourceFile);
        if (! $profile) {
            return ApiResponse::message('考生不存在', 404);
        }

        $this->audit->log($user, 'mistake.profile.replace', 'mistake_student', $code, [
            'sourceFile' => $sourceFile,
            'markdownLength' => mb_strlen($markdown),
        ]);

        return ApiResponse::data($profile);
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
