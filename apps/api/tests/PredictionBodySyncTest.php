<?php
declare(strict_types=1);

require __DIR__ . '/../src/Support/PredictionBodySync.php';

use App\Support\PredictionBodySync as Sync;

$current = ['id'=>7, 'slug'=>'sample', 'source_file'=>Sync::SOURCES[0], 'title'=>'Title',
    'summary'=>'Summary', 'html'=>'<p>Old</p>', 'outline'=>[['level'=>2,'title'=>'Section']],
    'word_count'=>3, 'status'=>'hidden', 'priority'=>'A', 'sort_order'=>9, 'layer'=>'old'];
$source = $current;
$source['html'] = '<p>New</p>';
$source['outline'] = [['title'=>'Section','level'=>2]];
$source['layer'] = 'new';
$plan = Sync::plan([$current], [$source], 'sample');
assert($plan['changes'] === [['slug'=>'sample','fields'=>['html']]]);
$entries = $plan['entries'];
assert($entries[0]['old_html_sha256'] === hash('sha256', $current['html']));
$new = array_replace($current, $entries[0]['after']);
assert(Sync::plan([$new], [$source], 'sample')['unchanged'] === ['sample']);
assert(Sync::restore([$new], $entries)[0]['after']['html'] === $current['html']);
$reject = static function (callable $run): void {
    try { $run(); } catch (RuntimeException) { return; }
    throw new RuntimeException('Expected refusal');
};
$reject(fn () => Sync::restore([array_replace($new, ['summary'=>'changed later'])], $entries));
$reject(fn () => Sync::plan([$current], [array_replace($source, ['source_file'=>'outside.md'])], 'sample'));
$reject(fn () => Sync::plan([$current], [$source], null));
echo "PredictionBodySyncTest: PASS\n";
