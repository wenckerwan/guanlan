<?php
declare(strict_types=1);
if (!str_ends_with((string)getenv('DB_DATABASE'), '_admin_test')) throw new RuntimeException('Disposable admin test DB required');
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH.'/vendor/autoload.php';
$container = require BASE_PATH.'/config/container.php';
Hyperf\Database\Model\Register::setConnectionResolver($container->get(Hyperf\Database\ConnectionResolverInterface::class));
if (!class_exists(App\Service\AdminArticleService::class)) { echo "FAIL article editing service is missing\n"; exit(1); }
$failed = false;
Swoole\Coroutine\run(function () use (&$failed) {
    $pdo = new PDO('mysql:host='.getenv('DB_HOST').';dbname='.getenv('DB_DATABASE'), getenv('DB_USERNAME'), getenv('DB_PASSWORD'), [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    foreach (['hotspots','analysis_articles'] as $table) {
        $pdo->exec("DROP TABLE IF EXISTS $table");
        $pdo->exec("CREATE TABLE $table (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,slug VARCHAR(191) UNIQUE,title VARCHAR(191),summary TEXT,status VARCHAR(16) DEFAULT 'published',period VARCHAR(64) DEFAULT '',category VARCHAR(32) DEFAULT '',priority VARCHAR(2) DEFAULT 'A',level VARCHAR(2) DEFAULT 'A',type VARCHAR(32) DEFAULT '',tag VARCHAR(32) DEFAULT '',subject_id INT DEFAULT 1,html LONGTEXT,outline JSON,word_count INT DEFAULT 0,source_file VARCHAR(191) DEFAULT '',`release` BOOLEAN DEFAULT FALSE,created_at DATETIME NULL,updated_at DATETIME NULL) ENGINE=InnoDB");
    }
    require BASE_PATH.'/migrations/2026_10_10_000001_add_article_editor_metadata.php';
    (new AddArticleEditorMetadata())->up();
    $pdo->exec("UPDATE content_maintenance SET maintained=0 WHERE table_name IN ('hotspots','analysis_articles')");
    $admin = App\Model\User::where('role','admin')->where('status','active')->first();
    App\Support\Auth::setUser($admin);
    $service = new App\Service\AdminArticleService(new App\Service\ArticleRenderer());
    $assert = static function ($ok, $message) { if (!$ok) throw new RuntimeException($message); };
    $expect = static function ($code, $fn) use ($assert) { try {$fn();} catch (RuntimeException $e) {$assert($e->getCode()===$code, 'wrong error code '.$e->getCode()); return;} throw new RuntimeException('request accepted'); };
    $passed=0; $errors=[];
    $check = static function ($name,$fn) use (&$passed,&$errors) {try {$fn(); ++$passed; echo "PASS $name\n";} catch (Throwable $e) {$errors[]=$name.': '.$e->getMessage(); echo 'FAIL '.end($errors)."\n";}};
    $markdown = "# 中文标题\n\n**重点**与 [安全链接](https://example.test)\n\n## 第二节\n\n| 项 | 值 |\n| --- | --- |\n| 甲 | 乙 |\n\n<script>alert(1)</script>\n\n[攻击](javascript:alert(1))";
    $check('markdown preview has safe GFM and reader outline', function () use ($service,$markdown,$assert) {
        $r=$service->preview(['format'=>'markdown','body'=>$markdown]);
        $assert(str_contains($r['html'],'<table>') && str_contains($r['html'],'&lt;script&gt;') && !str_contains($r['html'],'href="javascript:'), 'unsafe or incomplete markdown');
        $assert($r['outline']===[['level'=>1,'title'=>'中文标题'],['level'=>2,'title'=>'第二节']] && str_contains($r['html'],'id="toc-0"') && str_contains($r['html'],'id="toc-1"'), 'reader TOC mismatch');
        $assert($r['wordCount']>10, 'missing Unicode word count');
    });
    $check('HTML preview removes executable nodes attributes and unsafe URLs', function () use ($service,$assert) {
        $r=$service->preview(['format'=>'html','body'=>'<h2 onclick="evil()">标题</h2><script>evil()</script><svg onload="evil()"></svg><a href="java&#x73;cript:evil()">坏</a><img src="data:image/svg+xml,x" onerror="evil()"><p style="color:red">正文</p>']);
        $assert(!preg_match('/script|onclick|onload|onerror|javascript|data:|style=|<svg/i',$r['html']), 'HTML sanitizer leak');
        $assert($r['outline']===[['level'=>2,'title'=>'标题']] && $r['wordCount']===5, 'HTML derived metadata mismatch');
    });
    foreach (['hotspot','analysis'] as $kind) {
        $check("$kind list includes editor revision and source", function () use ($kind,$assert) {
            $class=$kind==='hotspot'?App\Model\Hotspot::class:App\Model\AnalysisArticle::class;
            $item=App\Resource\ArticleResource::listItem(new $class(['revision'=>8,'content_source'=>'admin']));
            $assert(($item['revision']??null)===8&&($item['contentSource']??null)==='admin','missing editor revision on list');
        });
        $check("$kind legacy detail and metadata preserve exact HTML", function () use ($kind,$service,$pdo,$assert) {
            $table=$kind==='hotspot'?'hotspots':'analysis_articles'; $html="<h2 id='old' class='legacy'>原文</h2>\n<p style='color: red'>内容 &amp; 保留</p>";
            $q=$pdo->prepare("INSERT INTO $table (slug,title,html,status,outline,word_count) VALUES (?,?,?,'hidden','[]',9)");$q->execute(['legacy','旧文',$html]);
            $r=$service->detail($kind,1);$assert($r['format']==='html'&&$r['body']===$html&&$r['revision']===1&&$r['contentSource']==='dataset','legacy contract');
            $m=$service->save($kind,['title'=>'新标题'],1);$assert($m->html===$html&&$m->status==='hidden'&&(int)$m->revision===2&&$m->content_source==='admin','metadata rewrote legacy body');
        });
        $check("$kind markdown save matches preview and retains body", function () use ($kind,$service,$markdown,$assert) {
            $p=$service->preview(['format'=>'markdown','body'=>$markdown]);$m=$service->save($kind,['format'=>'markdown','body'=>$markdown,'expectedRevision'=>2],1);$d=$service->detail($kind,1);
            $assert($m->html===$p['html']&&$d['outline']===$p['outline']&&$d['wordCount']===$p['wordCount']&&$d['body']===$markdown&&$d['revision']===3,'save/preview mismatch');
        });
        $check("$kind stale revision is rejected without write", function () use ($kind,$service,$expect,$assert) {$expect(409,fn()=>$service->save($kind,['body'=>'lost','format'=>'markdown','expectedRevision'=>2],1));$assert($service->detail($kind,1)['revision']===3,'conflict changed revision');});
        $check("$kind explicit HTML edit sanitized and markdown cleared", function () use ($kind,$service,$assert) {$m=$service->save($kind,['format'=>'html','body'=>'<h1>新正文</h1><script>bad()</script>','expectedRevision'=>3],1);$d=$service->detail($kind,1);$assert($d['format']==='html'&&$d['body']===$m->html&&!str_contains($m->html,'script')&&$m->markdown===null,'format switch failed');});
        $check("$kind create legacy html compatibility",function()use($kind,$service,$assert){$m=$service->save($kind,['title'=>'创建','html'=>'<p>正文</p>']);$assert($m->html==='<p>正文</p>'&&(int)$m->revision===1&&$m->content_source==='admin','legacy create failed');});
        $check("$kind missing id and invalid status",function()use($kind,$service,$expect){$expect(404,fn()=>$service->detail($kind,999));$expect(404,fn()=>$service->save($kind,[],999));$expect(422,fn()=>$service->save($kind,['status'=>'draft'],1));});
        $check("$kind body edit requires revision and valid format",function()use($kind,$service,$expect){$expect(422,fn()=>$service->save($kind,['format'=>'markdown','body'=>'oops'],1));$expect(422,fn()=>$service->save($kind,['expectedRevision'=>4,'format'=>'bad','body'=>'oops'],1));});
    }
    $check('failed render leaves article and maintenance marker unchanged',function()use($service,$pdo,$expect,$assert){$pdo->exec("UPDATE content_maintenance SET maintained=0 WHERE table_name='hotspots'");$expect(422,fn()=>$service->save('hotspot',['format'=>'markdown','body'=>str_repeat('x',1000001),'expectedRevision'=>4],1));$assert($service->detail('hotspot',1)['revision']===4 && (int)$pdo->query("SELECT maintained FROM content_maintenance WHERE table_name='hotspots'")->fetchColumn()===0,'failed render committed');});
    $check('preview and write require active administrator',function()use($service,$expect,$admin){App\Support\Auth::setUser(null);$expect(403,fn()=>$service->preview(['format'=>'html','body'=>'']));$expect(403,fn()=>$service->save('hotspot',['title'=>'denied'],1));App\Support\Auth::setUser($admin);$admin->status='disabled';$admin->save();$expect(403,fn()=>$service->preview(['format'=>'html','body'=>'']));$admin->status='active';$admin->save();});
    echo "RESULT $passed passed, ".count($errors)." failed\n"; $failed=$errors!==[];
});
exit($failed?1:0);
