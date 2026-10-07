<?php

declare(strict_types=1);

// Run against an API connected to a disposable database named *_visit_test.
$base = rtrim((string) getenv('VISIT_TEST_BASE_URL'), '/');
$database = (string) getenv('DB_DATABASE');
if ($base === '' || ! str_ends_with($database, '_visit_test')) {
    fwrite(STDERR, "Requires VISIT_TEST_BASE_URL and a disposable DB_DATABASE ending in _visit_test.\n");
    exit(1);
}
$http = static function (string $path, string $method, string $cookie = '') use ($base): array {
    $context = stream_context_create(['http' => [
        'method' => $method, 'ignore_errors' => true, 'timeout' => 15,
        'header' => "Cookie: $cookie\r\nSec-Fetch-Site: same-origin\r\nConnection: close\r\n",
    ]]);
    $body = file_get_contents($base . $path, false, $context);
    return ['headers' => $http_response_header ?? [], 'body' => json_decode((string) $body, true)];
};
if (($argv[1] ?? '') === 'worker') {
    $result = $http('/stats/visit', 'POST', $argv[2]);
    echo json_encode($result['body']);
    exit(isset($result['body']['data']) ? 0 : 1);
}
$check = static function (bool $ok, string $label): void {
    if (! $ok) throw new RuntimeException($label);
};
$initial = $http('/stats/visits', 'GET');
$headers = implode("\n", $initial['headers']);
$check(str_contains(strtolower($headers), 'cache-control: no-store'), 'no-store');
$check(preg_match('/Set-Cookie: (guanlan_browser=([a-f0-9]{64}));/i', $headers, $matches) === 1, 'cookie issued');
$check(str_contains($headers, 'HttpOnly') && str_contains($headers, 'SameSite=Lax'), 'cookie flags');
$cookie = $matches[1];
$hash = hash('sha256', $matches[2]);
$before = $initial['body']['data']['total'];
$check($http('/stats/visit', 'POST')['body']['message'] === '请先初始化浏览器标识', 'cookie required');
$first = $http('/stats/visit', 'POST', $cookie)['body']['data'];
$check($first['counted'] && $first['total'] === $before + 1, 'first increments');
$second = $http('/stats/visit', 'POST', $cookie)['body']['data'];
$check(! $second['counted'] && $second['total'] === $first['total'], 'refresh deduplicated');
$pdo = new PDO('mysql:host=' . (getenv('DB_HOST') ?: 'mysql') . ';port=' . (getenv('DB_PORT') ?: '3306')
    . ';dbname=' . $database, (string) getenv('DB_USERNAME'), (string) getenv('DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$expire = $pdo->prepare('UPDATE site_visit_browsers SET last_counted_at = ? WHERE browser_hash = ?');
$expire->execute([time() - 3600, $hash]);
$workers = [];
for ($i = 0; $i < 8; ++$i) {
    $pipes = [];
    $process = proc_open([PHP_BINARY, __FILE__, 'worker', $cookie], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $check(is_resource($process), 'worker starts');
    fclose($pipes[0]);
    $workers[] = [$process, $pipes];
}
$counted = 0;
foreach ($workers as [$process, $pipes]) {
    $body = json_decode(stream_get_contents($pipes[1]), true);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $check(proc_close($process) === 0, 'worker response: ' . $error);
    $counted += (int) $body['data']['counted'];
}
$check($counted === 1, 'concurrent expiry counted once');
$final = $http('/stats/visits', 'GET', $cookie)['body']['data'];
$check($final['total'] === $before + 2 && $final['today'] === $first['today'] + 1, 'daily and total consistent');
echo "VisitHttpIntegrationTest: PASS\n";
