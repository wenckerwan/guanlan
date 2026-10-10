<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\AnalysisArticle;
use App\Model\Attempt;
use App\Model\Favorite;
use App\Model\Hotspot;
use App\Model\Mock;
use App\Model\MistakeItem;
use App\Model\MistakeProfile;
use App\Model\MistakeStudent;
use App\Model\Note;
use App\Model\Paper;
use App\Model\Prediction;
use App\Model\Question;
use App\Model\User;
use App\Model\UserToken;
use App\Support\ContentMaintenance;
use App\Support\ContentStatus;
use App\Support\UserGroup;
use Hyperf\DbConnection\Db;

/**
 * 通过后台：概览、用户管理、内容增删改。
 */
class AdminService
{
    public function __construct(
        private MistakeService $mistakeService,
        private MistakeProfileService $mistakeProfiles,
        private AuthService $authService
    ) {
    }

    /** @return array<string, mixed> */
    public function overview(): array
    {
        $counts = [];
        foreach ([
            'users' => User::class,
            'papers' => Paper::class,
            'questions' => Question::class,
            'analysis_articles' => AnalysisArticle::class,
            'hotspots' => Hotspot::class,
            'predictions' => Prediction::class,
            'mistake_items' => MistakeItem::class,
            'mistake_reviews' => \App\Model\MistakeReview::class,
            'attempts' => Attempt::class,
            'favorites' => Favorite::class,
            'notes' => Note::class,
        ] as $key => $model) {
            $counts[$key] = (int) $model::query()->count();
        }

        // 近 14 天注册趋势：按天 group by，前端用 trend 纯函数补齐空档日
        $since = date('Y-m-d 00:00:00', strtotime('-13 days'));
        $trendRows = User::query()
            ->selectRaw("DATE(created_at) as day, count(*) as total")
            ->where('created_at', '>=', $since)
            ->groupBy(Db::raw('DATE(created_at)'))
            ->get();
        $perDay = [];
        foreach ($trendRows as $row) {
            $perDay[(string) $row->day] = (int) $row->total;
        }

        // 近 14 天作答趋势
        $attemptRows = Attempt::query()
            ->selectRaw("DATE(created_at) as day, count(*) as total")
            ->where('created_at', '>=', $since)
            ->groupBy(Db::raw('DATE(created_at)'))
            ->get();
        $attemptsPerDay = [];
        foreach ($attemptRows as $row) {
            $attemptsPerDay[(string) $row->day] = (int) $row->total;
        }

        // 内容上下线分布
        $statusCounts = [];
        foreach (['hotspots' => Hotspot::class, 'analysis_articles' => AnalysisArticle::class, 'predictions' => Prediction::class] as $key => $model) {
            $rows = $model::query()->selectRaw('status, count(*) as total')->groupBy('status')->get();
            $statusCounts[$key] = [];
            foreach ($rows as $row) {
                $statusCounts[$key][(string) ($row->status ?? 'published')] = (int) $row->total;
            }
        }

        // 最近 5 条审计日志
        $recentAudit = [];
        $auditRows = Db::table('admin_audit_logs')
            ->leftJoin('users', 'users.id', '=', 'admin_audit_logs.admin_id')
            ->orderByDesc('admin_audit_logs.id')
            ->limit(5)
            ->get(['admin_audit_logs.action', 'admin_audit_logs.target_type', 'admin_audit_logs.target_id', 'admin_audit_logs.created_at', 'users.email as admin_email']);
        foreach ($auditRows as $row) {
            $recentAudit[] = [
                'action' => (string) $row->action,
                'targetType' => (string) $row->target_type,
                'targetId' => (string) $row->target_id,
                'adminEmail' => (string) ($row->admin_email ?? ''),
                'createdAt' => (string) $row->created_at,
            ];
        }

        return [
            'counts' => $counts,
            'recentUsers' => User::query()->orderByDesc('id')->limit(5)
                ->get()
                ->map(fn (User $u) => [
                    'id' => (int) $u->id,
                    'email' => (string) $u->email,
                    'displayName' => (string) $u->display_name,
                    'role' => (string) $u->role,
                    'createdAt' => (string) $u->created_at,
                ])
                ->all(),
            'todayUsers' => User::query()->where('created_at', '>=', date('Y-m-d') . ' 00:00:00')->count(),
            'registrationTrend' => $perDay,
            'attemptsTrend' => $attemptsPerDay,
            'statusCounts' => $statusCounts,
            'recentAudit' => $recentAudit,
        ];
    }

