<?php
declare(strict_types=1);

// Runs only against an explicitly disposable MySQL database, using real models/services.
$database = (string) getenv('DB_DATABASE');
if (!str_ends_with($database, '_admin_test')) {
    throw new RuntimeException('DB_DATABASE must end in _admin_test');
}
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/vendor/autoload.php';
$container = require BASE_PATH . '/config/container.php';
Hyperf\Database\Model\Register::setConnectionResolver($container->get(Hyperf\Database\ConnectionResolverInterface::class));
$failed = false;
if (in_array($argv[1] ?? '', ['demote', 'maintain'], true)) {
    $result = '';
    Swoole\Coroutine\run(function () use ($argv, &$result): void {
        try {
            $service = (new ReflectionClass(App\Service\AdminService::class))->newInstanceWithoutConstructor();
            if ($argv[1] === 'maintain') {
                App\Support\ContentMaintenance::write('hotspots', function () use ($service) {
                    file_put_contents('/tmp/admin-maintenance-lock', 'locked');
                    usleep(300000);
                    return $service->saveHotspot(['title'=>'Concurrent edit'],1);
                });
            } else { $service->updateUser((int)$argv[2], 'user'); }
            $result = 'changed';
        } catch (RuntimeException $e) { $result = 'blocked'; }
    });
    echo $result;
    exit(0);
}

