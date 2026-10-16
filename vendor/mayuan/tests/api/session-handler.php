<?php
declare(strict_types=1);
$root = getenv('MAYUAN_API_ROOT') ?: dirname(__DIR__, 2).'/apps/api';
require $root.'/vendor/autoload.php';
$path = sys_get_temp_dir().'/mayuan-session-test-'.bin2hex(random_bytes(8));
$handler = new Hyperf\Session\Handler\FileHandler(new Hyperf\Support\Filesystem\Filesystem(), $path, 120);
try {
    $payload = serialize(['user' => ['id' => 'test:learner']]);
    if (!$handler->write('fixture', $payload) || $handler->read('fixture') !== $payload) {
        throw new RuntimeException('Session cannot be restored after write');
    }
    touch($path.'/fixture', time()-86400);
    clearstatcache(true, $path.'/fixture');
    if ($handler->read('fixture') !== '') throw new RuntimeException('Expired session restored');
    echo "PASS real Hyperf file session roundtrip and expiry\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL session roundtrip: '.get_class($error).' '.$error->getMessage()."\n");
    exit(1);
} finally {
    if (is_file($path.'/fixture')) unlink($path.'/fixture');
    if (is_dir($path)) rmdir($path);
}