    /** @return array{items: array, total: int, page: int, perPage: int} */
    public function users(string $keyword = '', int $page = 1, int $perPage = 20, string $role = '', string $status = '', string $userGroup = ''): array
    {
        foreach ([[$role, ['user', 'admin']], [$status, ['active', 'disabled']], [$userGroup, UserGroup::ALL]] as [$value, $allowed]) {
            if ($value !== '' && !in_array($value, $allowed, true)) throw new \RuntimeException('无效的用户筛选条件');
        }
        $keyword = trim($keyword);
        $perPage = min(100, max(1, $perPage));
        return Db::transaction(function () use ($keyword, $page, $perPage, $role, $status, $userGroup) {
            $query = User::query();
            if ($keyword !== '') {
                $like = '%' . strtr($keyword, ['=' => '==', '%' => '=%', '_' => '=_']) . '%';
                $query->whereRaw("(email LIKE ? ESCAPE '=' OR display_name LIKE ? ESCAPE '=')", [$like, $like]);
            }
            if ($role !== '') $query->where('role', $role);
            if ($status !== '') $query->where('status', $status);
            if ($userGroup === UserGroup::USER) {
                $query->where(function ($q) {
                    $q->where('user_group', UserGroup::USER)->orWhereNull('user_group')->orWhereNotIn('user_group', UserGroup::ALL);
                });
            } elseif ($userGroup !== '') { $query->where('user_group', $userGroup); }
            $total = (int)(clone $query)->count();
            $page = min(max(1, $page), max(1, (int)ceil($total / $perPage)));
            $items = $query->orderByDesc('id')->forPage($page, $perPage)->get()->all();
            return compact('items', 'total', 'page', 'perPage');
        });
    }

    public function updateUser(int $id, string $role = '', string $status = '', ?string $mistakeCode = null, string $userGroup = ''): ?User
    {
        return Db::transaction(function () use ($id, $role, $status, $mistakeCode, $userGroup) {
            $admins = User::query()->where('role', 'admin')->where('status', 'active')->orderBy('id')->lockForUpdate()->get();
            $user = User::query()->where('id', $id)->lockForUpdate()->first();
            if (!$user) return null;
            if ($role !== '' && !in_array($role, ['user', 'admin'], true)) throw new \RuntimeException('无效的角色');
            if ($status !== '' && !in_array($status, ['active', 'disabled'], true)) throw new \RuntimeException('无效的账号状态');
            if ($user->isAdmin() && $user->isActive() && ($role === 'user' || $status === 'disabled') && $admins->count() <= 1) {
                throw new \RuntimeException('不能禁用或降级最后一个有效管理员');
            }
            if (in_array($role, ['user', 'admin'], true)) {
                $user->role = $role;
            }
            if (in_array($status, ['active', 'disabled'], true)) {
                $user->status = $status;
            }
            if ($userGroup !== '') {
                if (! in_array($userGroup, UserGroup::ALL, true)) {
                    throw new \RuntimeException('无效的用户组');
                }
                $user->user_group = $userGroup;
            }
            if ($mistakeCode !== null) {
                $code = trim($mistakeCode);
                if ($code !== '' && $code !== $user->mistake_code) {
                    $taken = User::query()
                        ->where('mistake_code', $code)
                        ->where('id', '!=', (int) $user->id)
                        ->exists();
                    if ($taken) {
                        throw new \RuntimeException('该考生编号已被其他账号绑定');
                    }
                }
                $user->mistake_code = $code === '' ? null : $code;
            }
            $user->save();

            return $user;

        });
    }

