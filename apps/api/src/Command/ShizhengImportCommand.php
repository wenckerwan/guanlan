<?php

declare(strict_types=1);

namespace App\Command;

use App\Seeder\DatasetManifestVerifier;
use App\Seeder\HotspotDatasetImporter;
use App\Support\ContentMaintenance;
use Hyperf\Command\Command;
use Hyperf\DbConnection\Db;
use Symfony\Component\Console\Input\InputOption;

/**
 * 只导入时政长文（hotspots），不碰真题分析 / 时政预测。
 *
 * 内容部署走这条命令：dataset 与 manifest 一起更新后执行，
 * 由 DatasetManifestVerifier 先校验完整性，再整表重灌（幂等，按 slug 唯一）。
 */
final class ShizhengImportCommand extends Command
{
    public function __construct()
    {
        parent::__construct('shizheng:import');
    }

    protected function configure(): void
    {
        $this->setDescription('导入 storage/dataset/hotspots.json 的时政事件条目（只影响 hotspots 表）')
            ->addOption('force', null, InputOption::VALUE_NONE, '先解除 hotspots 的导入保护（maintained 标记）；仅在已备份数据库时使用');
    }

    public function handle(): int
    {
        try {
            (new DatasetManifestVerifier())->verify();
        } catch (\Throwable $exception) {
            $this->error('dataset 校验失败：' . $exception->getMessage());
            return self::FAILURE;
        }

        if ((bool) $this->option('force')) {
            Db::table('content_maintenance')
                ->where('table_name', 'hotspots')
                ->update(['maintained' => 0, 'updated_at' => date('Y-m-d H:i:s')]);
            $this->line('[shizheng] 已解除 hotspots 导入保护');
        }

        $count = 0;
        try {
            ContentMaintenance::import(['hotspots'], function () use (&$count): void {
                $count = (new HotspotDatasetImporter())->import();
            });
        } catch (\RuntimeException $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }

        $this->line('[shizheng] 已导入 ' . $count . ' 条时政事件');
        return self::SUCCESS;
    }
}
