<?php

declare(strict_types=1);

namespace App\Command;

use App\Seeder\DatasetManifestVerifier;
use App\Seeder\PredictionDatasetImporter;
use App\Support\ContentMaintenance;
use Hyperf\Command\Command;
use Hyperf\DbConnection\Db;
use Symfony\Component\Console\Input\InputOption;

/**
 * 只导入时政预测（predictions），不碰时政热点与真题分析。
 *
 * 预测条目按题位（Q15 / Q16 / Q32 / Q33 / Q34—Q38）拆分，layer 与 sort_order 决定分组与顺序。
 */
final class PredictImportCommand extends Command
{
    public function __construct()
    {
        parent::__construct('predict:import');
    }

    protected function configure(): void
    {
        $this->setDescription('导入 storage/dataset/predictions.json 的时政预测条目（只影响 predictions 表）')
            ->addOption('force', null, InputOption::VALUE_NONE, '先解除 predictions 的导入保护（maintained 标记）；仅在已备份数据库时使用');
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
                ->where('table_name', 'predictions')
                ->update(['maintained' => 0, 'updated_at' => date('Y-m-d H:i:s')]);
            $this->line('[predict] 已解除 predictions 导入保护');
        }

        $count = 0;
        try {
            ContentMaintenance::import(['predictions'], function () use (&$count): void {
                $count = (new PredictionDatasetImporter())->import();
            });
        } catch (\RuntimeException $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }

        $this->line('[predict] 已导入 ' . $count . ' 条时政预测');
        return self::SUCCESS;
    }
}
