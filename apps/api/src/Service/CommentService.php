<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\AnalysisArticle;
use App\Model\Comment;
use App\Model\Hotspot;
use App\Model\Prediction;
use App\Model\User;
use App\Support\UserGroup;
use App\Support\ContentMaintenance;
use Hyperf\Database\Model\Builder;

/**
 * 文章评论区：登录用户可见可评（游客由路由层 401 拦截），
 * 置顶楼永远排最前，回复为一级楼中楼、不占楼层。
 */
class CommentService
{
    public const ARTICLE_TYPES = ['analysis', 'hotspot', 'prediction'];

    public const MODES = ['open', 'review', 'closed'];

    private const ARTICLE_MODELS = [
        'analysis' => AnalysisArticle::class,
        'hotspot' => Hotspot::class,
        'prediction' => Prediction::class,
    ];

    /** 文章的评论模式；文章不存在返回 null */
    public function mode(string $articleType, string $slug): ?string
    {
        $article = $this->article($articleType, $slug);

        return $article ? (string) ($article->comment_mode ?? 'open') : null;
    }

    public function setMode(string $articleType, string $slug, string $mode): bool
    {
        if (! in_array($mode, self::MODES, true)) {
            return false;
        }
        $table = ['analysis' => 'analysis_articles', 'hotspot' => 'hotspots', 'prediction' => 'predictions'][$articleType] ?? null;
        if ($table === null) return false;
        return ContentMaintenance::write($table, function () use ($articleType, $slug, $mode): bool {
            $article = $this->article($articleType, $slug);
            if (!$article) return false;
            $article->comment_mode = $mode;
            $article->save();
            return true;
        });
    }

    /**
     * 前台列表：置顶优先 → 楼层升序；自己的待审评论可见（标记审核中），他人待审不可见。
     *
     * @return array<string, mixed>
     */
    public function list(string $articleType, string $slug, User $user, int $page = 1, int $perPage = 20): array
    {
        $perPage = max(1, min(50, $perPage));
        $page = max(1, $page);

        $query = $this->visibleQuery($articleType, $slug, $user)
            ->whereNull('parent_id');

        $total = (int) (clone $query)->count();
        $rows = $query->with('user')
            ->orderByDesc('pinned')
            ->orderBy('floor')
            ->orderBy('id')
            ->forPage($page, $perPage)
            ->get()
            ->all();

        $items = [];
        $topIds = [];
        foreach ($rows as $row) {
            $topIds[] = (int) $row->id;
            $items[(int) $row->id] = $this->present($row, $user);
        }

        if ($topIds !== []) {
            $children = $this->visibleQuery($articleType, $slug, $user)
                ->whereIn('parent_id', $topIds)
                ->with('user')
                ->orderBy('id')
                ->get();
            foreach ($children as $child) {
                $parentId = (int) $child->parent_id;
                $payload = $this->present($child, $user);
                $payload['replyTo'] = isset($items[$parentId])
                    ? $items[$parentId]['user']['name']
                    : '';
                $items[$parentId]['replies'][] = $payload;
            }
        }

        return [
            'mode' => $this->mode($articleType, $slug) ?? 'open',
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'items' => array_values($items),
        ];
    }

    /**
     * 发表评论；审核模式下落 pending（作者本人可见）。
     *
     * @return array<string, mixed> 出错时 ['error' => ..., 'code' => ...]
     */
    public function create(User $user, string $articleType, string $slug, string $content, int $parentId = 0): array
    {
        $mode = $this->mode($articleType, $slug);
        if ($mode === null) {
            return ['error' => '文章不存在', 'code' => 404];
        }
        if ($mode === 'closed') {
            return ['error' => '本文已关闭评论', 'code' => 403];
        }

        $parent = null;
        if ($parentId > 0) {
            $parent = Comment::query()->find($parentId);
            if (! $parent
                || (string) $parent->article_type !== $articleType
                || (string) $parent->article_slug !== $slug
                || $parent->parent_id !== null
                || (string) $parent->status !== 'approved') {
                return ['error' => '回复对象不存在或不可回复', 'code' => 422];
            }
        }

        $floor = 0;
        if ($parent === null) {
            $floor = (int) Comment::query()
                ->where('article_type', $articleType)
                ->where('article_slug', $slug)
                ->whereNull('parent_id')
                ->max('floor') + 1;
        }

        $comment = Comment::create([
            'user_id' => (int) $user->id,
            'article_type' => $articleType,
            'article_slug' => mb_substr($slug, 0, 191),
            'parent_id' => $parent?->id,
            'floor' => $floor,
            'content' => $content,
            'status' => $mode === 'review' ? 'pending' : 'approved',
        ]);

        return ['comment' => $comment, 'status' => (string) $comment->status];
    }

    /** 删除评论及其楼中楼回复。 */
    public function remove(Comment $comment): bool
    {
        Comment::query()->where('parent_id', (int) $comment->id)->delete();

        return (bool) $comment->delete();
    }

    public function pin(Comment $comment, bool $pinned): Comment
    {
        $comment->pinned = $pinned;
        $comment->save();

        return $comment;
    }

