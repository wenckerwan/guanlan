<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\AnalysisReleaseMaintenanceService;
use Hyperf\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class PublishAnalysisReleaseCommand extends Command
{
    public function __construct(private AnalysisReleaseMaintenanceService $maintenance)
    {
        parent::__construct('analysis:publish-release');
    }

    protected function configure(): void
    {
        $this->setDescription('Review, apply or roll back the November analysis article release')
            ->addOption('apply', null, InputOption::VALUE_REQUIRED, 'Approval token from a read-only review')
            ->addOption('rollback', null, InputOption::VALUE_NONE, 'Restore only the fields changed by this release')
            ->addOption('receipt', null, InputOption::VALUE_REQUIRED, 'Absolute path in a private directory');
    }

    public function handle(): int
    {
        $token = $this->option('apply');
        $rollback = $this->option('rollback');
        $receipt = $this->option('receipt');
        if (($rollback && $token !== null)
            || (($rollback || $token !== null) !== ($receipt !== null))
            || ($token !== null && !preg_match('/\A[a-f0-9]{64}\z/', $token))) {
            $this->error('应用和回退互斥，均需要 --receipt；应用还需要有效的预检凭据。');
            return self::INVALID;
        }
        if ($rollback || $token !== null) {
            $count = $rollback ? $this->maintenance->rollback($receipt) : $this->maintenance->apply($token, $receipt);
            $this->line(json_encode(['changed' => $count], JSON_THROW_ON_ERROR));
            return self::SUCCESS;
        }
        $report = $this->maintenance->review();
        $this->output->writeln(
            json_encode($report, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            OutputInterface::OUTPUT_RAW,
        );
        return $report['blockers'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
