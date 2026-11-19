<?php
declare(strict_types=1);

require __DIR__.'/../src/Support/AnalysisRelease.php';
$path = __DIR__.'/../src/Support/AnalysisReleasePlan.php';
if (!is_file($path)) { fwrite(STDERR, "FAIL: analysis release planner missing\n"); exit(1); }
require $path;

use App\Support\AnalysisRelease;
use App\Support\AnalysisReleasePlan;

$failures = [];
$check = function (string $name, callable $test) use (&$failures) {
    try { $test(); echo "PASS $name\n"; }
    catch (Throwable $e) { $failures[] = "$name: ".$e->getMessage(); }
};
$assert = function (bool $ok, string $message = 'assertion failed') { if (!$ok) throw new RuntimeException($message); };
$sources = json_decode(file_get_contents(dirname(__DIR__, 3).'/storage/dataset/analysis_articles.json'), true, 512, JSON_THROW_ON_ERROR);
$rows = [];
foreach ($sources as $i => $s) {
    $rows[] = array_merge($s, [
        'id'=>$i+1, 'status'=>'published', 'revision'=>3, 'content_source'=>'dataset',
        'outline'=>json_encode(array_merge([['level'=>1,'title'=>$s['title']]], $s['outline']), JSON_UNESCAPED_UNICODE),
        'created_at'=>'2026-01-01 00:00:00', 'updated_at'=>'2026-01-01 00:00:00',
    ]);
}
foreach (AnalysisRelease::RETIRED_SLUGS as $i => $slug) {
    $rows[] = ['id'=>20+$i,'slug'=>$slug,'title'=>'旧稿','html'=>'人工修订旧稿','outline'=>'[]','status'=>'published','revision'=>8,'content_source'=>'admin'];
}
$check('targeted changes preserve text and match 11 visible articles', function () use ($sources,$rows,$assert) {
    $plan=AnalysisReleasePlan::build($rows,$sources);
    $assert($plan['blockers']===[]);
    $assert(count($plan['changes'])===13);
    foreach ($plan['changes'] as $change) {
        $assert(array_diff(array_keys($change['fields']),['status','outline'])===[], 'unsafe write field');
    }
    $assert($plan['visible_after']===11);
});
$check('manual body changes retain their outlines', function () use ($sources,$rows,$assert) {
    $rows[0]['html'].='手工修改';
    $plan=AnalysisReleasePlan::build($rows,$sources);
    $assert(count($plan['changes'])===12 && count($plan['skipped_outlines'])===1);
});
$check('non-title H1 is never silently removed', function () use ($sources,$rows,$assert) {
    $rows[0]['outline']=json_encode([['level'=>1,'title'=>'正文另一个标题']]);
    $plan=AnalysisReleasePlan::build($rows,$sources);
    $assert(count($plan['changes'])===12 && count($plan['skipped_outlines'])===1);
});
$check('fresh 11-row dataset is an idempotent no-op', function () use ($sources,$rows,$assert) {
    $rows=array_slice($rows,0,11);
    foreach ($rows as &$row) $row['outline']=json_encode($sources[$row['id']-1]['outline']);
    unset($row);
    $plan=AnalysisReleasePlan::build($rows,$sources);
    $assert($plan['blockers']===[] && $plan['changes']===[]);
});
$check('missing release and unexpected article block publication', function () use ($sources,$rows,$assert) {
    $rows=array_values(array_filter($rows,fn($r)=>$r['slug']!==AnalysisRelease::RELEASE_SLUG));
    $rows[]=['id'=>99,'slug'=>'unreviewed','status'=>'published'];
    $assert(count(AnalysisReleasePlan::build($rows,$sources)['blockers'])>=2);
});
$check('hidden retained articles are not force-published', function () use ($sources,$rows,$assert) {
    $rows[0]['status']='hidden';
    $assert(AnalysisReleasePlan::build($rows,$sources)['blockers']!==[]);
});
$check('fingerprints ignore row order but detect manual edits', function () use ($rows,$assert) {
    $reversed=array_reverse($rows);
    $assert(AnalysisReleasePlan::fingerprint($rows)===AnalysisReleasePlan::fingerprint($reversed));
    $reversed[0]['html']='changed';
    $assert(AnalysisReleasePlan::fingerprint($rows)!==AnalysisReleasePlan::fingerprint($reversed));
});
if ($failures) { fwrite(STDERR,implode("\n",$failures)."\n"); exit(1); }
$check('receipt detects intervening changes and restores only original fields', function () use ($sources,$rows,$assert) {
    $receipt = AnalysisReleasePlan::prepare($rows, $sources);
    $assert(count($receipt['entries']) === 13);
    foreach ($receipt['entries'] as $entry) {
        foreach ($rows as &$row) {
            if ($row['id'] !== $entry['id']) continue;
            $row = array_replace($row, $entry['after'], ['revision'=>$entry['revision']+1]);
        }
        unset($row);
    }
    $restores = AnalysisReleasePlan::rollback($rows, $receipt['entries']);
    $assert($restores[0]['fields'] === $receipt['entries'][0]['before']);
    ++$rows[0]['revision'];
    try {
        AnalysisReleasePlan::rollback($rows, $receipt['entries']);
        throw new LogicException('rollback accepted an intervening revision');
    } catch (RuntimeException $error) {
        $assert(str_contains($error->getMessage(), $rows[0]['slug']));
    }
});
$check('preflight approval is tied to the database and dataset snapshot', function () use ($sources,$rows,$assert) {
    $review = AnalysisReleasePlan::prepare($rows, $sources);
    $assert($review['token'] === AnalysisReleasePlan::prepare(array_reverse($rows), $sources)['token']);
    $rows[0]['html'] .= ' manual edit';
    $assert($review['token'] !== AnalysisReleasePlan::prepare($rows, $sources)['token']);
    $sources[0]['html'] .= ' new dataset';
    $assert($review['token'] !== AnalysisReleasePlan::prepare(array_reverse($rows), $sources)['token']);
});
if ($failures) { fwrite(STDERR,implode("\n",$failures)."\n"); exit(1); }
echo "AnalysisReleasePlanTest: PASS\n";
