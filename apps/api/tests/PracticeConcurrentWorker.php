<?php
declare(strict_types=1);

if (! str_ends_with((string) getenv('DB_DATABASE'), '_admin_test')) {
    throw new RuntimeException('Disposable _admin_test database required');
}
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/vendor/autoload.php';
$container = require BASE_PATH . '/config/container.php';
Hyperf\Database\Model\Register::setConnectionResolver($container->get(Hyperf\Database\ConnectionResolverInterface::class));
Swoole\Coroutine\run(static function () use ($argv): void {
    $user = App\Model\User::findOrFail((int) $argv[1]);
    $bank = App\Model\PracticeBank::query()->where('slug', 'current-affairs-2026')->firstOrFail();
    $question = App\Model\PracticeQuestion::query()->where('bank_id', $bank->id)
        ->where('question_ref', 'SZ08-1')->where('is_active', true)->firstOrFail();
    $result = (new App\Service\PracticeStudyService())->submit($user, [
        'bankId' => $bank->slug, 'questionRef' => $question->question_ref,
        'questionVersion' => $question->question_version, 'eventId' => $argv[2],
        'chosen' => ['C'],
    ]);
    echo json_encode(['attemptId' => $result['attemptId'], 'duplicate' => $result['duplicate']], JSON_THROW_ON_ERROR) . PHP_EOL;
});
