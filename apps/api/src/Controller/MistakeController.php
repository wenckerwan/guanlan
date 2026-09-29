<?php

declare(strict_types=1);

namespace App\Controller;

use App\Model\MistakeProfile;
use App\Model\MistakeReview;
use App\Resource\MistakeResource;
use App\Service\AIAnalysisService;
use App\Service\MistakeReviewService;
use App\Service\MistakeService;
use App\Support\ApiResponse;
use App\Support\Auth;
use App\Support\MistakeAccess;
use App\Support\Validator;
use Hyperf\Context\ApplicationContext;
use Hyperf\HttpServer\Contract\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class MistakeController
{
    public function __construct(
        private MistakeService $service,
        private MistakeReviewService $reviewService,
        private RequestInterface $request
    ) {
    }

    /**
     * 懒加载 AI 分析服务：AIAnalysisService 依赖 hyperf/guzzle，
     * 包未安装时不能在控制器构造阶段注入，否则整个错题板块一起 500。
     */
    private function aiService(): ?AIAnalysisService
    {
        try {
            return ApplicationContext::getContainer()->get(AIAnalysisService::class);
        } catch (\Throwable) {
            return null;
        }
    }

    public function students(): ResponseInterface
    {
        $out = [];
        foreach ($this->service->studentsWithin(MistakeAccess::allowedCodes()) as $student) {
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
        if (! MistakeAccess::canViewStudent($student)) {
            return ApiResponse::message('该错题本仅对应账号和管理员可见', 403);
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
        if (! MistakeAccess::canViewStudent($student)) {
            return ApiResponse::message('该错题本仅对应账号和管理员可见', 403);
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

        $personalActions = [];
        $user = Auth::user();
        if ($user !== null && $result['items'] !== []) {
            $itemIds = array_map(static fn ($item) => (int) $item->id, $result['items']);
            $personalActions = MistakeReview::query()
                ->where('user_id', (int) $user->id)
                ->whereIn('mistake_item_id', $itemIds)
                ->pluck('personal_action', 'mistake_item_id')
                ->all();
        }

        return ApiResponse::data([
            'student' => MistakeResource::student(
                $student,
                $this->service->moduleCounts((int) $student->id),
                $this->service->errorTypeCounts((int) $student->id)
            ),
            'items' => MistakeResource::items($result['items'], $personalActions),
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
        if (! MistakeAccess::canViewStudent($student)) {
            return ApiResponse::message('该错题本仅对应账号和管理员可见', 403);
        }

        return ApiResponse::data(MistakeResource::handbooks($this->service->handbooks((int) $student->id)));
    }

    public function updateAction(int $id): ResponseInterface
    {
        $user = Auth::user();
        if (! $user) {
            return ApiResponse::message('未登录', 401);
        }

        $item = $this->service->item($id);
        if (! $item) {
            return ApiResponse::message('错题不存在', 404);
        }

        $code = (string) ($item->student?->code ?? '');
        if (! MistakeAccess::canViewCode($code)) {
            return ApiResponse::message('该错题本仅对应账号和管理员可见', 403);
        }

        $validator = new Validator($this->request->all());
        $validator->required('action', '行动建议')
            ->max('action', 20000, '行动建议');

        if ($validator->fails()) {
            return ApiResponse::message('请求校验失败', 422, $validator->errors());
        }

        $action = $validator->string('action');

        // 全局 action 是所有访客共享的内容，只有管理员能改；
        // 普通用户的行动建议写入个人复习记录的 personal_action。
        if (MistakeAccess::isAdmin($user)) {
            return ApiResponse::data(
                MistakeResource::item($this->service->updateAction($item, $action))
            );
        }

        return ApiResponse::data(
            MistakeResource::item($item, $this->reviewService->savePersonalAction($user, $item, $action))
        );
    }

    public function handbook(int $id): ResponseInterface
    {
        $handbook = $this->service->handbook($id);
        if (! $handbook) {
            return ApiResponse::message('提分手册不存在', 404);
        }

        if (! MistakeAccess::canViewCode((string) ($handbook->student?->code ?? ''))) {
            return ApiResponse::message('该提分手册仅对应账号和管理员可见', 403);
        }

        return ApiResponse::data(MistakeResource::handbook($handbook, true));
    }

    public function detail(string $code): ResponseInterface
    {
        $student = $this->service->student($code);
        if (! $student) {
            return ApiResponse::message('考生不存在', 404);
        }
        if (! MistakeAccess::canViewStudent($student)) {
            return ApiResponse::message('该错题本仅对应账号和管理员可见', 403);
        }

        // 内容来源：mistake_profiles（后台上传）优先，回退 students.detail_html（数据集内联）
        $profile = MistakeProfile::query()->where('student_id', (int) $student->id)->first();
        $isDefault = (bool) ($profile->is_default ?? true);

        return ApiResponse::data([
            'code' => (string) $student->code,
            'name' => (string) $student->name,
            'relation' => (string) $student->relation,
            'html' => $profile && ! $isDefault
                ? (string) $profile->html
                : (string) $student->detail_html,
            'isDefault' => $isDefault,
            'updatedAt' => $profile?->updated_at ? (string) $profile->updated_at : '',
        ]);
    }

    /**
     * 获取复习概览
     */
    public function reviewSummary(string $code): ResponseInterface
    {
        $user = Auth::user();
        if (! $user) {
            return ApiResponse::message('未登录', 401);
        }

        $student = $this->service->student($code);
        if (! $student) {
            return ApiResponse::message('考生不存在', 404);
        }
        if (! MistakeAccess::canViewStudent($student)) {
            return ApiResponse::message('该错题本仅对应账号和管理员可见', 403);
        }

        $summary = $this->reviewService->getSummary($user, $code);

        return ApiResponse::data($summary);
    }

    /**
     * 提交错题重练
     */
    public function submitReview(int $id): ResponseInterface
    {
        $user = Auth::user();
        if (! $user) {
            return ApiResponse::message('未登录', 401);
        }

        $validator = new Validator($this->request->all());
        $validator->required('chosen', '答案');

        if ($validator->fails()) {
            return ApiResponse::message('请求校验失败', 422, $validator->errors());
        }

        $item = $this->service->item($id);
        if (! $item) {
            return ApiResponse::message('错题不存在', 404);
        }

        $code = (string) ($item->student?->code ?? '');
        if (! MistakeAccess::canViewCode($code)) {
            return ApiResponse::message('该错题本仅对应账号和管理员可见', 403);
        }

        $result = $this->reviewService->submitReview($user, $id, $validator->string('chosen'));

        return ApiResponse::data([
            'isCorrect' => $result['isCorrect'],
            'correctAnswer' => $result['correctAnswer'],
            'chosen' => $result['chosen'],
            'nextReviewAt' => $result['review']->next_review_at?->format('Y-m-d H:i:s'),
            'status' => $result['review']->status,
            'reviewCount' => $result['review']->review_count,
            'correctCount' => $result['review']->correct_count,
            'wrongCount' => $result['review']->wrong_count,
        ]);
    }

    /**
     * 修改复习状态
     */
    public function updateReviewStatus(int $id): ResponseInterface
    {
        $user = Auth::user();
        if (! $user) {
            return ApiResponse::message('未登录', 401);
        }

        $validator = new Validator($this->request->all());
        $validator->required('status', '状态')
            ->in('status', ['new', 'reviewing', 'mastered', 'snoozed'], '状态');

        if ($validator->fails()) {
            return ApiResponse::message('请求校验失败', 422, $validator->errors());
        }

        $item = $this->service->item($id);
        if (! $item) {
            return ApiResponse::message('错题不存在', 404);
        }

        $code = (string) ($item->student?->code ?? '');
        if (! MistakeAccess::canViewCode($code)) {
            return ApiResponse::message('该错题本仅对应账号和管理员可见', 403);
        }

        $nextReviewAt = trim((string) $this->request->input('nextReviewAt', ''));
        if ($nextReviewAt !== '' && strtotime($nextReviewAt) === false) {
            return ApiResponse::message('nextReviewAt 时间格式无效', 422);
        }

        $review = $this->reviewService->updateStatus(
            $user,
            $id,
            $validator->string('status'),
            $nextReviewAt !== '' ? $nextReviewAt : null
        );

        return ApiResponse::data([
            'status' => $review->status,
            'nextReviewAt' => $review->next_review_at?->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * 请求 AI 分析
     */
    public function requestAIAnalysis(string $code): ResponseInterface
    {
        $user = Auth::user();
        if (! $user) {
            return ApiResponse::message('未登录', 401);
        }

        $student = $this->service->student($code);
        if (! $student) {
            return ApiResponse::message('考生不存在', 404);
        }
        if (! MistakeAccess::canViewStudent($student)) {
            return ApiResponse::message('该错题本仅对应账号和管理员可见', 403);
        }

        $validator = new Validator($this->request->all());
        $validator->required('provider', 'AI 提供商')
            ->required('apiKey', 'API Key');

        if ($validator->fails()) {
            return ApiResponse::message('请求校验失败', 422, $validator->errors());
        }

        $aiService = $this->aiService();
        if ($aiService === null) {
            return ApiResponse::message('AI 分析组件未安装（服务器缺少 hyperf/guzzle），请联系管理员启用', 503);
        }

        $config = [
            'provider' => $validator->string('provider'),
            'apiKey' => $validator->string('apiKey'),
            'baseUrl' => $validator->string('baseUrl'),
            'model' => $validator->string('model'),
            'endpoint' => $validator->string('endpoint'),
        ];

        if (! $aiService->validateConfig($config)) {
            return ApiResponse::message('AI 配置无效', 422);
        }

        $result = $aiService->analyze($student, $config);

        if (isset($result['error'])) {
            return ApiResponse::message($result['error'], 500);
        }

        return ApiResponse::data([
            'content' => $result['content'],
            'usage' => $result['usage'] ?? [],
        ]);
    }

    /**
     * 获取 AI 配置模板
     */
    public function getAIConfig(): ResponseInterface
    {
        $aiService = $this->aiService();
        if ($aiService === null) {
            return ApiResponse::message('AI 分析组件未安装（服务器缺少 hyperf/guzzle），请联系管理员启用', 503);
        }

        return ApiResponse::data([
            'providers' => [
                [
                    'id' => 'openai',
                    'name' => 'OpenAI',
                    'fields' => [
                        ['key' => 'apiKey', 'label' => 'API Key', 'required' => true],
                        ['key' => 'baseUrl', 'label' => 'Base URL', 'required' => false, 'default' => 'https://api.openai.com/v1'],
                        ['key' => 'model', 'label' => 'Model', 'required' => false, 'default' => 'gpt-4'],
                    ],
                ],
                [
                    'id' => 'claude',
                    'name' => 'Claude (Anthropic)',
                    'fields' => [
                        ['key' => 'apiKey', 'label' => 'API Key', 'required' => true],
                        ['key' => 'baseUrl', 'label' => 'Base URL', 'required' => false, 'default' => 'https://api.anthropic.com/v1'],
                        ['key' => 'model', 'label' => 'Model', 'required' => false, 'default' => 'claude-opus-4-8'],
                    ],
                ],
                [
                    'id' => 'custom',
                    'name' => '自定义 API',
                    'fields' => [
                        ['key' => 'endpoint', 'label' => 'API 端点', 'required' => true],
                        ['key' => 'apiKey', 'label' => 'API Key (可选)', 'required' => false],
                    ],
                ],
            ],
            'defaultConfig' => $aiService->getDefaultConfig(),
        ]);
    }
}
