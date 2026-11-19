<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\AnalysisArticle;
use App\Seeder\DatasetReader;
use App\Support\AnalysisRelease;
use App\Support\AnalysisReleasePlan;
use App\Support\ContentMaintenance;
use Hyperf\DbConnection\Db;
use RuntimeException;

final class AnalysisReleaseMaintenanceService
{
    public function review(): array
    {
        $models = $this->models(false);
        $plan = AnalysisReleasePlan::prepare($this->rows($models), $this->sources());
        $plan['articles'] = array_map(static fn (AnalysisArticle $article) => [
            'id' => (int) $article->id, 'slug' => $article->slug, 'title' => $article->title,
            'status' => $article->status, 'revision' => (int) $article->revision,
            'category' => $article->category, 'release' => (bool) $article->release,
            'sort_order' => $article->sort_order,
        ], $models);
        foreach (AnalysisRelease::RETIRED_SLUGS as $slug) {
            $plan['references'][$slug] = [
                'favorites' => Db::table('favorites')->where(function ($query) use ($slug) {
                    $query->where('target_id', $slug)->orWhere('url', '/analysis/'.$slug);
                })->count(),
                'notes' => Db::table('notes')->where('target_id', $slug)->count(),
                'comments' => Db::table('comments')->where('article_type', 'analysis')->where('article_slug', $slug)->count(),
                'progress' => Db::table('study_progress')->where('ref', $slug)->count(),
            ];
        }
        unset($plan['entries']);
        return $plan;
    }

    public function apply(string $token, string $receipt): int
    {
        return ContentMaintenance::withLock('analysis_articles', function () use ($token, $receipt) {
            $models = $this->models(true);
            $plan = AnalysisReleasePlan::prepare($this->rows($models), $this->sources());
            if ($plan['blockers'] !== []) {
                throw new RuntimeException(implode('；', $plan['blockers']));
            }
            if (!hash_equals($plan['token'], $token)) {
                throw new RuntimeException('预检后数据库或数据集已变化，请重新预检');
            }
            if ($plan['entries'] === []) {
                return 0;
            }
            $this->saveReceipt($receipt, ['format' => 1, 'token' => $token, 'entries' => $plan['entries']]);
            return $this->write($models, $plan['changes']);
        });
    }

    public function rollback(string $receipt): int
    {
        $path = $this->privatePath($receipt);
        if (is_link($path) || !is_file($path) || (fileperms($path) & 0077) !== 0) {
            throw new RuntimeException('回退凭据必须是仅维护者可读的普通文件');
        }
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (($data['format'] ?? null) !== 1 || empty($data['entries']) || !array_is_list($data['entries'])) {
            throw new RuntimeException('回退凭据格式无效');
        }
        return ContentMaintenance::withLock('analysis_articles', function () use ($data) {
            $models = $this->models(true);
            return $this->write($models, AnalysisReleasePlan::rollback($this->rows($models), $data['entries']));
        });
    }

    private function models(bool $lock): array
    {
        $query = AnalysisArticle::query()->orderBy('id');
        return ($lock ? $query->lockForUpdate() : $query)->get()->all();
    }

    private function rows(array $models): array
    {
        return array_map(static fn (AnalysisArticle $model) => $model->getAttributes(), $models);
    }

    private function sources(): array
    {
        return DatasetReader::list('analysis_articles.json');
    }

    private function write(array $models, array $changes): int
    {
        $byId = [];
        foreach ($models as $model) {
            $byId[(int) $model->id] = $model;
        }
        foreach ($changes as $change) {
            $model = $byId[$change['id']];
            AdminArticleHistoryService::captureBaseline('analysis', $model);
            foreach ($change['fields'] as $field => $value) {
                $model->$field = $value;
            }
            $model->revision = (int) $model->revision + 1;
            if (!$model->save()) {
                throw new RuntimeException('文章变更保存失败：'.$model->slug);
            }
            AdminArticleHistoryService::capture('analysis', $model->refresh(), null);
        }
        return count($changes);
    }

    private function privatePath(string $path): string
    {
        $dir = realpath(dirname($path));
        if (!str_starts_with($path, '/') || $dir === false || !is_dir($dir) || (fileperms($dir) & 0077) !== 0) {
            throw new RuntimeException('凭据需要写入维护者专用目录，请使用绝对路径和目录权限 0700');
        }
        return $dir.'/'.basename($path);
    }

    private function saveReceipt(string $path, array $data): void
    {
        $path = $this->privatePath($path);
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
        $file = fopen($path, 'xb');
        if ($file === false) {
            throw new RuntimeException('无法创建新凭据，禁止覆盖：'.$path);
        }
        try {
            if (!chmod($path, 0600) || fwrite($file, $json) !== strlen($json) || !fsync($file)) {
                throw new RuntimeException('凭据写入失败，发布未应用');
            }
        } finally {
            fclose($file);
        }
    }
}
