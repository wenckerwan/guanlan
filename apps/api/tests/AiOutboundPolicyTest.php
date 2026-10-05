<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/Support/AiOutboundPolicy.php';

use App\Support\AiOutboundPolicy;

$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
    ++$checks;
    if (! $ok) throw new RuntimeException($label);
};
$policy = new AiOutboundPolicy(static fn (string $host): array => match ($host) {
    'public.example' => ['93.184.215.14', '2606:4700:4700::1111'],
    'mixed.example' => ['93.184.215.14', '10.0.0.1'],
    'v6-private.example' => ['93.184.215.14', '::ffff:127.0.0.1'],
    default => [],
});
foreach ([
    'http://127.0.0.1/', 'http://169.254.169.254/', 'http://10.0.0.1/',
    'http://192.168.1.1/', 'http://172.16.1.1/', 'http://100.64.0.1/',
    'http://224.0.0.1/', 'http://198.18.0.1/', 'http://192.0.2.1/',
    'https://[::1]/', 'https://[::ffff:8.8.8.8]/', 'https://[fc00::1]/',
    'https://[fe80::1]/', 'https://[2001:db8::1]/', 'https://[2002:0808:0808::1]/',
    'http://localhost./', 'http://foo.internal/', 'http://2130706433/',
    'http://0177.0.0.1/', 'http://0x7f000001/', 'https://user:secret@public.example/',
    'https://public.example:0/', 'https://public.example/#secret',
    "https://public.example/\r\nHost: localhost", 'https://public.example\\@localhost/',
    'file:///etc/passwd', 'https://mixed.example/', 'https://v6-private.example/',
    'https://missing.example/',
] as $url) {
    try { $policy->resolve($url); $check(false, "accepted forbidden URL: {$url}"); }
    catch (InvalidArgumentException) { ++$checks; }
}
$target = $policy->resolve('https://public.example:8443/v1?x=1');
$check($target['host'] === 'public.example', 'preserve TLS hostname');
$check($target['url'] === 'https://93.184.215.14:8443/v1?x=1', 'connect to approved address only');
$check($target['authority'] === 'public.example:8443', 'preserve Host header');
$target = $policy->resolve('https://[2606:4700:4700::1111]/v1');
$check($target['url'] === 'https://[2606:4700:4700::1111]:443/v1', 'IPv6 target');
$calls = 0;
$rebind = new AiOutboundPolicy(static function () use (&$calls): array {
    return ++$calls === 1 ? ['93.184.215.14'] : ['127.0.0.1'];
});
$check(str_contains($rebind->resolve('https://public.example/')['url'], '93.184.215.14'), 'pin first resolution');
try { $rebind->resolve('https://public.example/'); $check(false, 'rebind must be blocked'); }
catch (InvalidArgumentException) { ++$checks; }
echo "AiOutboundPolicyTest: PASS ({$checks} checks)\n";
