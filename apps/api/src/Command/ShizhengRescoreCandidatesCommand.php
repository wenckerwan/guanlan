<?php

declare(strict_types=1);

namespace App\Command;

use App\Model\ShizhengCandidate;
use App\Service\ShizhengSimilarityService;
use Hyperf\Command\Command as HyperfCommand;
use Symfony\Component\Console\Input\InputArgument;

/**
 * 给已入库的时政候选重算真题相关度。
 *
 * 用途：新列上线后回填历史候选；题库有改动（后台改题、加年份）后刷新分数。
 * 不重新抓取、不动 payload，只重写 exam_sim / exam_affinity / exam_matches 三列。
 *
 * 用法：php bin/hyperf.php shizheng:rescore 2026-09-30
 *       php bin/hyperf.php shizheng:rescore            # 全部日期
 */
class ShizhengRescoreCandidatesCommand extends HyperfCommand
{
    protected ?string $name = 'shizheng:rescore';

    public function __construct(private ShizhengSimilarityService $similarity)
    {
        parent::__construct();
    }

    public function configure(): void
    {
        parent::configure();
        $this->setDescription('重算时政候选的真题相关度（exam_sim/exam_affinity/exam_matches）');
        // 注意：mode 必须用 InputArgument::OPTIONAL 常量。传 0 会让 Symfony Console 抛
        // 「Argument mode "0" is not valid」，而 Hyperf 在 ApplicationFactory 阶段就实例化所有
        // 注册过的命令——命令配置写错不是「这条命令不能用」，而是整个 api 起不来。
        $this->addArgument('date', InputArgument::OPTIONAL,
            '只处理某一天 YYYY-MM-DD；省略则处理全部');
    }

    public function handle(): int
    {
        $date = (string) $this->input->getArgument('date');
        if ($date !== '' && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $this->error('date 格式应为 YYYY-MM-DD');
            return 1;
        }

        $query = ShizhengCandidate::query();
        if ($date !== '') {
            $query->where('publish_date', $date);
        }
        $rows = $query->get();
        if ($rows->isEmpty()) {
            $this->warn('没有匹配的候选行');
            return 0;
        }

        $start = microtime(true);
        $count = 0;
        foreach ($rows as $row) {
            $sim = $this->similarity->scorePayload((array) $row->payload);
            $row->exam_sim = $sim['exam_sim'];
            $row->exam_affinity = $sim['exam_affinity'];
            $row->exam_matches = $sim['exam_matches'];
            $row->save();
            $count++;
        }

        $this->info(sprintf('已重算 %d 条候选，耗时 %.1f 秒', $count, microtime(true) - $start));
        $top = $rows->sortByDesc('exam_sim')->take(10)->values()->all();
        $this->line('相似度最高的 10 条：');
        foreach ($top as $r) {
            $this->line(sprintf('  %.4f  %s', (float) $r->exam_sim, mb_substr((string) $r->title, 0, 48)));
        }
        return 0;
    }
}
