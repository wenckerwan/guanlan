<?php

declare(strict_types=1);

namespace App\Controller;

use App\Model\MistakeAnalysisReport;
use App\Model\MistakeProfile;
use App\Model\MistakeReview;
use App\Resource\MistakeResource;
use App\Service\AIAnalysisService;
use App\Service\MistakeReviewService;
use App\Service\MistakeService;
use App\Support\ApiResponse;
use App\Support\Auth;
use App\Support\MistakeAccess;
use App\Support\UserGroup;
use App\Support\Validator;
use Hyperf\Context\ApplicationContext;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface as HyperfResponseInterface;
use Psr\Http\Message\ResponseInterface;

class MistakeController
{
    public function __construct(
        private MistakeService $service,
        private MistakeReviewService $reviewService,
        private RequestInterface $request,
        private HyperfResponseInterface $response
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
     * 请求 AI 分析：提示词来自用户上传的 Markdown（可选）或考生错题数据。
     * 全部经由服务端代理转发，浏览器不再直连 AI 提供商。
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

        $markdown = trim((string) $this->request->input('markdown', ''));
        if (mb_strlen($markdown) > 200000) {
            return ApiResponse::message('错题内容过长（上限 20 万字符）', 422);
        }

        $result = $markdown !== ''
            ? $aiService->analyzeMarkdown($student, $markdown, $config)
            : $aiService->analyze($student, $config);

        if (isset($result['error'])) {
            return ApiResponse::message($result['error'], 500);
        }

        return ApiResponse::data([
            'content' => $result['content'],
            'usage' => $result['usage'] ?? [],
        ]);
    }

    /**
     * 请求 AI 分析（SSE 流式）：校验逻辑与 requestAIAnalysis 相同，
     * 校验通过后以 text/event-stream 逐段推送增量文本，
     * 事件格式：data: {"delta":"..."} / data: {"error":"..."} / data: {"done":true,...}
     */
    public function requestAIAnalysisStream(string $code): ResponseInterface
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

        $markdown = trim((string) $this->request->input('markdown', ''));
        if (mb_strlen($markdown) > 200000) {
            return ApiResponse::message('错题内容过长（上限 20 万字符）', 422);
        }

        $sse = $this->response
            ->withHeader('Content-Type', 'text/event-stream; charset=utf-8')
            ->withHeader('Cache-Control', 'no-cache')
            ->withHeader('X-Accel-Buffering', 'no');

        $send = function (array $payload) use ($sse): void {
            $sse->write('data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n\n");
        };

        $onDelta = function (string $delta) use ($send): void {
            $send(['delta' => $delta]);
        };

        try {
            $result = $markdown !== ''
                ? $aiService->analyzeMarkdownStream($student, $markdown, $config, $onDelta)
                : $aiService->analyzeStream($student, $config, $onDelta);
        } catch (\Throwable $e) {
            $send(['error' => 'AI 分析流式响应中断: ' . mb_substr($e->getMessage(), 0, 200)]);

            return $sse;
        }

        if (isset($result['error'])) {
            $send(['error' => $result['error']]);

            return $sse;
        }

        $send([
            'done' => true,
            'content' => $result['content'],
            'usage' => $result['usage'] ?? [],
        ]);

        return $sse;
    }

    /**
     * AI 连接测试 + 模型列表获取（凭证由请求方自带，服务端不存储）
     */
    public function testAIConnection(): ResponseInterface
    {
        $user = Auth::user();
        if (! $user) {
            return ApiResponse::message('未登录', 401);
        }

        $aiService = $this->aiService();
        if ($aiService === null) {
            return ApiResponse::message('AI 分析组件未安装（服务器缺少 hyperf/guzzle），请联系管理员启用', 503);
        }

        $validator = new Validator($this->request->all());
        $validator->required('provider', 'AI 提供商');
        if ($validator->fails()) {
            return ApiResponse::message('请求校验失败', 422, $validator->errors());
        }

        $config = [
            'provider' => $validator->string('provider'),
            'apiKey' => $validator->string('apiKey'),
            'baseUrl' => $validator->string('baseUrl'),
            'model' => $validator->string('model'),
            'endpoint' => $validator->string('endpoint'),
        ];

        return ApiResponse::data($aiService->testConnection($config));
    }

    /**
     * AI 真实对话测试：发送固定短消息，验证模型实际可用
     */
    public function chatTestAIConnection(): ResponseInterface
    {
        $user = Auth::user();
        if (! $user) {
            return ApiResponse::message('未登录', 401);
        }

        $aiService = $this->aiService();
        if ($aiService === null) {
            return ApiResponse::message('AI 分析组件未安装（服务器缺少 hyperf/guzzle），请联系管理员启用', 503);
        }

        $validator = new Validator($this->request->all());
        $validator->required('provider', 'AI 提供商');
        if ($validator->fails()) {
            return ApiResponse::message('请求校验失败', 422, $validator->errors());
        }

        $config = [
            'provider' => $validator->string('provider'),
            'apiKey' => $validator->string('apiKey'),
            'baseUrl' => $validator->string('baseUrl'),
            'model' => $validator->string('model'),
            'endpoint' => $validator->string('endpoint'),
        ];

        return ApiResponse::data($aiService->chatTest($config));
    }

