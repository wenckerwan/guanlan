<?php

declare(strict_types=1);

use App\Model\AnalysisArticle;
use App\Service\AnalysisReleaseMaintenanceService;
use App\Support\AnalysisRelease;
use App\Support\ContentMaintenance;
use Hyperf\Database\ConnectionResolverInterface;
use Hyperf\Database\Model\Register;
use Hyperf\DbConnection\Db;

if (getenv('DB_DATABASE') !== 'guanlan_analysis_test' || getenv('DB_HOST') !== 'analysis-qa-db') {
    throw new RuntimeException('Disposable analysis database required');
}
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH.'/vendor/autoload.php';
$container = require BASE_PATH.'/config/container.php';
Register::setConnectionResolver($container->get(ConnectionResolverInterface::class));

$legacy = json_decode((string) file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
$directory = '/tmp/analysis-release-'.bin2hex(random_bytes(8));
mkdir($directory, 0700);

Swoole\Coroutine\run(function () use ($legacy, $directory): void {
    $assert = static function (bool $ok, string $message): void {
        if (!$ok) {
            throw new RuntimeException($message);
        }
    };
    $fails = static function (callable $operation, string $expected): void {
        try {
            $operation();
        } catch (Throwable $error) {
            if (str_contains($error->getMessage(), $expected)) {
                return;
            }
            throw $error;
        }
        throw new RuntimeException('Expected failure: '.$expected);
    };
    $service = new AnalysisReleaseMaintenanceService();
    $tables = ['analysis_articles', 'hotspots', 'predictions'];
    foreach ($legacy as $row) {
        $row = array_intersect_key($row, array_flip([
            'slug', 'title', 'category', 'release', 'priority', 'summary', 'html',
            'outline', 'source_file', 'word_count', 'sort_order',
        ]));
        $row['summary'] = mb_substr($row['summary'], 0, 500);
        AnalysisArticle::create($row);
    }
    Db::table('content_maintenance')->whereIn('table_name', $tables)->update(['maintained' => 1]);
    $snapshot = static fn (string $table) => json_encode(Db::table($table)->orderBy('id')->get());
    $markers = static fn () => json_encode(Db::table('content_maintenance')->orderBy('table_name')->get());
    $other = [$snapshot('hotspots'), $snapshot('predictions')];
    $release = static fn () => AnalysisArticle::query()->where('slug', AnalysisRelease::RELEASE_SLUG)->first();
    $retired = static fn () => AnalysisArticle::query()->whereIn('slug', AnalysisRelease::RETIRED_SLUGS)->orderBy('id')->get();
    $review = $service->review();
    $assert($review['blockers'] === [] && $review['visible_after'] === 11, 'baseline is not publishable');
    $assert(count($review['changes']) === 13, 'legacy MySQL data should yield 11 outline fixes and 2 retirements');

    $manual = AnalysisArticle::query()->where('slug', '!=', AnalysisRelease::RELEASE_SLUG)->orderBy('id')->first();
    $locked = new Swoole\Coroutine\Channel(1);
    $finishEdit = new Swoole\Coroutine\Channel(1);
    $editResult = new Swoole\Coroutine\Channel(1);
    $publishResult = new Swoole\Coroutine\Channel(1);
    Swoole\Coroutine::create(static function () use ($manual, $locked, $finishEdit, $editResult): void {
        try {
            $editResult->push(ContentMaintenance::write('analysis_articles', static function () use ($manual, $locked, $finishEdit) {
                $locked->push(true);
                if ($finishEdit->pop(5) === false) {
                    throw new RuntimeException('Timed out waiting to finish editor transaction');
                }
                $manual->html .= '<p>Manual edit</p>';
                $manual->content_source = 'admin';
                ++$manual->revision;
                return $manual->save();
            }));
        } catch (Throwable $error) {
            $editResult->push($error);
        }
    });
    $assert($locked->pop(5) === true, 'editor did not acquire the maintenance lock');
    Swoole\Coroutine::create(static function () use ($service, $review, $directory, $publishResult): void {
        try {
            $publishResult->push($service->apply($review['token'], $directory.'/stale.json'));
        } catch (Throwable $error) {
            $publishResult->push($error);
        }
    });
    $earlyPublication = $publishResult->pop(0.2);
    $finishEdit->push(true);
    $assert($editResult->pop(5) === true, 'editor transaction failed');
    $assert($earlyPublication === false, 'publication did not wait for the maintenance lock');
    $conflict = $publishResult->pop(5);
    $assert($conflict instanceof RuntimeException && str_contains($conflict->getMessage(), '预检后'), 'stale preflight was not rejected after editor committed');
    $sameMarkers = $markers();
    $assert(!file_exists($directory.'/stale.json'), 'stale preview wrote a receipt');
    $review = $service->review();
    $assert(count($review['changes']) === 12 && count($review['skipped_outlines']) === 1, 'manual edit was not excluded');

    $beforeFailure = $snapshot('analysis_articles');
    $historyBefore = $snapshot('article_revisions');
    Db::statement("CREATE TRIGGER reject_revision BEFORE INSERT ON article_revisions FOR EACH ROW
        BEGIN IF NEW.revision = 2 AND NEW.article_id > 2 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'simulated history failure'; END IF; END");
    try {
        $fails(fn () => $service->apply($review['token'], $directory.'/failed.json'), 'simulated history failure');
    } finally {
        Db::statement('DROP TRIGGER reject_revision');
    }
    $assert($snapshot('analysis_articles') === $beforeFailure && $snapshot('article_revisions') === $historyBefore, 'transaction partly committed');
    $fails(fn () => $service->rollback($directory.'/failed.json'), '回退版本冲突');

    $receipt = $directory.'/applied.json';
    $assert($service->apply($review['token'], $receipt) === 12, 'wrong apply count');
    $assert(count($release()->outline) === 37, 'release does not have 37 sections');
    foreach ($retired() as $article) {
        $assert($article->status === 'hidden', 'a retired article is visible');
    }
    $public = new App\Service\ArticleService();
    $visible = $public->analysis();
    $assert(count($visible) === 11 && $visible[0]->slug === AnalysisRelease::RELEASE_SLUG, 'wrong public count or ordering');
    $assert(count($public->analysis('选择题规律')) === 4, 'wrong choice-analysis category count');
    $assert(!App\Support\GuestQuota::locked(2) && App\Support\GuestQuota::locked(3), 'guest quota boundary changed');
    $assert($service->apply($service->review()['token'], $directory.'/noop.json') === 0, 'repeat apply is not a no-op');
    $assert($service->rollback($receipt) === 12 && count($release()->outline) === 38, 'rollback did not restore the original outline');
    foreach ($retired() as $article) {
        $assert($article->status === 'published', 'rollback did not restore visibility');
    }
    $assert($manual->refresh()->revision === 2 && str_ends_with($manual->html, '<p>Manual edit</p>'), 'manual article changed');

    $second = $directory.'/second.json';
    $service->apply($service->review()['token'], $second);
    $assert($sameMarkers === $markers(), 'maintenance protection changed during apply or rollback');
    ContentMaintenance::write('analysis_articles', function () use ($release) {
        $article = $release();
        ++$article->revision;
        return $article->save();
    });
    $beforeConflict = $snapshot('analysis_articles');
    $fails(fn () => $service->rollback($second), '回退版本冲突');
    $assert($snapshot('analysis_articles') === $beforeConflict, 'rollback conflict partially committed');
    $assert($other === [$snapshot('hotspots'), $snapshot('predictions')], 'out-of-scope tables changed');
    echo "PASS actual legacy data, MySQL JSON, manual edits, failed transaction, idempotency, rollback and conflict\n";
});
