<?php
declare(strict_types=1);

namespace App\Command;

use App\Model\AnalysisArticle;
use App\Service\AdminArticleHistoryService;
use App\Service\ArticleRenderer;
use App\Support\ContentMaintenance;
use Hyperf\Command\Annotation\Command as AsCommand;
use Hyperf\Command\Command;
use RuntimeException;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand]
final class UpdateAnalysisBodiesCommand extends Command
{
    private const SLUGS = ['2012-2026-649d1f95', '2024-2026-59141b88', 'ocr-5274fe86'];

    public function __construct(private ArticleRenderer $renderer)
    {
        parent::__construct('content:update-analysis-bodies');
    }

    protected function configure(): void
    {
        $this->setDescription('Review, update or restore the three approved analysis article bodies')
            ->addOption('payload', null, InputOption::VALUE_REQUIRED, 'JSON containing Markdown article bodies')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Apply the planned edits')
            ->addOption('receipt', null, InputOption::VALUE_REQUIRED, 'Private receipt path')
            ->addOption('rollback', null, InputOption::VALUE_NONE, 'Restore a recorded update');
    }

    public function handle(): int
    {
        $payload = $this->option('payload');
        $receipt = $this->option('receipt');
        $apply = (bool) $this->option('apply');
        $rollback = (bool) $this->option('rollback');
        if (($rollback && ($apply || $payload !== null)) || (!$rollback && !$payload)
            || (($apply || $rollback) !== ($receipt !== null)) || $receipt === '') {
            throw new RuntimeException('Use --payload for preview; add --apply --receipt to write, or use --rollback --receipt.');
        }

        if ($rollback) {
            $report = $this->restore($receipt);
        } else {
            $items = $this->renderPayload($payload);
            $process = fn() => $this->update($items, $apply ? $receipt : null);
            $report = $apply ? ContentMaintenance::withLock('analysis_articles', $process) : $process();
        }
        $this->output->writeln(json_encode($report, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
        return self::SUCCESS;
    }

    private function readItems(string $path, bool $receipt = false): array
    {
        $bytes = file_get_contents($path);
        if ($bytes === false) throw new RuntimeException('Cannot read input file');
        $data = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        $items = $data['items'] ?? null;
        if (!is_array($items) || !array_is_list($items) || !$items
            || ($receipt && ($data['kind'] ?? null) !== 'analysis-body-update-v1')) {
            throw new RuntimeException('Expected a nonempty items list');
        }
        $keyed = [];
        foreach ($items as $item) {
            $slug = $item['slug'] ?? null;
            if (!in_array($slug, self::SLUGS, true) || isset($keyed[$slug])) {
                throw new RuntimeException('Unknown or duplicate analysis slug');
            }
            $keyed[$slug] = $item;
        }
        ksort($keyed);
        return $keyed;
    }

    private function renderPayload(string $path): array
    {
        $items = [];
        foreach ($this->readItems($path) as $slug => $item) {
            if (($item['format'] ?? null) !== 'markdown' || !is_string($item['body'] ?? null) || trim($item['body']) === '') {
                throw new RuntimeException('Expected a nonempty Markdown body for '.$slug);
            }
            $rendered = $this->renderer->render($item['format'], $item['body']);
            $items[$slug] = ['html' => $rendered['html'], 'outline' => $rendered['outline'],
                'word_count' => $rendered['wordCount'], 'markdown' => $item['body']];
        }
        return $items;
    }

    private function row(string $slug, bool $lock): AnalysisArticle
    {
        $query = AnalysisArticle::query()->where('slug', $slug);
        $model = ($lock ? $query->lockForUpdate() : $query)->first();
        if (!$model || $model->slug !== $slug) throw new RuntimeException('Analysis article not found: '.$slug);
        return $model;
    }

    private function update(array $items, ?string $receipt): array
    {
        $changes = $unchanged = $entries = $pending = [];
        foreach ($items as $slug => $fields) {
            $row = $this->row($slug, $receipt !== null);
            $old = $this->bodyFields($row);
            $different = [];
            foreach ($fields as $key => $value) {
                if ($value !== $old[$key]) $different[] = $key;
            }
            if (!$different) {
                $unchanged[] = $slug;
                continue;
            }
            $changes[] = ['slug' => $slug, 'fields' => $different];
            $entries[] = ['slug' => $slug, 'revision' => (int) $row->revision, 'old' => $old, 'applied' => $fields];
            $pending[] = [$row, $fields];
        }
        if ($receipt !== null && $pending) {
            $this->saveReceipt($receipt, $entries);
            foreach ($pending as [$row, $fields]) $this->saveRevision($row, $fields);
        }
        return compact('changes', 'unchanged');
    }

    private function restore(string $receipt): array
    {
        if (!is_file($receipt) || is_link($receipt) || (fileperms($receipt) & 0777) !== 0600) {
            throw new RuntimeException('Rollback requires a private regular receipt (0600)');
        }
        $items = $this->readItems($receipt, true);
        return ContentMaintenance::withLock('analysis_articles', function () use ($items) {
            $pending = [];
            foreach ($items as $slug => $item) {
                $row = $this->row($slug, true);
                $revision = $item['revision'] ?? null;
                $old = $item['old'] ?? null;
                if (!is_int($revision) || $revision < 1 || !is_array($old)
                    || array_diff(['html','outline','word_count','markdown'], array_keys($old))
                    || !is_string($old['html']) || ($old['outline'] !== null && !is_array($old['outline']))
                    || !is_int($old['word_count']) || ($old['markdown'] !== null && !is_string($old['markdown']))) {
                    throw new RuntimeException('Invalid receipt for '.$slug);
                }
                if ((int) $row->revision !== $revision + 1 || $this->bodyFields($row) !== ($item['applied'] ?? null)) {
                    throw new RuntimeException('Rollback revision conflict for '.$slug);
                }
                $pending[] = [$row, array_intersect_key($old, array_flip(['html','outline','word_count','markdown']))];
            }
            foreach ($pending as [$row, $fields]) $this->saveRevision($row, $fields);
            return ['restored' => array_keys($items)];
        });
    }

    private function bodyFields(AnalysisArticle $row): array
    {
        return ['html' => $row->html, 'outline' => $row->outline,
            'word_count' => (int) $row->word_count, 'markdown' => $row->markdown];
    }

    private function saveRevision(AnalysisArticle $row, array $fields): void
    {
        AdminArticleHistoryService::captureBaseline('analysis', $row);
        foreach ($fields as $field => $value) $row->$field = $value;
        $row->revision = (int) $row->revision + 1;
        $row->content_source = 'admin';
        if (!$row->save()) throw new RuntimeException('Cannot save analysis article '.$row->slug);
        AdminArticleHistoryService::capture('analysis', $row, null);
    }

    private function saveReceipt(string $path, array $items): void
    {
        $bytes = json_encode(['kind' => 'analysis-body-update-v1', 'items' => $items], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
        $mask = umask(0077);
        try { $handle = fopen($path, 'xb'); } finally { umask($mask); }
        if ($handle === false) throw new RuntimeException('Cannot create receipt; existing files are never overwritten');
        try {
            if (!chmod($path, 0600) || fwrite($handle, $bytes) !== strlen($bytes) || !fsync($handle)) {
                throw new RuntimeException('Cannot safely write receipt');
            }
        } finally { fclose($handle); }
    }
}