Swoole\Coroutine\run(function () use ($database, &$failed): void {
    $pdo = new PDO('mysql:host=' . getenv('DB_HOST') . ';dbname=' . $database,
        getenv('DB_USERNAME'), getenv('DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    foreach (['users', 'user_tokens', 'hotspots', 'analysis_articles', 'papers', 'questions', 'content_maintenance'] as $table) {
        $pdo->exec("DROP TABLE IF EXISTS {$table}");
    }
    $pdo->exec("CREATE TABLE users (id BIGINT UNSIGNED PRIMARY KEY, email VARCHAR(191), role VARCHAR(16), status VARCHAR(16), user_group VARCHAR(16), mistake_code VARCHAR(32), password_hash VARCHAR(255), created_at DATETIME NULL, updated_at DATETIME NULL) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE user_tokens (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED, token_hash CHAR(64), expires_at DATETIME) ENGINE=InnoDB");
    foreach (['hotspots', 'analysis_articles'] as $table) {
        $pdo->exec("CREATE TABLE {$table} (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, slug VARCHAR(191), title VARCHAR(255), summary TEXT, html LONGTEXT, status VARCHAR(16), category VARCHAR(64), level VARCHAR(8), priority VARCHAR(8), type VARCHAR(64), tag VARCHAR(64), period VARCHAR(64), subject_id BIGINT, created_at DATETIME NULL, updated_at DATETIME NULL) ENGINE=InnoDB");
    }
    $pdo->exec("CREATE TABLE papers (id BIGINT UNSIGNED PRIMARY KEY, pid VARCHAR(191), created_at DATETIME NULL, updated_at DATETIME NULL) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE questions (id BIGINT UNSIGNED PRIMARY KEY, pid VARCHAR(191), created_at DATETIME NULL, updated_at DATETIME NULL) ENGINE=InnoDB");
    $migration = BASE_PATH . '/migrations/2026_10_09_000001_create_content_maintenance.php';
    if (is_file($migration)) { require $migration; (new CreateContentMaintenance())->up(); }

    $service = (new ReflectionClass(App\Service\AdminService::class))->newInstanceWithoutConstructor();
    $failures = []; $passed = 0;
    $check = function (string $name, callable $test) use (&$failures, &$passed): void {
        try { $test(); ++$passed; echo "PASS {$name}\n"; }
        catch (Throwable $e) { $failures[] = $name . ': ' . $e->getMessage(); echo "FAIL " . end($failures) . "\n"; }
    };
    $assert = function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
    foreach (['hotspots' => 'saveHotspot', 'analysis_articles' => 'saveAnalysis'] as $table => $method) {
        $pdo->exec("INSERT INTO {$table} (id,slug,title,status) VALUES (1,'hidden','Original','hidden')");
        $check("{$table} partial update preserves hidden", function () use ($service,$method,$assert): void {
            $row = $service->$method(['title'=>'Changed'],1);
            $assert($row->status === 'hidden', 'hidden content was published');
        });
        $check("{$table} missing update never creates", function () use ($service,$method,$table,$pdo,$assert): void {
            $before = $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
            try { $service->$method(['title'=>'Missing'],999); } catch (RuntimeException $e) {
                $assert($pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn() === $before,'unexpected insert'); return;
            }
            throw new RuntimeException('missing update accepted');
        });
        $check("{$table} invalid status leaves data unchanged", function () use ($service,$method,$table,$pdo,$assert): void {
            $before = $pdo->query("SELECT title,status FROM {$table} WHERE id=1")->fetch(PDO::FETCH_ASSOC);
            try { $service->$method(['title'=>'Invalid','status'=>'draft'],1); } catch (RuntimeException $e) {
                $assert($pdo->query("SELECT title,status FROM {$table} WHERE id=1")->fetch(PDO::FETCH_ASSOC) === $before,'invalid update changed data'); return;
            }
            throw new RuntimeException('invalid status accepted');
        });
    }
    $pdo->exec("INSERT INTO users (id,email,role,status,user_group,password_hash) VALUES (1,'admin@example.test','admin','active','user','old')");
    foreach ([['user',''],['','disabled']] as [$role,$status]) {
        $check("last administrator protection {$role}{$status}", function () use ($service,$role,$status,$assert): void {
            try { $service->updateUser(1,$role,$status); } catch (RuntimeException $e) { return; }
            throw new RuntimeException('last administrator change accepted');
        });
        $pdo->exec("UPDATE users SET role='admin',status='active' WHERE id=1");
    }
    $pdo->exec("INSERT INTO users (id,email,role,status,user_group) VALUES (2,'second@example.test','admin','active','user')");
    $check('two administrators permit one demotion', function () use ($service,$assert): void {
        $assert($service->updateUser(2,'user')->role === 'user','demotion rejected');
    });
    $pdo->exec("INSERT INTO user_tokens (user_id,token_hash,expires_at) VALUES (1,REPEAT('a',64),'2030-01-01'),(2,REPEAT('b',64),'2030-01-01')");
    $check('password reset revokes only target sessions', function () use ($service,$pdo,$assert): void {
        $user = $service->resetPassword(1,'new-password');
        $assert(password_verify('new-password',$user->password_hash),'password not changed');
        $assert((int)$pdo->query('SELECT COUNT(*) FROM user_tokens WHERE user_id=1')->fetchColumn() === 0,'old sessions remain');
        $assert((int)$pdo->query('SELECT COUNT(*) FROM user_tokens WHERE user_id=2')->fetchColumn() === 1,'other sessions revoked');
    });
    $check('maintenance blocks destructive reimport', function () use ($assert): void {
        try { App\Support\ContentMaintenance::assertImportAllowed(['hotspots']); }
        catch (RuntimeException $e) { $assert(str_contains($e->getMessage(),'hotspots'),'missing table context'); return; }
        throw new RuntimeException('maintained content may be overwritten');
    });
    $check('protected import executes no destructive callback', function () use ($pdo,$assert): void {
        $called = false;
        try { App\Support\ContentMaintenance::import(['hotspots'], function () use (&$called): void { $called = true; }); }
        catch (RuntimeException $e) { $assert(!$called,'import wrote before checking protection'); return; }
        throw new RuntimeException('import not blocked');
    });
    $check('unmaintained import rolls back on failure', function () use ($pdo,$assert): void {
        try {
            App\Support\ContentMaintenance::import(['questions'], function (): void {
                Hyperf\DbConnection\Db::table('questions')->insert(['id'=>99,'pid'=>'rollback']);
                throw new RuntimeException('injected import failure');
            });
        } catch (RuntimeException $e) {}
        $assert((int)$pdo->query('SELECT COUNT(*) FROM questions')->fetchColumn() === 0,'partial import persisted');
    });
    $pdo->exec("INSERT INTO papers (id,pid) VALUES (1,'test-paper')");
    $pdo->exec("INSERT INTO questions (id,pid) VALUES (1,'test-paper')");
    $check('non-force paper delete preserves questions', function () use ($service,$pdo,$assert): void {
        $assert(isset($service->deletePaper(1)['blocked']),'non-force delete not blocked');
        $assert((int)$pdo->query('SELECT COUNT(*) FROM questions')->fetchColumn() === 1,'questions deleted');
    });
    $pdo->exec("CREATE TRIGGER reject_paper_delete BEFORE DELETE ON papers FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected failure'");
    $check('failed force deletion rolls back questions and marker', function () use ($service,$pdo,$assert): void {
        try { $service->deletePaper(1,true); } catch (Throwable $e) {
            $assert((int)$pdo->query('SELECT COUNT(*) FROM questions')->fetchColumn() === 1,'question deletion persisted');
            $assert((int)$pdo->query("SELECT maintained FROM content_maintenance WHERE table_name='papers'")->fetchColumn() === 0,'failed operation marked'); return;
        }
        throw new RuntimeException('injected failure ignored');
    });
    $pdo->exec('DROP TRIGGER reject_paper_delete');
    $check('successful force deletion removes paper and questions', function () use ($service,$pdo,$assert): void {
        $assert($service->deletePaper(1,true)['deleted'],'delete failed');
        $assert((int)$pdo->query('SELECT COUNT(*) FROM papers')->fetchColumn() === 0,'paper remains');
        $assert((int)$pdo->query('SELECT COUNT(*) FROM questions')->fetchColumn() === 0,'questions remain');
    });
    foreach (['ArticleSeeder','PaperQuestionSeeder'] as $seeder) {
        $check("{$seeder} refuses before any write", function () use ($seeder,$pdo,$assert): void {
            require_once BASE_PATH . '/seeders/' . $seeder . '.php';
            $before = $pdo->query('SELECT title FROM hotspots ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
            try { (new $seeder())->run(); } catch (RuntimeException $e) {
                $assert(str_contains($e->getMessage(),'拒绝覆盖后台维护内容'),'seeder did not check maintenance first');
                $assert($pdo->query('SELECT title FROM hotspots ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) === $before,'content changed'); return;
            }
            throw new RuntimeException('seeder was permitted');
        });
    }
    $check('concurrent maintenance prevents waiting import overwrite', function () use ($pdo,$assert): void {
        $pdo->exec("UPDATE content_maintenance SET maintained=0 WHERE table_name='hotspots'");
        @unlink('/tmp/admin-maintenance-lock');
        $pipes = [];
        $process = proc_open([PHP_BINARY,__FILE__,'maintain'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if (!is_resource($process)) throw new RuntimeException('maintenance worker did not start');
        fclose($pipes[0]);
        $deadline = microtime(true)+5;
        while (!is_file('/tmp/admin-maintenance-lock') && microtime(true)<$deadline) usleep(10000);
        $called = false; $blocked = false;
        try { App\Support\ContentMaintenance::import(['hotspots'],function () use (&$called) { $called=true; }); }
        catch (RuntimeException $e) { $blocked = str_contains($e->getMessage(),'拒绝覆盖'); }
        $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $assert(proc_close($process)===0 && $out==='changed','maintenance worker failed: '.$out.$err);
        $assert($blocked && !$called,'import bypassed concurrent maintenance');
        $assert($pdo->query('SELECT title FROM hotspots WHERE id=1')->fetchColumn()==='Concurrent edit','edit lost');
    });
    $check('concurrent demotions leave one active administrator', function () use ($pdo,$assert): void {
        $pdo->exec("UPDATE users SET role='admin',status='active'");
        $workers = [];
        foreach ([1,2] as $id) {
            $pipes = [];
            $process = proc_open([PHP_BINARY,__FILE__,'demote',(string)$id], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
            if (!is_resource($process)) throw new RuntimeException('worker did not start');
            fclose($pipes[0]); $workers[] = [$process,$pipes];
        }
        $changed = 0;
        foreach ($workers as [$process,$pipes]) {
            $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            $assert(proc_close($process) === 0,'worker failed: '.$err);
            $assert(in_array($out,['changed','blocked'],true),'unexpected worker response: '.$out);
            $changed += (int)($out === 'changed');
        }
        $assert($changed === 1,'expected exactly one permitted demotion');
        $assert((int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='admin' AND status='active'")->fetchColumn() === 1,'last administrator lost');
    });
    echo "RESULT {$passed} passed, " . count($failures) . " failed\n";
    $failed = $failures !== [];
});
exit($failed ? 1 : 0);