    public function createUser(string $email, string $password, string $displayName, string $role): array
    {
        return $this->authService->createByAdmin($email, $password, $displayName, $role);
    }

    public function resetPassword(int $id, string $password): ?User
    {
        return Db::transaction(function () use ($id, $password) {
            if (mb_strlen($password) < 6) {
                throw new \RuntimeException('密码至少 6 位');
            }
            $user = User::query()->where('id', $id)->lockForUpdate()->first();
            if (! $user) {
                return null;
            }
            $user->password_hash = password_hash($password, PASSWORD_DEFAULT);
            $user->save();
            UserToken::query()->where('user_id', $id)->delete();

            return $user;

        });
    }

    /** 审计日志分页（含操作人邮箱） */
    public function auditLogs(int $page = 1, int $perPage = 20, string $action = '', int $adminId = 0): array
    {
        $query = Db::table('admin_audit_logs')->leftJoin('users', 'users.id', '=', 'admin_audit_logs.admin_id');
        if ($action !== '') {
            $query->where('admin_audit_logs.action', 'like', $action . '%');
        }
        if ($adminId > 0) {
            $query->where('admin_audit_logs.admin_id', $adminId);
        }
        $total = (int) (clone $query)->count();
        $rows = $query
            ->orderByDesc('admin_audit_logs.id')
            ->forPage(max(1, $page), max(1, min(100, $perPage)))
            ->get([
                'admin_audit_logs.id',
                'admin_audit_logs.admin_id',
                'users.email as admin_email',
                'admin_audit_logs.action',
                'admin_audit_logs.target_type',
                'admin_audit_logs.target_id',
                'admin_audit_logs.detail',
                'admin_audit_logs.created_at',
            ]);

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'id' => (int) $row->id,
                'adminId' => (int) $row->admin_id,
                'adminEmail' => (string) ($row->admin_email ?? ''),
                'action' => (string) $row->action,
                'targetType' => (string) $row->target_type,
                'targetId' => (string) $row->target_id,
                'detail' => json_decode((string) ($row->detail ?? ''), true),
                'createdAt' => (string) $row->created_at,
            ];
        }

        return ['items' => $items, 'total' => $total, 'page' => max(1, $page), 'perPage' => max(1, min(100, $perPage))];
    }

    public function savePaper(array $data, ?int $id = null): Paper
    {
        return ContentMaintenance::write('papers',function () use ($data, $id) {
            $paper = $id ? Paper::find($id) : null;
            if ($id !== null && ! $paper) {
                throw new \RuntimeException('试卷不存在');
            }

            $year = (int) ($data['year'] ?? $paper->year ?? (int) date('Y'));
            $pid = trim((string) ($data['pid'] ?? $paper->pid ?? ''));
            if ($pid === '') {
                $pid = sprintf('p-%d-%s', $year, bin2hex(random_bytes(4)));
            }
            $taken = Paper::query()->where('pid', $pid)->when($paper, fn ($q) => $q->where('id', '!=', $paper->id))->exists();
            if ($taken) {
                throw new \RuntimeException('试卷编号已存在');
            }

            $paper ??= new Paper();
            $paper->fill([
                'pid' => $pid,
                'year' => $year,
                'label' => mb_substr((string) ($data['label'] ?? $paper->label ?? ''), 0, 32),
                'kind' => mb_substr((string) ($data['kind'] ?? $paper->kind ?? ''), 0, 16),
                'total_score' => max(0, (int) ($data['totalScore'] ?? $paper->total_score ?? 0)),
                'sort_order' => (int) ($data['sortOrder'] ?? $paper->sort_order ?? 0),
                'sections' => is_array($data['sections'] ?? null) ? $data['sections'] : ($paper->sections ?? null),
            ]);
            $paper->save();
            $paper->question_count = (int) Question::query()->where('pid', $paper->pid)->count();
            $paper->save();

            return $paper;

        });
    }

    /** @return array{blocked?: bool, questions?: int, deleted?: bool} */
    public function deletePaper(int $id, bool $force = false): array
    {
        return ContentMaintenance::write('papers',function () use ($id, $force) {
            $paper = Paper::find($id);
            if (! $paper) {
                throw new \RuntimeException('试卷不存在');
            }
            $questionCount = (int) Question::query()->where('pid', $paper->pid)->count();
            if ($questionCount > 0 && ! $force) {
                return ['blocked' => true, 'questions' => $questionCount];
            }
            Question::query()->where('pid', $paper->pid)->delete();
            $paper->delete();

            return ['deleted' => true, 'questions' => $questionCount];

        });
    }

    public function updateQuestion(int $id, array $data): ?Question
    {
        return ContentMaintenance::write('questions',function () use ($id, $data) {
            $question = Question::find($id);
            if (! $question) {
                return null;
            }
            $question->fill([
                'stem' => (string) ($data['stem'] ?? $question->stem),
                'material' => (string) ($data['material'] ?? $question->material),
                'options' => is_array($data['options'] ?? null) ? array_values($data['options']) : $question->options,
                'answer' => mb_substr((string) ($data['answer'] ?? $question->answer), 0, 16),
                'answer_text' => (string) ($data['answerText'] ?? $question->answer_text),
                'analysis' => (string) ($data['analysis'] ?? $question->analysis),
                'kaodian' => mb_substr((string) ($data['kaodian'] ?? $question->kaodian), 0, 191),
                'module' => mb_substr((string) ($data['module'] ?? $question->module), 0, 16),
                'module_name' => mb_substr((string) ($data['moduleName'] ?? $question->module_name), 0, 64),
                'score' => (float) ($data['score'] ?? $question->score),
            ]);
            $question->save();

            return $question;

        });
    }

    /** @return array<int, Attempt> */
    public function attempts(int $limit = 100): array
    {
        return Attempt::query()->orderByDesc('id')->limit($limit)->get()->all();
    }

    /** @return array<string, int> */
    public function mistakeOverview(): array
    {
        $rows = MistakeItem::query()
            ->selectRaw('module, count(*) as total')
            ->groupBy('module')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row->module] = (int) $row->total;
        }
        return $out;
    }

    public function hotspots(int $page = 1, int $perPage = 20, array $filters = []): array
    {
        return $this->articleList(Hotspot::class, $page, $perPage, $filters, 'period');
    }

    private function pageResult(object $query, int $page, int $perPage): array
    {
        $perPage = max(1, min(100, $perPage));
        $total = (int)(clone $query)->count();
        $page = min(max(1, $page), max(1, (int)ceil($total / $perPage)));
        return ['items' => $query->forPage($page, $perPage)->get()->all(), 'total' => $total, 'page' => $page, 'perPage' => $perPage];
    }

    private function articleList(string $model, int $page, int $perPage, array $filters, string $facet): array
    {
        $status = (string)($filters['status'] ?? '');
        if ($status !== '' && !in_array($status, ContentStatus::ALL, true)) throw new \RuntimeException('无效的发布状态', 422);
        return Db::transaction(function () use ($model, $page, $perPage, $filters, $status, $facet) {
            $query = $model::query()->orderByDesc('id');
            $keyword = trim((string)($filters['q'] ?? ''));
            if ($keyword !== '') {
                $like = '%' . strtr($keyword, ['=' => '==', '%' => '=%', '_' => '=_']) . '%';
                $query->whereRaw("(title LIKE ? ESCAPE '=' OR summary LIKE ? ESCAPE '=')", [$like, $like]);
            }
            if ($status !== '') $query->where('status', $status);
            if (trim((string)($filters[$facet] ?? '')) !== '') $query->where($facet, trim((string)$filters[$facet]));
            $result = $this->pageResult($query, $page, $perPage);
            $options = $model::query()->whereNotNull($facet)->where($facet, '!=', '')->distinct()->orderBy($facet)->pluck($facet)->all();
            $result['filters'] = ['periods' => $facet === 'period' ? $options : [], 'categories' => $facet === 'category' ? $options : []];
            return $result;
        });
    }

    public function saveHotspot(array $data, ?int $id = null): Hotspot
    {
        return ContentMaintenance::write('hotspots',function () use ($data, $id) {
            $hotspot = $id !== null ? Hotspot::find($id) : new Hotspot();
            if (!$hotspot) throw new \RuntimeException('记录不存在', 404);

            $hotspot->fill([
                'title' => (string) ($data['title'] ?? $hotspot->title ?? ''),
                'level' => (string) ($data['level'] ?? $hotspot->level ?? 'A'),
                'priority' => (string) ($data['priority'] ?? $hotspot->priority ?? 'A'),
                'summary' => (string) ($data['summary'] ?? $hotspot->summary ?? ''),
                'type' => (string) ($data['type'] ?? $hotspot->type ?? ''),
                'tag' => (string) ($data['tag'] ?? $hotspot->tag ?? ''),
                'period' => (string) ($data['period'] ?? $hotspot->period ?? ''),
                'html' => (string) ($data['html'] ?? $hotspot->html ?? ''),
                'subject_id' => (int) ($data['subjectId'] ?? $hotspot->subject_id ?? 1),
                'status' => ContentStatus::forWrite($data, $hotspot->status ?? null),
            ]);

            if (! $hotspot->slug) {
                $hotspot->slug = 'admin-' . bin2hex(random_bytes(6));
            }

            $hotspot->save();

            return $hotspot;

        });
    }

    public function deleteHotspot(int $id): bool
    {
        return ContentMaintenance::write('hotspots',function () use ($id) {
            return (bool) Hotspot::query()->where('id', $id)->delete();

        });
    }

    public function analysis(int $page = 1, int $perPage = 20, array $filters = []): array
    {
        return $this->articleList(AnalysisArticle::class, $page, $perPage, $filters, 'category');
    }

    public function saveAnalysis(array $data, ?int $id = null): AnalysisArticle
    {
        return ContentMaintenance::write('analysis_articles',function () use ($data, $id) {
            $article = $id !== null ? AnalysisArticle::find($id) : new AnalysisArticle();
            if (!$article) throw new \RuntimeException('记录不存在', 404);

            $article->fill([
                'title' => (string) ($data['title'] ?? $article->title ?? ''),
                'category' => (string) ($data['category'] ?? $article->category ?? ''),
                'summary' => (string) ($data['summary'] ?? $article->summary ?? ''),
                'html' => (string) ($data['html'] ?? $article->html ?? ''),
                'status' => ContentStatus::forWrite($data, $article->status ?? null),
            ]);

            if (! $article->slug) {
                $article->slug = 'admin-' . bin2hex(random_bytes(6));
            }

            $article->save();

            return $article;

        });
    }

    public function deleteAnalysis(int $id): bool
    {
        return ContentMaintenance::write('analysis_articles',function () use ($id) {
            return (bool) AnalysisArticle::query()->where('id', $id)->delete();

        });
    }

    /** 后台只读：试卷分页 */
    public function papers(int $page = 1, int $perPage = 20): array
    {
        $query = Paper::query()->orderBy('sort_order')->orderBy('id');
        return Db::transaction(fn () => $this->pageResult($query, $page, $perPage));
    }

    /** 后台只读：某卷题目分页 */
    public function paperQuestions(string $pid, int $page = 1, int $perPage = 20): array
    {
        $query = Question::query()->where('pid', $pid)->orderBy('no');
        return Db::transaction(fn () => $this->pageResult($query, $page, $perPage));
    }

    /** 后台只读：模拟押题分页 */
    public function mocks(int $page = 1, int $perPage = 20): array
    {
        $query = Mock::query()->orderBy('id');
        return Db::transaction(fn () => $this->pageResult($query, $page, $perPage));
    }

    public function savePrediction(array $data, int $id): ?Prediction
    {
        return ContentMaintenance::write('predictions',function () use ($data, $id) {
            $prediction = Prediction::find($id);
            if (! $prediction) {
                return null;
            }
            $prediction->status = ContentStatus::forWrite($data, $prediction->status ?? null);
            if (isset($data['sortOrder'])) {
                $prediction->sort_order = (int) $data['sortOrder'];
            }
            $prediction->save();

            return $prediction;

        });
    }

    /** 后台只读：时政预测分页 */
    public function predictions(int $page = 1, int $perPage = 20): array
    {
        $query = Prediction::query()->orderBy('sort_order')->orderBy('id');
        $total = (int) (clone $query)->count();
        $items = $query->forPage(max(1, $page), max(1, min(100, $perPage)))->get()->all();

        return ['items' => $items, 'total' => $total, 'page' => max(1, $page), 'perPage' => max(1, min(100, $perPage))];
    }

    /* ---------- 错题后台（阶段 B1） ---------- */

    /** 全部考生（含未绑定账号的数据集考生），带计数与绑定账号信息 */
    public function mistakeStudents(): array
    {
        $students = $this->mistakeService->studentsWithin(null);
        if ($students === []) {
            return [];
        }

        $ownerIds = array_values(array_filter($students, fn ($s) => $s->owner_user_id) ?: []);
        $owners = [];
        if ($ownerIds !== []) {
            $owners = User::query()
                ->whereIn('id', array_map(static fn ($s) => (int) $s->owner_user_id, $ownerIds))
                ->get()
                ->keyBy('id');
        }

        $out = [];
        foreach ($students as $student) {
            /** @var User|null $owner */
            $owner = $student->owner_user_id ? ($owners[(int) $student->owner_user_id] ?? null) : null;
            $out[] = [
                'code' => (string) $student->code,
                'name' => (string) $student->name,
                'relation' => (string) $student->relation,
                'itemCount' => (int) $student->items()->count(),
                'moduleCounts' => $this->mistakeService->moduleCounts((int) $student->id),
                'errorTypes' => $this->mistakeService->errorTypeCounts((int) $student->id),
                'ownerEmail' => $owner?->email ?? '',
                'ownerId' => $student->owner_user_id ? (int) $student->owner_user_id : null,
                'isDataset' => ! (bool) $student->owner_user_id,
            ];
        }

        return $out;
    }

    public function mistakeStudent(string $code): ?MistakeStudent
    {
        return $this->mistakeService->student($code);
    }

    /** @return array{items: array, total: int, page: int, perPage: int} */
    public function mistakeItems(string $code, int $page = 1, int $perPage = 20): array
    {
        $student = $this->mistakeService->student($code);
        if (! $student) {
            return ['items' => [], 'total' => 0, 'page' => max(1, $page), 'perPage' => max(1, min(100, $perPage))];
        }

        $result = $this->mistakeService->items((int) $student->id, '', '', $page, $perPage);

        return [
            'items' => $result['items'],
            'total' => $result['total'],
            'page' => max(1, $page),
            'perPage' => max(1, min(100, $perPage)),
        ];
    }

    /** @return array<string, mixed>|null */
    public function mistakeProfile(string $code): ?array
    {
        $student = $this->mistakeService->student($code);
        if (! $student) {
            return null;
        }

        $profile = MistakeProfile::query()->where('student_id', (int) $student->id)->first();

        return [
            'code' => (string) $student->code,
            'name' => (string) $student->name,
            'markdown' => (string) ($profile->markdown ?? ''),
            'html' => (string) ($profile->html ?? ''),
            'isDefault' => (bool) ($profile->is_default ?? true),
            'sourceFile' => (string) ($profile->source_file ?? ''),
            'updatedAt' => $profile?->updated_at ? (string) $profile->updated_at : '',
        ];
    }

    /** @return array<string, mixed>|null */
    public function saveMistakeProfile(User $admin, string $code, string $markdown, string $sourceFile): ?array
    {
        $student = $this->mistakeService->student($code);
        if (! $student) {
            return null;
        }

        $profile = $this->mistakeProfiles->replace($student, $markdown, $sourceFile, $admin);

        return [
            'code' => (string) $student->code,
            'markdown' => (string) $profile->markdown,
            'html' => (string) $profile->html,
            'isDefault' => false,
            'sourceFile' => (string) $profile->source_file,
            'updatedAt' => (string) $profile->updated_at,
        ];
    }

    /** B3：管理员维护错题条目全局字段，返回实际写入的字段名列表 */
    public function updateMistakeItem(int $id, array $data): array
    {
        $item = MistakeItem::query()->find($id);
        if (! $item) {
            return [];
        }
        return $this->fillMistakeItem($item, $data);
    }

    public function mistakeItemExists(int $id): bool
    {
        return MistakeItem::query()->where('id', $id)->exists();
    }

    public function mistakeItem(int $id): ?MistakeItem
    {
        return MistakeItem::query()->find($id);
    }

    private function fillMistakeItem(MistakeItem $item, array $data): array
    {
        $fill = [];
        if (array_key_exists('action', $data) && $data['action'] !== null) {
            $fill['action'] = (string) $data['action'];
        }
        if (array_key_exists('errorType', $data) && $data['errorType'] !== null) {
            $fill['error_type'] = (string) $data['errorType'];
        }
        if (array_key_exists('module', $data) && $data['module'] !== null) {
            $fill['module'] = (string) $data['module'];
        }

        if ($fill !== []) {
            $item->fill($fill)->save();
        }

        return array_keys($fill);
    }

    /** @return array<int, array<string, mixed>> B2：按考生汇总复习数据，单条 group by */
    public function mistakeReviewStats(): array
    {
        $rows = Db::table('mistake_students as s')
            ->leftJoin('mistake_items as i', 'i.student_id', '=', 's.id')
            ->leftJoin('mistake_reviews as r', 'r.mistake_item_id', '=', 'i.id')
            ->groupBy('s.id', 's.code', 's.name')
            ->orderBy('s.code')
            ->selectRaw(implode(', ', [
                's.code as code',
                's.name as name',
                'count(distinct i.id) as item_count',
                'count(distinct r.user_id) as learners',
                'coalesce(sum(r.review_count), 0) as review_count',
                'coalesce(sum(r.correct_count), 0) as correct_count',
                'sum(case when r.status = \'new\' then 1 else 0 end) as status_new',
                'sum(case when r.status = \'reviewing\' then 1 else 0 end) as status_reviewing',
                'sum(case when r.status = \'mastered\' then 1 else 0 end) as status_mastered',
                'sum(case when r.status = \'snoozed\' then 1 else 0 end) as status_snoozed',
                'sum(case when r.next_review_at is not null and r.next_review_at < curdate() and r.status <> \'mastered\' then 1 else 0 end) as overdue',
            ]))
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $reviewCount = (int) $row->review_count;
            $correctCount = (int) $row->correct_count;
            $out[] = [
                'code' => (string) $row->code,
                'name' => (string) $row->name,
                'itemCount' => (int) $row->item_count,
                'learners' => (int) $row->learners,
                'reviewCount' => $reviewCount,
                'correctCount' => $correctCount,
                'accuracy' => $reviewCount > 0 ? round($correctCount / $reviewCount, 4) : 0,
                'new' => (int) $row->status_new,
                'reviewing' => (int) $row->status_reviewing,
                'mastered' => (int) $row->status_mastered,
                'snoozed' => (int) $row->status_snoozed,
                'overdue' => (int) $row->overdue,
            ];
        }

        return $out;
    }
}
