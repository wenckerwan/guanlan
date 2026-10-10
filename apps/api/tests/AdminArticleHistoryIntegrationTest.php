<?php
declare(strict_types=1);
if (!str_ends_with((string)getenv('DB_DATABASE'), '_admin_test')) throw new RuntimeException('Disposable admin test DB required');
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH.'/vendor/autoload.php';
$container = require BASE_PATH.'/config/container.php';
Hyperf\Database\Model\Register::setConnectionResolver($container->get(Hyperf\Database\ConnectionResolverInterface::class));
if (!class_exists(App\Service\AdminArticleHistoryService::class)) { echo "FAIL article history service is missing\n"; exit(1); }
$failed = false;
Swoole\Coroutine\run(function () use (&$failed) {
    $pdo = new PDO('mysql:host='.getenv('DB_HOST').';dbname='.getenv('DB_DATABASE'), getenv('DB_USERNAME'), getenv('DB_PASSWORD'), [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    foreach (['hotspots','analysis_articles'] as $table) {
        $pdo->exec("DROP TABLE IF EXISTS $table");
        $pdo->exec("CREATE TABLE $table (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,slug VARCHAR(191) UNIQUE,title VARCHAR(191),summary TEXT,status VARCHAR(16) DEFAULT 'published',period VARCHAR(64) DEFAULT '',category VARCHAR(32) DEFAULT '',priority VARCHAR(2) DEFAULT 'A',level VARCHAR(2) DEFAULT 'A',type VARCHAR(32) DEFAULT '',tag VARCHAR(32) DEFAULT '',subject_id INT DEFAULT 1,html LONGTEXT,outline JSON,word_count INT DEFAULT 0,source_file VARCHAR(191) DEFAULT '',`release` BOOLEAN DEFAULT FALSE,created_at DATETIME NULL,updated_at DATETIME NULL) ENGINE=InnoDB");
    }
    require BASE_PATH.'/migrations/2026_10_10_000001_add_article_editor_metadata.php';
    (new AddArticleEditorMetadata())->up();
    $pdo->exec("ALTER TABLE hotspots ADD comment_mode VARCHAR(16) DEFAULT 'open'");
    $pdo->exec("ALTER TABLE analysis_articles ADD comment_mode VARCHAR(16) DEFAULT 'open'");
    $pdo->exec("DROP TABLE IF EXISTS article_revisions");
    require BASE_PATH.'/migrations/2026_10_10_000002_create_article_revisions.php';
    (new CreateArticleRevisions())->up();
    $pdo->exec("UPDATE content_maintenance SET maintained=0 WHERE table_name IN ('hotspots','analysis_articles')");
    $admin = App\Model\User::where('role','admin')->where('status','active')->first();
    App\Support\Auth::setUser($admin);
    $service = new App\Service\AdminArticleService(new App\Service\ArticleRenderer());
    $assert = static function ($ok, $message) { if (!$ok) throw new RuntimeException($message); };
    $expect = static function ($code, $fn) use ($assert) { try {$fn();} catch (RuntimeException $e) {$assert($e->getCode()===$code, 'wrong error code '.$e->getCode()); return;} throw new RuntimeException('request accepted'); };
    $passed=0; $errors=[];
    $check = static function ($name,$fn) use (&$passed,&$errors) {try {$fn(); ++$passed; echo "PASS $name\n";} catch (Throwable $e) {$errors[]=$name.': '.$e->getMessage(); echo 'FAIL '.end($errors)."\n";}};
    $pdo->exec("ALTER TABLE analysis_articles ALTER COLUMN priority SET DEFAULT 'B'");
    $history = new App\Service\AdminArticleHistoryService($service);
    foreach (['hotspot','analysis'] as $kind) {
        $check("$kind immutable creation and successive history", function () use ($kind,$service,$history,$assert,$admin) {
            $m=$service->save($kind,['title'=>'初稿','format'=>'markdown','body'=>"# 原稿\n\n保留 **源文**"]);
            $first=$history->revision($kind,(int)$m->id,1);
            $service->save($kind,['title'=>'更新','format'=>'markdown','body'=>'新文','expectedRevision'=>1],(int)$m->id);
            $assert($history->revision($kind,(int)$m->id,1)===$first,'snapshot overwritten');
            $list=$history->revisions($kind,(int)$m->id,1,1);
            $assert($list['total']===2&&$list['items'][0]['revision']===2&&$first['adminId']===(int)$admin->id,'history listing/actor');
            $export=$history->export($kind,(int)$m->id,1);
            $assert($export['body']==="# 原稿\n\n保留 **源文**"&&$export['format']==='markdown'&&$export['fileName']==="$kind-{$m->id}-r1.md",'raw export changed');
        });
        $check("$kind legacy baseline rollback conflict and restore", function () use ($kind,$service,$history,$pdo,$assert,$expect) {
            $table=$kind==='hotspot'?'hotspots':'analysis_articles';
            $q=$pdo->prepare("INSERT INTO $table (slug,title,summary,html,outline,word_count,status) VALUES (?, '旧稿', '旧摘要', '<h2>原文</h2>', '[]',2,'hidden')");$q->execute(['baseline']);$id=(int)$pdo->lastInsertId();
            $expect(422,fn()=>$service->save($kind,['expectedRevision'=>1,'format'=>'markdown','body'=>str_repeat('x',1000001)],$id));
            $assert($history->revisions($kind,$id)['total']===0,'failed write left baseline');
            $service->save($kind,['expectedRevision'=>1,'title'=>'改稿','summary'=>'改摘要','format'=>'markdown','body'=>'新正文'],$id);
            $base=$history->revision($kind,$id,1);
            $assert($base['adminId']===null&&$base['article']['body']==='<h2>原文</h2>','baseline inaccurate');
            $expect(409,fn()=>$service->save($kind,['expectedRevision'=>1,'title'=>'丢失'],$id));
            $assert($history->revisions($kind,$id)['total']===2,'conflict wrote history');
            $pdo->exec("UPDATE $table SET status='published',comment_mode='closed' WHERE id=$id");
            $r=$history->restore($kind,$id,['revision'=>1,'expectedRevision'=>2]);
            $assert($r['revision']===3&&$r['title']==='旧稿'&&$r['summary']==='旧摘要'&&$r['status']==='published'&&$r['commentMode']==='closed','restore visibility/body contract');
            $expect(409,fn()=>$history->restore($kind,$id,['revision'=>1,'expectedRevision'=>2]));
            $assert($history->revision($kind,$id,1)===$base,'restore overwrote old snapshot');
            $expect(404,fn()=>$history->revision($kind,$id,999));
        });
    }
    $check('minimal creation snapshots and restores actual database defaults', function () use ($service,$history,$assert) {
        foreach (['hotspot','analysis'] as $kind) {
            $model=$service->save($kind,['title'=>'默认元数据']);$id=(int)$model->id;
            $fresh=$service->detail($kind,$id);$snapshot=$history->revision($kind,$id,1)['article'];
            $assert($snapshot===$fresh&&App\Resource\ArticleResource::adminDetail($model)===$fresh,'created response/history differs from persisted defaults');
            $assert($kind==='analysis'?$fresh['priority']==='B':$fresh['level']==='A','fixture does not reflect production defaults');
            $service->save($kind,['title'=>'第二版','expectedRevision'=>1],$id);
            $restored=$history->restore($kind,$id,['revision'=>1,'expectedRevision'=>2]);
            $assert($restored['priority']===$fresh['priority']&&($kind!=='hotspot'||$restored['level']===$fresh['level']),'restore changed database default metadata');
        }
    });
    $check('snapshot insertion failure rolls back article and maintenance marker', function () use ($service,$pdo,$assert) {
        $before=$service->detail('hotspot',1);
        $pdo->exec("UPDATE content_maintenance SET maintained=0 WHERE table_name='hotspots'");
        $pdo->exec("CREATE TRIGGER fail_revision BEFORE INSERT ON article_revisions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='snapshot failure'");
        $thrown=false;
        try { $service->save('hotspot',['expectedRevision'=>$before['revision'],'title'=>'应回退'],1); } catch (Throwable $e) {$thrown=true;} finally {$pdo->exec('DROP TRIGGER fail_revision');}
        $assert($thrown&&$service->detail('hotspot',1)===$before&&(int)$pdo->query("SELECT maintained FROM content_maintenance WHERE table_name='hotspots'")->fetchColumn()===0,'snapshot failure committed article/marker');
    });
    $check('dataset reconciliation whitelist and source-only read preserve files and markers', function () use ($history,$pdo,$assert) {
        if (!is_file('/.dockerenv')) throw new RuntimeException('Dataset fixture requires disposable container');
        $root=BASE_PATH.'/storage/dataset';if (!is_dir($root)) mkdir($root,0777,true);
        $source=['slug'=>'source-match','title'=>'来源','summary'=>'摘要','html'=>'<p>正文</p>','period'=>'2026'];
        $datasets=['hotspots.json'=>[$source,$source+['unused'=>true]],'analysis_articles.json'=>[],'mistakes.json'=>[],'mocks.json'=>[],'papers.json'=>[],'predictions.json'=>[],'questions.json'=>[],'stats.json'=>[]];
        $datasets['hotspots.json'][1]['slug']='source-only';
        $manifest=['schema_version'=>1,'datasets'=>[],'totals'=>['bytes'=>0,'files'=>8,'items'=>0]];
        foreach ($datasets as $file=>$rows) {
            $path=$root.'/'.$file;file_put_contents($path,json_encode($rows,JSON_UNESCAPED_UNICODE));
            $manifest['datasets'][$file]=['bytes'=>filesize($path),'sha256'=>hash_file('sha256',$path),'items'=>count($rows),'groups'=>[]];
            $manifest['totals']['bytes']+=filesize($path);$manifest['totals']['items']+=count($rows);
        }
        file_put_contents(BASE_PATH.'/storage/import-manifest.json',json_encode(['asset_count'=>0,'logical_document_count'=>0]));
        $manifest['source_manifest']=['path'=>'storage/import-manifest.json','sha256'=>hash_file('sha256',BASE_PATH.'/storage/import-manifest.json'),'asset_count'=>0,'logical_document_count'=>0];
        file_put_contents(BASE_PATH.'/storage/dataset-manifest.json',json_encode($manifest));
        $q=$pdo->prepare("INSERT INTO hotspots (slug,title,summary,html,outline,period) VALUES (?,?,?,?, '[]',?)");$q->execute(['source-match','来源','摘要','<p>正文</p>','2026']);$id=(int)$pdo->lastInsertId();
        $hash=hash_file('sha256',$root.'/hotspots.json');$marker=$pdo->query("SELECT maintained FROM content_maintenance WHERE table_name='hotspots'")->fetchColumn();
        $diff=$history->sourceDiff('hotspot',$id);$assert($diff['sourceStatus']==='same'&&count($diff['fields'])===4&&$diff['fields'][2]['field']==='html','identical source compared wrongly');
        $pdo->exec("UPDATE hotspots SET title='不同' WHERE id=$id");$assert($history->sourceDiff('hotspot',$id)['sourceStatus']==='different','source differences missing');
        $assert($history->sourceDiff('hotspot',1)['sourceStatus']==='database-only','admin-only content missing');
        $index=$history->sourceDiffIndex('hotspot',999,1);$assert($index['counts']['sourceOnly']===1&&$index['counts']['different']===1&&count($index['items'])===1,'aggregate source reconciliation/pagination');
        $all=$history->sourceDiffIndex('hotspot');$only=array_values(array_filter($all['items'],fn($r)=>$r['sourceStatus']==='source-only'));
        $assert($only[0]['articleId']===null&&$only[0]['slug']==='source-only','missing source-only record');
        $assert(hash_file('sha256',$root.'/hotspots.json')===$hash&&$pdo->query("SELECT maintained FROM content_maintenance WHERE table_name='hotspots'")->fetchColumn()===$marker,'reconciliation modified source or marker');
    });
    $check('concurrent saves accept one version and append one snapshot', function () use ($service,$history,$admin,$assert) {
        $m=$service->save('hotspot',['title'=>'并发','format'=>'markdown','body'=>'原文']);$id=(int)$m->id;
        $channel=new Swoole\Coroutine\Channel(2);
        foreach (['并发甲','并发乙'] as $title) Swoole\Coroutine::create(function () use ($service,$admin,$id,$title,$channel) {
            App\Support\Auth::setUser($admin);
            try {$service->save('hotspot',['title'=>$title,'expectedRevision'=>1],$id);$channel->push(200);} catch (RuntimeException $e) {$channel->push($e->getCode());}
        });
        $results=[$channel->pop(10),$channel->pop(10)];sort($results);
        $assert($results===[200,409]&&$history->revisions('hotspot',$id)['total']===2&&$service->detail('hotspot',$id)['revision']===2,'concurrent revision/history race');
    });
    $check('history readers require freshly active admin', function () use ($history,$expect,$admin) {
        $admin->status='disabled';$admin->save();
        foreach ([fn()=>$history->revisions('hotspot',1),fn()=>$history->revision('hotspot',1,1),fn()=>$history->export('hotspot',1),fn()=>$history->sourceDiff('hotspot',1)] as $fn) $expect(403,$fn);
        $admin->status='active';$admin->save();
        $expect(422,fn()=>$history->sourceDiff('../secret',1));
        $expect(422,fn()=>$history->restore('hotspot',1,['revision'=>1]));
    });
    echo "RESULT $passed passed, ".count($errors)." failed\n"; $failed=$errors!==[];
});
exit($failed?1:0);
