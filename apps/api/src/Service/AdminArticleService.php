<?php
declare(strict_types=1);
namespace App\Service;

use App\Model\AnalysisArticle;
use App\Model\Hotspot;
use App\Model\User;
use App\Resource\ArticleResource;
use App\Support\Auth;
use App\Support\ContentMaintenance;
use App\Support\ContentStatus;

class AdminArticleService
{
    public function __construct(private ArticleRenderer $renderer)
    {
    }

    private function authorize(): void
    {
        $snapshot = Auth::user();
        $user = $snapshot ? User::find($snapshot->id) : null;
        if (!$user || $user->role !== 'admin' || !$user->isActive()) {
            throw new \RuntimeException('需要有效的管理员身份', 403);
        }
    }

    private function model(string $kind): string
    {
        return match ($kind) {
            'hotspot' => Hotspot::class,
            'analysis' => AnalysisArticle::class,
            default => throw new \RuntimeException('文章类型无效', 422),
        };
    }

    public function detail(string $kind, int $id): array
    {
        $this->authorize();
        $class = $this->model($kind);
        $model = $class::find($id);
        if (!$model) throw new \RuntimeException('记录不存在', 404);
        return ArticleResource::adminDetail($model);
    }

    public function preview(array $data): array
    {
        $this->authorize();
        if (!isset($data['format']) || !is_string($data['format']) || !array_key_exists('body', $data) || !is_string($data['body'])) {
            throw new \RuntimeException('请提供正文格式和正文', 422);
        }
        return $this->renderer->render($data['format'], $data['body']);
    }

    public function save(string $kind, array $data, ?int $id = null): Hotspot|AnalysisArticle
    {
        $this->authorize();
        $class = $this->model($kind);
        $table = $kind === 'hotspot' ? 'hotspots' : 'analysis_articles';
        return ContentMaintenance::write($table, function () use ($kind, $class, $data, $id) {
            $model = $id !== null ? $class::query()->where('id', $id)->lockForUpdate()->first() : new $class();
            if (!$model) throw new \RuntimeException('记录不存在', 404);
            $revision = (int) ($model->revision ?? 1);
            $hasBody = array_key_exists('body', $data) || array_key_exists('html', $data) || array_key_exists('format', $data);
            // Metadata-only legacy list actions remain supported. Every supplied revision is checked.
            if ($id !== null && $hasBody && !array_key_exists('expectedRevision', $data)) {
                throw new \RuntimeException('编辑正文需要版本号', 422);
            }
            if (array_key_exists('expectedRevision', $data)) {
                if (!is_int($data['expectedRevision']) || $data['expectedRevision'] < 1) {
                    throw new \RuntimeException('版本号无效', 422);
                }
                if ($id !== null && $data['expectedRevision'] !== $revision) {
                    throw new \RuntimeException('文章已被修改，请重新读取后合并', 409);
                }
            }
            $title = $data['title'] ?? $model->title ?? '';
            if (!is_string($title) || trim($title) === '' || mb_strlen($title) > 191) {
                throw new \RuntimeException('标题不能为空或超过191字', 422);
            }
            try {
                $status = ContentStatus::forWrite($data, $model->status ?? null);
            } catch (\RuntimeException $e) {
                throw new \RuntimeException($e->getMessage(), 422);
            }
            $fields = $kind === 'hotspot' ? ['summary','level','priority','type','tag','period'] : ['summary','category'];
            foreach ($fields as $field) {
                if (array_key_exists($field, $data)) {
                    if (!is_string($data[$field])) throw new \RuntimeException('文章元数据无效', 422);
                    $model->$field = $data[$field];
                }
            }
            if ($kind === 'hotspot' && array_key_exists('subjectId', $data)) $model->subject_id = (int) $data['subjectId'];
            if (!$model->exists && $kind === 'hotspot' && !$model->subject_id) $model->subject_id = 1;
            $model->title = $title;
            $model->status = $status;
            if ($hasBody) {
                $format = $data['format'] ?? 'html';
                $body = $data['body'] ?? $data['html'] ?? null;
                if (!is_string($format) || !is_string($body)) throw new \RuntimeException('请提供正文格式和正文', 422);
                $rendered = $this->renderer->render($format, $body);
                $model->html = $rendered['html'];
                $model->outline = $rendered['outline'];
                $model->word_count = $rendered['wordCount'];
                $model->markdown = $format === 'markdown' ? $body : null;
            } elseif (!$model->exists) {
                $model->html = '';
                $model->outline = [];
                $model->word_count = 0;
            }
            if (!$model->slug) $model->slug = 'admin-'.bin2hex(random_bytes(6));
            $model->revision = $id !== null ? $revision + 1 : 1;
            $model->content_source = 'admin';
            $model->save();
            return $model;
        });
    }
}
