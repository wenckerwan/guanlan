<?php
declare(strict_types=1);

namespace App\Command;

use App\Model\Prediction;
use App\Seeder\DatasetManifestVerifier;
use App\Support\ContentMaintenance;
use App\Support\PredictionBodySync;
use Hyperf\Command\Command;
use RuntimeException;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class SyncPredictionBodiesCommand extends Command
{
    public function __construct()
    {
        parent::__construct('content:sync-predictions');
    }

    protected function configure(): void
    {
        $this->setDescription('Review or sync prediction article bodies without importing other content')
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Verified storage/dataset/predictions.json')
            ->addOption('only', null, InputOption::VALUE_REQUIRED, 'Limit synchronization to this slug')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Apply the planned edits')
            ->addOption('receipt', null, InputOption::VALUE_REQUIRED, 'Absolute receipt path')
            ->addOption('rollback', null, InputOption::VALUE_NONE, 'Restore a recorded update');
    }

    public function handle(): int
    {
        $apply = $this->option('apply');
        $rollback = $this->option('rollback');
        $only = $this->option('only');
        $receipt = $this->option('receipt');
        $source = $this->option('source');
        if (($rollback && ($apply || $only !== null || $source !== null))
            || (($apply || $rollback) !== ($receipt !== null))
            || ($receipt !== null && !str_starts_with($receipt, '/'))) {
            $this->error('Use --apply or --rollback with an absolute --receipt path.');
            return self::INVALID;
        }
        if ($rollback) {
            if (!is_file($receipt) || is_link($receipt) || (fileperms($receipt) & 0777) !== 0600) {
                throw new RuntimeException('Rollback requires a private regular receipt (0600)');
            }
            $data = json_decode((string) file_get_contents($receipt), true, 512, JSON_THROW_ON_ERROR);
            if (($data['format'] ?? null) !== 1 || empty($data['entries']) || !array_is_list($data['entries'])) {
                throw new RuntimeException('Invalid rollback receipt');
            }
            $report = ContentMaintenance::write('predictions', function () use ($data) {
                $entries = PredictionBodySync::restore($this->rows(true), $data['entries']);
                $this->writeRows($entries);
                return ['restored'=>count($entries)];
            });
        } else {
            $sources = $this->readSource($source ?? BASE_PATH.'/storage/dataset/predictions.json');
            if ($apply) {
                $report = ContentMaintenance::write('predictions', function () use ($sources, $only, $receipt) {
                    $plan = PredictionBodySync::plan($this->rows(true), $sources, $only);
                    if (!$plan['entries']) return null;
                    $this->saveReceipt($receipt, $plan['entries']);
                    $this->writeRows($plan['entries']);
                    return $plan;
                }) ?? PredictionBodySync::plan($this->rows(false), $sources, $only);
            } else {
                $report = PredictionBodySync::plan($this->rows(false), $sources, $only);
            }
            unset($report['entries']);
        }
        $this->output->writeln(json_encode($report, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
        return self::SUCCESS;
    }

    private function readSource(string $source): array
    {
        $path = realpath($source);
        if ($path === false || !str_ends_with($path, '/storage/dataset/predictions.json')) {
            throw new RuntimeException('Source must resolve to storage/dataset/predictions.json in a verified bundle');
        }
        $root = dirname($path, 3);
        $manifest = json_decode((string) file_get_contents($root.'/storage/dataset-manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        $verified = (new DatasetManifestVerifier())->verify($root);
        $bytes = file_get_contents($path);
        if ($bytes === false || in_array('predictions.json', $verified['skipped'], true)
            || hash('sha256', $bytes) !== ($manifest['datasets']['predictions.json']['sha256'] ?? null)) {
            throw new RuntimeException('Predictions source did not match the validated manifest');
        }
        $sources = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($sources) || !array_is_list($sources)) throw new RuntimeException('Invalid predictions source');
        return $sources;
    }

    private function rows(bool $lock): array
    {
        $q = Prediction::query()->orderBy('id');
        return array_map(static function (Prediction $p): array {
            return [...$p->getAttributes(), 'id'=>(int) $p->id, 'word_count'=>(int) $p->word_count, 'outline'=>$p->outline];
        }, ($lock ? $q->lockForUpdate() : $q)->get()->all());
    }

    private function writeRows(array $entries): void
    {
        foreach ($entries as $entry) {
            $model = Prediction::query()->findOrFail($entry['id']);
            $model->timestamps = false;
            $model->fill($entry['after']);
            if (!$model->save()) throw new RuntimeException('Unable to save prediction');
        }
    }

    private function saveReceipt(string $path, array $entries): void
    {
        $bytes = json_encode(['format'=>1, 'entries'=>$entries], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
        $mask = umask(0077);
        try { $file = fopen($path, 'xb'); } finally { umask($mask); }
        if ($file === false) throw new RuntimeException('Cannot create receipt; existing receipts are never overwritten');
        try {
            if (!chmod($path, 0600) || fwrite($file, $bytes) !== strlen($bytes) || !fsync($file)) {
                throw new RuntimeException('Receipt was not safely written');
            }
        } finally { fclose($file); }
    }
}
