<?php
declare(strict_types=1);

if (!str_ends_with((string) getenv('DB_DATABASE'), '_admin_test')) throw new RuntimeException('Disposable admin test DB required');
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/vendor/autoload.php';
$container = require BASE_PATH . '/config/container.php';
Hyperf\Database\Model\Register::setConnectionResolver($container->get(Hyperf\Database\ConnectionResolverInterface::class));

Swoole\Coroutine\run(static function (): void {
    $db = Hyperf\DbConnection\Db::class;
    $db::beginTransaction();
    try {
        $user = App\Model\User::create(['email' => 'practice-stats-' . bin2hex(random_bytes(4)) . '@example.test',
            'display_name' => '统计测试', 'password_hash' => 'test', 'role' => 'user', 'status' => 'active']);
        $bank = App\Model\PracticeBank::query()->where('slug', 'current-affairs-2026')->first()
            ?? App\Model\PracticeBank::create(['slug' => 'current-affairs-2026', 'title' => '测试题库', 'category' => 'current-affairs',
                'schema_version' => 1, 'content_version' => 'test', 'source_sha256' => str_repeat('0', 64), 'status' => 'published']);
        $bank->status = 'published';
        $bank->save();
        $question = App\Model\PracticeQuestion::create(['bank_id' => $bank->id, 'question_ref' => 'TEST-' . bin2hex(random_bytes(4)), 'question_version' => str_repeat('1', 64),
                'type' => 'single', 'module' => 'sx', 'stem' => '测试', 'options' => ['A' => 'A'], 'answer' => 'A',
                'analysis' => '测试', 'sources' => [], 'sort_order' => 1, 'is_active' => true]);
        $db::table('attempts')->insert(['user_id' => $user->id, 'source' => 'paper', 'module' => '习思想',
            'chosen' => 'A', 'correct' => 'A', 'is_right' => 1, 'created_at' => '2040-01-01 15:59:59']);
        foreach ([[1, 'B', 0, '2040-01-01 15:59:59'], [2, 'A', 1, '2040-01-01 16:00:00']] as [$index, $chosen, $right, $time]) {
            $db::table('practice_attempts')->insert(['user_id' => $user->id, 'bank_id' => $bank->id, 'question_id' => $question->id,
                'client_event_id' => sprintf('00000000-0000-4000-8000-%012d', $index), 'chosen' => $chosen,
                'correct_snapshot' => 'A', 'is_right' => $right, 'request_fingerprint' => str_repeat((string) $index, 64), 'created_at' => $time]);
        }
        $study = (new ReflectionClass(App\Service\StudyService::class))->newInstanceWithoutConstructor();
        $stats = $study->stats($user);
        if ($stats['total'] !== 3 || $stats['right'] !== 2 || $stats['wrong'] !== 1) throw new RuntimeException('Combined cumulative count');
        if (count($stats['byModule']) !== 1 || $stats['byModule'][0]['module'] !== 'sx' || $stats['byModule'][0]['total'] !== 3) throw new RuntimeException('Module did not merge');
        if (array_sum(array_column($stats['bySource'], 'total')) !== 3) throw new RuntimeException('Sources do not reconcile');
        $current = $stats['currentAffairs'];
        if ($current['submissionCount'] !== 2 || $current['uniqueQuestionCount'] !== 1 || $current['firstAccuracy'] != 0 || $current['latestAccuracy'] != 100 || $current['repeatCount'] !== 1) throw new RuntimeException('Current bank metrics');
        $admin = App\Model\User::query()->where('role', 'admin')->where('status', 'active')->first();
        if ($admin) {
            App\Support\Auth::setUser($admin);
            $service = (new ReflectionClass(App\Service\AdminService::class))->newInstanceWithoutConstructor();
            $page = $service->attemptPage(1, 20, 'practice');
            $ours = array_values(array_filter($page['items'], static fn ($row) => $row['userId'] === (int) $user->id));
            if (count($ours) !== 2 || $ours[0]['correct'] !== 'A' || $ours[0]['userEmail'] !== $user->email) throw new RuntimeException('Practice list snapshot/user');
            $dashboard = (new App\Service\AdminDashboardService())->statistics(new App\Support\AdminDateRange('2040-01-01', '2040-01-02'));
            if ($dashboard['attemptsTrend']['2040-01-01'] < 2 || $dashboard['attemptsTrend']['2040-01-02'] < 1) throw new RuntimeException('Beijing trend boundary');
        }
        echo "PracticeStatsIntegrationTest: PASS\n";
    } finally { $db::rollBack(); }
});
