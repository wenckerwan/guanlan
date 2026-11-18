<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/Support/ClientAddress.php';
require dirname(__DIR__) . '/src/Service/TurnstileService.php';

function checkProtection(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$secret = str_repeat('a', 64);
putenv('BFF_PROXY_SECRET=' . $secret);
$timestamp = (string) time();
$path = '/api/v1/auth/email/code';
$ip = '198.51.100.8';
$signature = hash_hmac('sha256', "$timestamp\nPOST\n$path\n$ip", $secret);
checkProtection(App\Support\ClientAddress::verify($ip, $timestamp, $signature, 'POST', $path) === $ip, 'Valid signed IP rejected');
checkProtection(App\Support\ClientAddress::verify('203.0.113.123', $timestamp, $signature, 'POST', $path) === null, 'Forged IP accepted');
checkProtection(App\Support\ClientAddress::verify($ip, $timestamp, $signature, 'POST', '/api/v1/auth/register') === null, 'Signature crossed endpoints');
$old = (string) (time() - 120);
checkProtection(App\Support\ClientAddress::verify($ip, $old, hash_hmac('sha256', "$old\nPOST\n$path\n$ip", $secret), 'POST', $path) === null, 'Expired signature accepted');
echo "PASS authenticated visitor address\n";

putenv('TURNSTILE_ENABLED=true');
putenv('TURNSTILE_SECRET_KEY=test-secret');
putenv('TURNSTILE_HOSTNAMES=guanlan.wencker.top,guanlan.wencker.pro');
$calls = 0;
$valid = ['success' => true, 'hostname' => 'guanlan.wencker.top', 'action' => 'register'];
$service = new App\Service\TurnstileService(function (array $payload) use (&$calls, &$valid): array {
    ++$calls;
    checkProtection($payload['remoteip'] === '198.51.100.8', 'Wrong IP submitted');
    return $valid;
});
checkProtection($service->validate('', $ip) !== null && $calls === 0, 'Missing token reached verification');
checkProtection($service->validate(str_repeat('x', 2049), $ip) !== null && $calls === 0, 'Oversized token accepted');
checkProtection($service->validate('token', $ip) === null, 'Valid challenge rejected');
$valid['hostname'] = 'guanlan.wencker.pro';
checkProtection($service->validate('token', $ip) === null, 'Second configured hostname rejected');
foreach ([['success' => false], ['success' => 'true'], ['hostname' => 'evil.test'], ['action' => 'login']] as $override) {
    $valid = array_replace(['success' => true, 'hostname' => 'guanlan.wencker.top', 'action' => 'register'], $override);
    checkProtection($service->validate('token', $ip) !== null, 'Invalid challenge accepted');
}
$service = new App\Service\TurnstileService(fn () => throw new RuntimeException('network unavailable'));
checkProtection(($service->validate('token', $ip)['status'] ?? 0) === 503, 'Network failure allowed request');
putenv('TURNSTILE_SECRET_KEY=');
checkProtection(($service->validate('token', $ip)['status'] ?? 0) === 503, 'Missing secret allowed request');
echo "PASS Turnstile missing/invalid token, hostname, action and fail-closed behavior\n";
