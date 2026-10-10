<?php

declare(strict_types=1);

namespace App\Controller;

use App\Model\Comment;
use App\Service\AdminAuditService;
use App\Service\CommentService;
use App\Support\ApiResponse;
use App\Support\Auth;
use App\Support\Validator;
use Hyperf\HttpServer\Contract\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class CommentController
{
    public function __construct(
        private CommentService $service,
        private AdminAuditService $audit,
        private RequestInterface $request
    ) {
    }

    /** 评论列表（仅登录用户；游客由路由中间件 401 拦截） */
    public function index(): ResponseInterface
    {
        $user = Auth::user();
        $articleType = (string) $this->request->input('articleType', '');
        $slug = trim((string) $this->request->input('slug', ''));
        if (! in_array($articleType, CommentService::ARTICLE_TYPES, true) || $slug === '') {
            return ApiResponse::message('请求校验失败', 422, ['articleType' => '栏目与文章标识不能为空']);
        }

        $result = $this->service->list(
            $articleType,
            $slug,
            $user,
            (int) $this->request->input('page', 1),
            (int) $this->request->input('perPage', 20)
        );

        return ApiResponse::data($result);
    }

    /** 发表评论 / 回复（仅登录用户） */
    public function store(): ResponseInterface
    {
        $user = Auth::user();
        $articleType = (string) $this->request->input('articleType', '');
        $slug = trim((string) $this->request->input('slug', ''));

        $validator = new Validator($this->request->all());
        $validator->required('content', '评论内容')
            ->max('content', 1000, '评论内容');

        if ($validator->fails()
            || ! in_array($articleType, CommentService::ARTICLE_TYPES, true)
            || $slug === '') {
            return ApiResponse::message('请求校验失败', 422, $validator->errors());
        }

        $result = $this->service->create(
            $user,
            $articleType,
            $slug,
            trim($validator->string('content')),
            (int) $this->request->input('parentId', 0)
        );
        if (isset($result['error'])) {
            return ApiResponse::message((string) $result['error'], (int) $result['code']);
        }

        /** @var Comment $comment */
        $comment = $result['comment'];

        return ApiResponse::data([
            'id' => (int) $comment->id,
            'status' => (string) $comment->status,
        ], 201);
    }

    /** 删除评论：本人或管理员 */
    public function destroy(int $id): ResponseInterface
    {
        $user = Auth::user();

        $comment = Comment::query()->find($id);
        if (! $comment) {
            return ApiResponse::message('评论不存在', 404);
        }

        if ((int) $comment->user_id !== (int) $user->id && ! $user->isAdmin()) {
            return ApiResponse::message('只能删除自己的评论', 403);
        }

        $this->service->remove($comment);
        if ($user->isAdmin() && (int) $comment->user_id !== (int) $user->id) {
            $this->audit->log($user, 'comment.delete', 'comment', (string) $id, [
                'articleType' => (string) $comment->article_type,
                'articleSlug' => (string) $comment->article_slug,
            ]);
        }

        return ApiResponse::message('已删除', 200);
    }

    /** 后台：评论列表（status=pending 过滤待审） */
    public function adminIndex(): ResponseInterface
    {
        try {
            $params = [];
            foreach (['status', 'articleType', 'q', 'articleSlug', 'userQ'] as $field) {
                $value = $this->request->input($field, '');
                if (!is_string($value)) throw new \RuntimeException('评论筛选条件无效', 422);
                $params[$field] = trim($value);
            }
            foreach (['page' => 1, 'perPage' => 20] as $field => $default) {
                $value = $this->request->input($field, $default);
                if ((!is_int($value) && !is_string($value)) || !preg_match('/^-?[0-9]+$/D', (string) $value)) throw new \RuntimeException('分页参数无效', 422);
                $params[$field] = (int) $value;
            }
            $params['userId'] = $this->request->input('userId', '');
            return ApiResponse::data($this->service->adminList($params['status'], $params['articleType'], $params['page'], $params['perPage'], $params));
        } catch (\RuntimeException $e) {
            if (!in_array($e->getCode(), [403, 422], true)) throw $e;
            return ApiResponse::message($e->getMessage(), $e->getCode());
        }
    }

    /** 后台：置顶 / 取消置顶 / 审核通过 */
    public function adminUpdate(int $id): ResponseInterface
    {
        $action = (string) $this->request->input('action', '');

        $comment = Comment::query()->find($id);
        if (! $comment) {
            return ApiResponse::message('评论不存在', 404);
        }

        switch ($action) {
            case 'pin':
                if ($comment->parent_id !== null) {
                    return ApiResponse::message('只能置顶主楼评论', 422);
                }
                $comment = $this->service->pin($comment, true);
                break;
            case 'unpin':
                $comment = $this->service->pin($comment, false);
                break;
            case 'approve':
                $comment = $this->service->approve($comment);
                break;
            default:
                return ApiResponse::message('不支持的操作', 422);
        }

        $this->audit->log(Auth::user(), 'comment.' . $action, 'comment', (string) $id, [
            'articleType' => (string) $comment->article_type,
            'articleSlug' => (string) $comment->article_slug,
        ]);

        return ApiResponse::data(['ok' => true]);
    }

    /** 后台：删除任意评论（含楼中楼），写审计 */
    public function adminDestroy(int $id): ResponseInterface
    {
        $comment = Comment::query()->find($id);
        if (! $comment) {
            return ApiResponse::message('评论不存在', 404);
        }

        $this->service->remove($comment);
        $this->audit->log(Auth::user(), 'comment.delete', 'comment', (string) $id, [
            'articleType' => (string) $comment->article_type,
            'articleSlug' => (string) $comment->article_slug,
        ]);

        return ApiResponse::data(['ok' => true]);
    }

    /** 后台：设置文章评论模式（open 自动发布 / review 审核后发布 / closed 禁止评论） */
    public function setMode(): ResponseInterface
    {
        $articleType = (string) $this->request->input('articleType', '');
        $slug = trim((string) $this->request->input('slug', ''));
        $mode = (string) $this->request->input('mode', '');

        if (! in_array($articleType, CommentService::ARTICLE_TYPES, true)
            || $slug === ''
            || ! in_array($mode, CommentService::MODES, true)) {
            return ApiResponse::message('请求校验失败', 422);
        }

        if (! $this->service->setMode($articleType, $slug, $mode)) {
            return ApiResponse::message('文章不存在', 404);
        }

        $this->audit->log(Auth::user(), 'comment.mode', 'article', $slug, ['articleType' => $articleType, 'mode' => $mode]);

        return ApiResponse::data(['ok' => true]);
    }
}