    public function approve(Comment $comment): Comment
    {
        $comment->status = 'approved';
        $comment->save();

        return $comment;
    }

    /** 后台列表（可按状态/栏目筛选），含全部待审与他人评论。 */
    public function adminList(string $status = '', string $articleType = '', int $page = 1, int $perPage = 20, array $filters = []): array
    {
        AdminDashboardService::authorize();
        if (!in_array($status, ['', 'pending', 'approved'], true) || !in_array($articleType, ['', ...self::ARTICLE_TYPES], true)) {
            throw new \RuntimeException('评论筛选条件无效', 422);
        }
        foreach (['q', 'articleSlug', 'userQ'] as $field) {
            if (array_key_exists($field, $filters) && !is_string($filters[$field])) throw new \RuntimeException('评论筛选条件无效', 422);
        }
        $userId = array_key_exists('userId', $filters) ? $filters['userId'] : '';
        if ($userId !== '' && ((!is_int($userId) && !is_string($userId)) || !preg_match('/^[1-9][0-9]*$/D', (string) $userId) || filter_var($userId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false)) {
            throw new \RuntimeException('用户ID必须为正整数', 422);
        }
        $perPage = max(1, min(100, $perPage));

        $query = Comment::query()->with('user')
            ->when($status !== '', fn (Builder $q) => $q->where('status', $status))
            ->when($articleType !== '', fn (Builder $q) => $q->where('article_type', $articleType));

        $keyword = trim($filters['q'] ?? '');
        if ($keyword !== '') {
            $like = '%' . strtr($keyword, ['=' => '==', '%' => '=%', '_' => '=_']) . '%';
            $query->whereRaw("content LIKE ? ESCAPE '='", [$like]);
        }
        $slug = trim($filters['articleSlug'] ?? '');
        if ($slug !== '') $query->where('article_slug', $slug);
        if ($userId !== '') $query->where('user_id', (int) $userId);
        $userQ = trim($filters['userQ'] ?? '');
        if ($userQ !== '') {
            $like = '%' . strtr($userQ, ['=' => '==', '%' => '=%', '_' => '=_']) . '%';
            $query->whereHas('user', function (Builder $user) use ($like) {
                $user->where(function (Builder $user) use ($like) {
                    $user->whereRaw("display_name LIKE ? ESCAPE '='", [$like])->orWhereRaw("email LIKE ? ESCAPE '='", [$like]);
                });
            });
        }

        $total = (int) (clone $query)->count();
        $page = min(max(1, $page), max(1, (int) ceil($total / $perPage)));
        $rows = $query->orderByDesc('id')
            ->forPage($page, $perPage)
            ->get()
            ->all();

        $items = array_map(fn (Comment $row) => $this->presentAdmin($row), $rows);

        return ['items' => $items, 'total' => $total, 'page' => $page, 'perPage' => $perPage];
    }

    private function article(string $articleType, string $slug): ?object
    {
        $model = self::ARTICLE_MODELS[$articleType] ?? null;
        if ($model === null) {
            return null;
        }

        return $model::query()->where('slug', $slug)->first();
    }

    private function visibleQuery(string $articleType, string $slug, User $user): Builder
    {
        return Comment::query()
            ->where('article_type', $articleType)
            ->where('article_slug', $slug)
            ->where(function (Builder $q) use ($user) {
                $q->where('status', 'approved')
                    ->orWhere(function (Builder $qq) use ($user) {
                        $qq->where('status', 'pending')->where('user_id', (int) $user->id);
                    });
            });
    }

    private function present(Comment $comment, User $user): array
    {
        $owner = $comment->user;

        return [
            'id' => (int) $comment->id,
            'floor' => (int) $comment->floor,
            'content' => (string) $comment->content,
            'status' => (string) $comment->status,
            'pinned' => (bool) $comment->pinned,
            'parentId' => $comment->parent_id !== null ? (int) $comment->parent_id : null,
            'createdAt' => (string) $comment->created_at,
            'isMine' => (int) $comment->user_id === (int) $user->id,
            'replies' => [],
            'user' => $this->presentUser($owner),
        ];
    }

    private function presentAdmin(Comment $comment): array
    {
        return [
            'id' => (int) $comment->id,
            'articleType' => (string) $comment->article_type,
            'articleSlug' => (string) $comment->article_slug,
            'floor' => (int) $comment->floor,
            'content' => (string) $comment->content,
            'status' => (string) $comment->status,
            'pinned' => (bool) $comment->pinned,
            'parentId' => $comment->parent_id !== null ? (int) $comment->parent_id : null,
            'createdAt' => (string) $comment->created_at,
            'user' => $this->presentUser($comment->user),
        ];
    }

    private function presentUser(?User $owner): array
    {
        return [
            'id' => (int) ($owner->id ?? 0),
            'name' => (string) ($owner->display_name ?? '已注销'),
            'role' => (string) ($owner->role ?? 'user'),
            'userGroup' => UserGroup::normalize($owner ? (string) $owner->user_group : 'user'),
        ];
    }
}
