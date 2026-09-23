<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\AnalysisArticle;
use App\Model\Attempt;
use App\Model\Favorite;
use App\Model\Hotspot;
use App\Model\MistakeItem;
use App\Model\Note;
use App\Model\Paper;
use App\Model\Prediction;
use App\Model\Question;
use App\Model\User;
use Hyperf\DbConnection\Db;

/**
 * 通过后台：概览、用户管理、内容增删改。
 */
class AdminService
{
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
            'attempts' => Attempt::class,
            'favorites' => Favorite::class,
            'notes' => Note::class,
        ] as $key => $model) {
            $counts[$key] = (int) $model::query()->count();
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

    public function updateUser(int $id, string $role = '', string $status = ''): ?User
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
}
