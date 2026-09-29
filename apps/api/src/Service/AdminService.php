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
use App\Support\ContentStatus;
use Hyperf\DbConnection\Db;

/**
 * 通过后台：概览、用户管理、内容增删改。
 */
class AdminService
{
    public function __construct(
        private MistakeService $mistakeService,
        private MistakeProfileService $mistakeProfiles
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
        ];
    }

    /** @return array<int, User> */
    public function users(string $keyword = '', int $limit = 50): array
    {
        return User::query()
            ->when($keyword !== '', function ($q) use ($keyword) {
                $like = "%{$keyword}%";
                $q->where(fn ($inner) => $inner
                    ->where('email', 'like', $like)
                    ->orWhere('display_name', 'like', $like));
            })
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->all();
    }

    public function updateUser(int $id, string $role = '', string $status = '', ?string $mistakeCode = null): ?User
    {
        $user = User::find($id);
        if (! $user) {
            return null;
        }
        if (in_array($role, ['user', 'admin'], true)) {
            $user->role = $role;
        }
        if (in_array($status, ['active', 'disabled'], true)) {
            $user->status = $status;
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

    /** @return array<int, Hotspot> */
    public function hotspots(int $limit = 100): array
    {
        return Hotspot::query()->orderByDesc('id')->limit($limit)->get()->all();
    }

    public function saveHotspot(array $data, ?int $id = null): Hotspot
    {
        $hotspot = $id ? Hotspot::find($id) : new Hotspot();
        if (! $hotspot) {
            $hotspot = new Hotspot();
        }

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
            'status' => ContentStatus::normalize(isset($data['status']) ? (string) $data['status'] : null),
        ]);

        if (! $hotspot->slug) {
            $hotspot->slug = 'admin-' . bin2hex(random_bytes(6));
        }

        $hotspot->save();

        return $hotspot;
    }

    public function deleteHotspot(int $id): bool
    {
        return (bool) Hotspot::query()->where('id', $id)->delete();
    }

    /** @return array<int, AnalysisArticle> */
    public function analysis(int $limit = 100): array
    {
        return AnalysisArticle::query()->orderByDesc('id')->limit($limit)->get()->all();
    }

    public function saveAnalysis(array $data, ?int $id = null): AnalysisArticle
    {
        $article = $id ? AnalysisArticle::find($id) : null;
        if (! $article) {
            $article = new AnalysisArticle();
        }

        $article->fill([
            'title' => (string) ($data['title'] ?? $article->title ?? ''),
            'category' => (string) ($data['category'] ?? $article->category ?? ''),
            'summary' => (string) ($data['summary'] ?? $article->summary ?? ''),
            'html' => (string) ($data['html'] ?? $article->html ?? ''),
            'status' => ContentStatus::normalize(isset($data['status']) ? (string) $data['status'] : null),
        ]);

        if (! $article->slug) {
            $article->slug = 'admin-' . bin2hex(random_bytes(6));
        }

        $article->save();

        return $article;
    }

    public function deleteAnalysis(int $id): bool
    {
        return (bool) AnalysisArticle::query()->where('id', $id)->delete();
    }

    /** 后台只读：试卷分页 */
    public function papers(int $page = 1, int $perPage = 20): array
    {
        $query = Paper::query()->orderBy('sort_order')->orderBy('id');
        $total = (int) (clone $query)->count();
        $items = $query->forPage(max(1, $page), max(1, min(100, $perPage)))->get()->all();

        return ['items' => $items, 'total' => $total, 'page' => max(1, $page), 'perPage' => max(1, min(100, $perPage))];
    }

    /** 后台只读：某卷题目分页 */
    public function paperQuestions(string $pid, int $page = 1, int $perPage = 20): array
    {
        $query = Question::query()->where('pid', $pid)->orderBy('no');
        $total = (int) (clone $query)->count();
        $items = $query->forPage(max(1, $page), max(1, min(100, $perPage)))->get()->all();

        return ['items' => $items, 'total' => $total, 'page' => max(1, $page), 'perPage' => max(1, min(100, $perPage))];
    }

    /** 后台只读：模拟押题分页 */
    public function mocks(int $page = 1, int $perPage = 20): array
    {
        $query = Mock::query()->orderBy('id');
        $total = (int) (clone $query)->count();
        $items = $query->forPage(max(1, $page), max(1, min(100, $perPage)))->get()->all();

        return ['items' => $items, 'total' => $total, 'page' => max(1, $page), 'perPage' => max(1, min(100, $perPage))];
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
