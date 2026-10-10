<?php

declare(strict_types=1);

namespace App\Controller;

use App\Model\User;
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

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();
        return $user;
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
        $result = $this->service->paperQuestions(rawurldecode($pid), $this->page(), $this->perPage());

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

    public function updatePrediction(int $id): ResponseInterface
    {
        try {
            $prediction = $this->service->savePrediction($this->request->all(), $id);
        } catch (\RuntimeException $exception) {
            return ApiResponse::message($exception->getMessage(), $exception->getCode() === 404 ? 404 : 422);
        }
        if (! $prediction) {
            return ApiResponse::message('记录不存在', 404);
        }

        $this->audit->log($this->user(), 'prediction.update', 'prediction', (string) $id, ['status' => (string) $prediction->status]);
        return ApiResponse::data(ArticleResource::listItem($prediction));
    }

    public function overview(): ResponseInterface
    {
        return ApiResponse::data($this->service->overview());
    }

    public function users(): ResponseInterface
    {
        try {
            $result = $this->service->users(
                trim((string)$this->request->input('q', '')), $this->page(), $this->perPage(),
                (string)$this->request->input('role', ''), (string)$this->request->input('status', ''),
                (string)$this->request->input('userGroup', '')
            );
        } catch (\RuntimeException $exception) {
            return ApiResponse::message($exception->getMessage(), 422);
        }
        return ApiResponse::data([
            'items' => UserResource::collection($result['items']), 'total' => $result['total'],
            'page' => $result['page'], 'perPage' => $result['perPage'],
        ]);
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
                $mistakeCode,
                (string) $this->request->input('userGroup', '')
            );
        } catch (\RuntimeException $exception) {
            return ApiResponse::message($exception->getMessage(), 422);
        }

        if ($user) {
            $this->audit->log($this->user(), 'user.update', 'user', (string) $id, [
                'role' => (string) $this->request->input('role', ''),
                'status' => (string) $this->request->input('status', ''),
                'userGroup' => (string) $this->request->input('userGroup', ''),
            ]);
        }

        return $user
            ? ApiResponse::data(UserResource::make($user))
            : ApiResponse::message('用户不存在', 404);
    }

    public function createUser(): ResponseInterface
    {
        $email = trim((string) $this->request->input('email', ''));
        $password = (string) $this->request->input('password', '');
        $displayName = trim((string) $this->request->input('displayName', ''));
        $role = (string) $this->request->input('role', 'user');

        if ($email === '' || $password === '') {
            return ApiResponse::message('请求校验失败', 422, ['email' => '邮箱与密码不能为空']);
        }

        try {
            $result = $this->service->createUser($email, $password, $displayName, $role);
        } catch (\RuntimeException $exception) {
            if (!in_array($exception->getCode(), [403, 422], true)) throw $exception;
            return ApiResponse::message($exception->getMessage(), $exception->getCode());
        }
        if (isset($result['error'])) {
            return ApiResponse::message($result['error'], 409);
        }

        $this->audit->log($this->user(), 'user.create', 'user', (string) $result['user']->id, ['email' => $email, 'role' => $role]);
        return ApiResponse::data(UserResource::make($result['user']), 201);
    }

    public function resetUserPassword(int $id): ResponseInterface
    {
        $password = (string) $this->request->input('password', '');
        try {
            $user = $this->service->resetPassword($id, $password);
        } catch (\RuntimeException $exception) {
            return ApiResponse::message($exception->getMessage(), 422);
        }
        if (! $user) {
            return ApiResponse::message('用户不存在', 404);
        }

        $this->audit->log($this->user(), 'user.reset_password', 'user', (string) $id);
        return ApiResponse::data(['ok' => true]);
    }

    public function auditLogs(): ResponseInterface
    {
        $action = trim((string) $this->request->input('action', ''));
        $adminId = (int) $this->request->input('adminId', 0);
        return ApiResponse::data($this->service->auditLogs($this->page(), $this->perPage(), $action, $adminId));
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

    /** B3：管理员维护错题条目全局字段（action / errorType / module） */
    public function updateMistakeItem(int $id): ResponseInterface
    {
        $user = Auth::user();
        if (! $user) {
            return ApiResponse::message('未登录', 401);
        }

        $input = [
            'action' => $this->request->input('action'),
            'errorType' => $this->request->input('errorType'),
            'module' => $this->request->input('module'),
        ];
        $provided = array_filter($input, static fn ($v) => $v !== null);
        if ($provided === []) {
            return ApiResponse::message('请求校验失败', 422, ['fields' => '至少提供一个可更新字段']);
        }

        if (isset($provided['action']) && mb_strlen((string) $provided['action']) > 20000) {
            return ApiResponse::message('请求校验失败', 422, ['action' => '行动建议过长']);
        }

        $updatedFields = $this->service->updateMistakeItem($id, $provided);
        if ($updatedFields === [] && ! $this->service->mistakeItemExists($id)) {
            return ApiResponse::message('错题不存在', 404);
        }

        $this->audit->log($user, 'mistake.item.update', 'mistake_item', (string) $id, [
            'fields' => $updatedFields,
        ]);

        $item = $this->service->mistakeItem($id);
        if (! $item) {
            return ApiResponse::message('错题不存在', 404);
        }

        return ApiResponse::data(MistakeResource::item($item));
    }

    /** B2：按考生汇总复习数据（与考生端 review-summary 同口径） */
    public function mistakeReviewStats(): ResponseInterface
    {
        return ApiResponse::data($this->service->mistakeReviewStats());
    }

    public function hotspots(): ResponseInterface
    {
        try {
            $result = $this->service->hotspots($this->page(), $this->perPage(), [
                'q' => $this->request->input('q', ''), 'status' => $this->request->input('status', ''),
                'period' => $this->request->input('period', ''), 'category' => $this->request->input('category', ''),
            ]);
        } catch (\RuntimeException $exception) { return ApiResponse::message($exception->getMessage(), 422); }
        $result['items'] = ArticleResource::collection($result['items']);
        return ApiResponse::data($result);
    }

    public function createHotspot(): ResponseInterface
    {
        if (trim((string) $this->request->input('title', '')) === '') {
            return ApiResponse::message('请求校验失败', 422, ['title' => '标题不能为空']);
        }

        try {
            $hotspot = $this->service->saveHotspot($this->request->all());
        } catch (\RuntimeException $exception) {
            return ApiResponse::message($exception->getMessage(), $exception->getCode() === 404 ? 404 : 422);
        }
        $this->audit->log($this->user(), 'hotspot.create', 'hotspot', (string) $hotspot->id, ['title' => (string) $hotspot->title]);
        return ApiResponse::data(ArticleResource::detail($hotspot), 201);
    }

    public function updateHotspot(int $id): ResponseInterface
    {
        try {
            $hotspot = $this->service->saveHotspot($this->request->all(), $id);
        } catch (\RuntimeException $exception) {
            return ApiResponse::message($exception->getMessage(), $exception->getCode() === 404 ? 404 : 422);
        }
        $this->audit->log($this->user(), 'hotspot.update', 'hotspot', (string) $id, ['title' => (string) $hotspot->title]);
        return ApiResponse::data(ArticleResource::detail($hotspot));
    }

    public function deleteHotspot(int $id): ResponseInterface
    {
        $ok = $this->service->deleteHotspot($id);
        if ($ok) {
            $this->audit->log($this->user(), 'hotspot.delete', 'hotspot', (string) $id);
        }
        return $ok
            ? ApiResponse::data(['ok' => true])
            : ApiResponse::message('记录不存在', 404);
    }

    public function analysis(): ResponseInterface
    {
        try {
            $result = $this->service->analysis($this->page(), $this->perPage(), [
                'q' => $this->request->input('q', ''), 'status' => $this->request->input('status', ''),
                'period' => $this->request->input('period', ''), 'category' => $this->request->input('category', ''),
            ]);
        } catch (\RuntimeException $exception) { return ApiResponse::message($exception->getMessage(), 422); }
        $result['items'] = ArticleResource::collection($result['items']);
        return ApiResponse::data($result);
    }

    public function createAnalysis(): ResponseInterface
    {
        if (trim((string) $this->request->input('title', '')) === '') {
            return ApiResponse::message('请求校验失败', 422, ['title' => '标题不能为空']);
        }

        try {
            $article = $this->service->saveAnalysis($this->request->all());
        } catch (\RuntimeException $exception) {
            return ApiResponse::message($exception->getMessage(), $exception->getCode() === 404 ? 404 : 422);
        }
        $this->audit->log($this->user(), 'analysis.create', 'analysis_article', (string) $article->id, ['title' => (string) $article->title]);
        return ApiResponse::data(ArticleResource::detail($article), 201);
    }

    public function updateAnalysis(int $id): ResponseInterface
    {
        try {
            $article = $this->service->saveAnalysis($this->request->all(), $id);
        } catch (\RuntimeException $exception) {
            return ApiResponse::message($exception->getMessage(), $exception->getCode() === 404 ? 404 : 422);
        }
        $this->audit->log($this->user(), 'analysis.update', 'analysis_article', (string) $id, ['title' => (string) $article->title]);
        return ApiResponse::data(ArticleResource::detail($article));
    }

    public function deleteAnalysis(int $id): ResponseInterface
    {
        $ok = $this->service->deleteAnalysis($id);
        if ($ok) {
            $this->audit->log($this->user(), 'analysis.delete', 'analysis_article', (string) $id);
        }
        return $ok
            ? ApiResponse::data(['ok' => true])
            : ApiResponse::message('记录不存在', 404);
    }

    public function createPaper(): ResponseInterface
    {
        try {
            $paper = $this->service->savePaper($this->request->all());
        } catch (\RuntimeException $exception) {
            return ApiResponse::message($exception->getMessage(), 422);
        }

        $this->audit->log($this->user(), 'paper.create', 'paper', (string) $paper->id, ['pid' => (string) $paper->pid]);
        return ApiResponse::data(PaperResource::make($paper), 201);
    }

    public function updatePaper(int $id): ResponseInterface
    {
        try {
            $paper = $this->service->savePaper($this->request->all(), $id);
        } catch (\RuntimeException $exception) {
            return ApiResponse::message($exception->getMessage(), 422);
        }

        $this->audit->log($this->user(), 'paper.update', 'paper', (string) $id, ['pid' => (string) $paper->pid]);
        return ApiResponse::data(PaperResource::make($paper));
    }

    public function deletePaper(int $id): ResponseInterface
    {
        $force = filter_var($this->request->input('force', false), FILTER_VALIDATE_BOOLEAN);
        try {
            $result = $this->service->deletePaper($id, $force);
        } catch (\RuntimeException $exception) {
            return ApiResponse::message($exception->getMessage(), 404);
        }

        if (isset($result['blocked'])) {
            return ApiResponse::message("该卷还有 {$result['questions']} 道题目，重复提交可连同题目一起删除", 409, $result);
        }

        $this->audit->log($this->user(), 'paper.delete', 'paper', (string) $id, $result);
        return ApiResponse::data(['ok' => true] + $result);
    }

    public function updateQuestion(int $id): ResponseInterface
    {
        $question = $this->service->updateQuestion($id, $this->request->all());
        if (! $question) {
            return ApiResponse::message('题目不存在', 404);
        }

        $this->audit->log($this->user(), 'question.update', 'question', (string) $id);
        return ApiResponse::data(QuestionResource::make($question));
    }
}
