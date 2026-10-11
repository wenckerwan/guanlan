<?php
declare(strict_types=1);

use App\Service\PracticeBankImporter;

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/vendor/autoload.php';

$check = static function (bool $ok, string $message): void { if (! $ok) throw new RuntimeException($message); };
$package = PracticeBankImporter::readPackage(BASE_PATH . '/resources/private/current-affairs-2026.json');
$check($package['questionCount'] === 101, 'declared count');
$check(count($package['questions']) === 101, 'actual count');
$types = array_count_values(array_column($package['questions'], 'type'));
$check($types === ['single' => 52, 'multi' => 49], 'type counts');
$check(array_sum(array_map(static fn ($q) => count($q['options']), $package['questions'])) === 404, 'option count');
foreach ($package['questions'] as $question) {
    $check(PracticeBankImporter::questionVersion($question) === $question['questionVersion'], 'hash ' . $question['questionRef']);
}
$changed = $package['questions'][0];
$changed['sources'] = [];
$changed['analysis'] = 'different explanation';
$check(PracticeBankImporter::questionVersion($changed) === $package['questions'][0]['questionVersion'], 'metadata changed version');
$changed['answer'] = 'A';
$check(PracticeBankImporter::questionVersion($changed) !== $package['questions'][0]['questionVersion'], 'grading did not change version');
echo "PracticeDataContractTest: PASS\n";
