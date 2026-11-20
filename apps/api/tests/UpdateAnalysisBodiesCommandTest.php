<?php
declare(strict_types=1);

namespace App\Support {
    final class ContentMaintenance
    {
        public static function withLock(string $table, callable $callback): mixed
        {
            if ($table !== 'analysis_articles') throw new \RuntimeException('Unexpected table');
            return $callback();
        }
    }
}
namespace App\Model {
    final class AnalysisArticle
    {
        public static array $rows = [];
        public function __construct(
            public string $slug, public int $revision = 4, public string $html = '<p>old</p>',
            public ?array $outline = null, public int $word_count = 3,
            public ?string $markdown = null, public string $content_source = 'admin',
            public string $title = 'Preserved',
        ) {}
        public static function query(): object { return new self(''); }
        public function where(string $key, string $slug): self { $this->slug = $slug; return $this; }
        public function lockForUpdate(): self { return $this; }
        public function first(): ?self { return self::$rows[$this->slug] ?? null; }
        public function save(): bool { return true; }
    }
}
namespace App\Service {
    final class ArticleRenderer
    {
        public function render(string $format, string $body): array
        {
            return ['html' => "<p>$body</p>", 'outline' => [], 'wordCount' => strlen($body)];
        }
    }
    final class AdminArticleHistoryService
    {
        public static array $history = [];
        public static function captureBaseline(string $kind, object $row): void
        {
            self::$history[$row->slug][$row->revision] ??= [null, $row->html];
        }
        public static function capture(string $kind, object $row, ?int $actor): void
        {
            self::$history[$row->slug][$row->revision] = [$actor, $row->html];
        }
    }
}
namespace Hyperf\Command {
    class Command
    {
        public const SUCCESS = 0;
        public array $options = [];
        protected object $output;
        public array $report;
        public function __construct(string $name) { $this->output = $this; }
        public function option(string $name): mixed { return $this->options[$name] ?? null; }
        public function writeln(string $line, int $flags): void { $this->report = json_decode($line, true, 512, JSON_THROW_ON_ERROR); }
    }
}
namespace Symfony\Component\Console\Output {
    interface OutputInterface { public const OUTPUT_RAW = 1; }
}
namespace {
    use App\Command\UpdateAnalysisBodiesCommand;
    use App\Model\AnalysisArticle;
    use App\Service\AdminArticleHistoryService;
    use App\Service\ArticleRenderer;

    require dirname(__DIR__).'/src/Command/UpdateAnalysisBodiesCommand.php';
    function check(bool $ok, string $message): void
    {
        if (!$ok) throw new RuntimeException($message);
    }
    function reject(callable $action, string $message): void
    {
        try { $action(); } catch (RuntimeException) { return; }
        throw new RuntimeException($message);
    }
    $slug = 'ocr-5274fe86';
    $row = AnalysisArticle::$rows[$slug] = new AnalysisArticle($slug);
    $dir = sys_get_temp_dir().'/analysis-sync-'.bin2hex(random_bytes(8));
    mkdir($dir, 0700);
    $payload = "$dir/payload.json";
    $receipt = "$dir/receipt.json";
    $command = new UpdateAnalysisBodiesCommand(new ArticleRenderer());
    $run = static function (array $options) use ($command): array {
        $command->options = $options;
        check($command->handle() === 0, 'command failed');
        return $command->report;
    };
    try {
        $item = ['slug' => $slug, 'format' => 'markdown', 'body' => 'new body'];
        file_put_contents($payload, json_encode(['items' => [$item]]));
        $plan = $run(['payload' => $payload]);
        check($plan === ['changes' => [['slug' => $slug, 'fields' => ['html','outline','word_count','markdown']]], 'unchanged' => []], 'wrong preview');
        check($row->revision === 4 && !AdminArticleHistoryService::$history, 'preview wrote data');
        reject(fn() => $run(['payload' => $payload, 'apply' => true]), 'apply accepted without receipt');
        $run(['payload' => $payload, 'apply' => true, 'receipt' => $receipt]);
        check($row->revision === 5 && $row->title === 'Preserved' && $row->markdown === 'new body', 'apply metadata or body mismatch');
        check(AdminArticleHistoryService::$history[$slug] === [4 => [null, '<p>old</p>'], 5 => [null, '<p>new body</p>']], 'wrong history');
        check((fileperms($receipt) & 0777) === 0600, 'receipt permissions');
        check($run(['payload' => $payload]) === ['changes' => [], 'unchanged' => [$slug]], 'no-op plan mismatch');
        $row->revision = 6;
        reject(fn() => $run(['rollback' => true, 'receipt' => $receipt]), 'accepted stale receipt');
        check($row->markdown === 'new body', 'conflict wrote data');
        $row->revision = 5;
        $run(['rollback' => true, 'receipt' => $receipt]);
        check($row->revision === 6 && $row->markdown === null && $row->outline === null && $row->html === '<p>old</p>', 'rollback did not restore originals');
        check(AdminArticleHistoryService::$history[$slug][6] === [null, '<p>old</p>'], 'rollback history missing');
        file_put_contents($payload, json_encode(['items' => [$item, $item]]));
        reject(fn() => $run(['payload' => $payload]), 'accepted duplicate');
        $item['slug'] = 'another-article';
        file_put_contents($payload, json_encode(['items' => [$item]]));
        reject(fn() => $run(['payload' => $payload]), 'accepted unrelated slug');
        echo "PASS analysis body preview, apply, no-op, history, rollback and scope\n";
    } finally {
        foreach ([$receipt, $payload] as $path) if (is_file($path)) unlink($path);
        rmdir($dir);
    }
}