    /**
     * 保存 AI 分析报告到本人名下（每考生保留最近 20 份）
     */
    public function saveAnalysisReport(string $code): ResponseInterface
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
        $validator->required('markdown', '分析报告')
            ->max('markdown', 200000, '分析报告');
        if ($validator->fails()) {
            return ApiResponse::message('请求校验失败', 422, $validator->errors());
        }

        $markdown = $validator->string('markdown');
        $title = trim((string) $this->request->input('title', ''));
        if ($title === '') {
            $title = 'AI 分析 ' . date('Y-m-d H:i');
        }
        $title = mb_substr($title, 0, 100);

        // 用户组每日报告次数限制（管理员不限）
        if (! $user->isAdmin()) {
            $limit = (int) (UserGroup::features($user->group())['aiReportDailyLimit'] ?? 0);
            $usedToday = MistakeAnalysisReport::query()
                ->where('user_id', (int) $user->id)
                ->where('created_at', '>=', date('Y-m-d 00:00:00'))
                ->count();
            if ($usedToday >= $limit) {
                return ApiResponse::message("今日 AI 分析次数已达上限（{$limit} 次），升级用户组可获得更多次数", 429);
            }
        }

        $report = MistakeAnalysisReport::create([
            'user_id' => (int) $user->id,
            'student_code' => (string) $student->code,
            'title' => $title,
            'markdown' => $markdown,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        // 只保留最近 20 份
        $keepIds = MistakeAnalysisReport::query()
            ->where('user_id', (int) $user->id)
            ->where('student_code', (string) $student->code)
            ->orderByDesc('id')
            ->limit(20)
            ->pluck('id')
            ->all();
        MistakeAnalysisReport::query()
            ->where('user_id', (int) $user->id)
            ->where('student_code', (string) $student->code)
            ->whereNotIn('id', $keepIds)
            ->delete();

        return ApiResponse::data([
            'id' => (int) $report->id,
            'title' => $title,
            'createdAt' => (string) $report->created_at,
        ], 201);
    }

    /**
     * 本人针对某考生的 AI 分析报告列表
     */
    public function analysisReports(string $code): ResponseInterface
    {
        $user = Auth::user();
        if (! $user) {
            return ApiResponse::message('未登录', 401);
        }

        $rows = MistakeAnalysisReport::query()
            ->where('user_id', (int) $user->id)
            ->where('student_code', $code)
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        return ApiResponse::data(array_map(static fn ($row) => [
            'id' => (int) $row->id,
            'title' => (string) $row->title,
            'createdAt' => (string) $row->created_at,
        ], $rows->all()));
    }

    /**
     * 读取本人的一份 AI 分析报告
     */
    public function analysisReport(int $id): ResponseInterface
    {
        $user = Auth::user();
        if (! $user) {
            return ApiResponse::message('未登录', 401);
        }

        $report = MistakeAnalysisReport::query()
            ->where('id', $id)
            ->where('user_id', (int) $user->id)
            ->first();
        if (! $report) {
            return ApiResponse::message('报告不存在', 404);
        }

        return ApiResponse::data([
            'id' => (int) $report->id,
            'studentCode' => (string) $report->student_code,
            'title' => (string) $report->title,
            'markdown' => (string) $report->markdown,
            'createdAt' => (string) $report->created_at,
        ]);
    }

    /**
     * 删除本人的一份 AI 分析报告
     */
    public function deleteAnalysisReport(int $id): ResponseInterface
    {
        $user = Auth::user();
        if (! $user) {
            return ApiResponse::message('未登录', 401);
        }

        $report = MistakeAnalysisReport::query()
            ->where('id', $id)
            ->where('user_id', (int) $user->id)
            ->first();
        if (! $report) {
            return ApiResponse::message('报告不存在', 404);
        }

        $report->delete();

        return ApiResponse::message('已删除', 200);
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
                    'id' => 'deepseek',
                    'name' => 'DeepSeek (官方)',
                    'fields' => [
                        ['key' => 'apiKey', 'label' => 'API Key', 'required' => true],
                        ['key' => 'baseUrl', 'label' => 'Base URL', 'required' => false, 'default' => 'https://api.deepseek.com'],
                        ['key' => 'model', 'label' => 'Model', 'required' => false, 'default' => 'deepseek-chat'],
                    ],
                ],
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
