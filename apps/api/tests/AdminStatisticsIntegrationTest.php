<?php
declare(strict_types=1);
if (!str_ends_with((string)getenv('DB_DATABASE'), '_admin_test')) throw new RuntimeException('Disposable admin test DB required');
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH.'/vendor/autoload.php';
$container = require BASE_PATH.'/config/container.php';
Hyperf\Database\Model\Register::setConnectionResolver($container->get(Hyperf\Database\ConnectionResolverInterface::class));
$failed = false;
Swoole\Coroutine\run(function () use (&$failed) {
    $pdo = new PDO('mysql:host='.getenv('DB_HOST').';dbname='.getenv('DB_DATABASE'), getenv('DB_USERNAME'), getenv('DB_PASSWORD'), [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach (['favorites','notes','attempts','study_progress','user_study_stats','site_visit_totals','site_visit_browsers','site_visit_days','admin_audit_logs'] as $table) $pdo->exec("DROP TABLE IF EXISTS $table");
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    foreach (['2026_09_22_000013_create_study_tables.php'=>'CreateStudyTables','2026_09_30_000004_create_user_study_stats_table.php'=>'CreateUserStudyStatsTable','2026_10_07_000001_create_site_visits_tables.php'=>'CreateSiteVisitsTables','2026_09_29_000003_create_admin_audit_logs_table.php'=>'CreateAdminAuditLogsTable'] as $file=>$class) { require BASE_PATH.'/migrations/'.$file; (new $class())->up(); }
    foreach (['mistake_items','mistake_reviews','predictions'] as $table) $pdo->exec("CREATE TABLE IF NOT EXISTS $table (id INT PRIMARY KEY,status VARCHAR(32) NULL)");
    $admin = App\Model\User::where('role','admin')->where('status','active')->first();
    App\Support\Auth::setUser($admin);
    for ($i=0;$i<4;$i++) { $user=new App\Model\User();$user->fill(['email'=>"stats-$i@example.test",'display_name'=>'统计测试','password_hash'=>'test','role'=>'user','status'=>'active']);$user->save(); }
    $pdo->exec("UPDATE users SET created_at='2020-01-01 00:00:00'");
    $userIds = App\Model\User::limit(4)->pluck('id')->all();
    $times=['2026-10-08 15:59:59','2026-10-08 16:00:00','2026-10-10 15:59:59','2026-10-10 16:00:00'];
    foreach ($times as $index=>$time) { $q=$pdo->prepare('UPDATE users SET created_at=? WHERE id=?'); $q->execute([$time,$userIds[$index]]); $q=$pdo->prepare("INSERT INTO attempts (user_id,source,is_right,created_at) VALUES (?,'paper',?,?)"); $q->execute([$userIds[$index],$index===1?1:0,$time]); }
    $pdo->exec("INSERT INTO site_visit_days VALUES ('2026-10-08',99),('2026-10-09',7),('2026-10-10',11),('2026-10-11',99)");
    foreach ([[$userIds[0],'2026-10-08',999],[$userIds[0],'2026-10-09',60],[$userIds[0],'2026-10-10',120],[$userIds[1],'2026-10-10',30],[$userIds[2],'2026-10-10',0],[$userIds[0],'2026-10-11',999]] as $r) { $q=$pdo->prepare('INSERT INTO user_study_stats (user_id,date,seconds) VALUES (?,?,?)');$q->execute($r); }
    $detail=['nested'=>['text'=>str_repeat('完整详情',300)],'html'=>'<script>alert(1)</script>'];
    $q=$pdo->prepare('INSERT INTO admin_audit_logs (admin_id,action,target_type,target_id,detail,created_at) VALUES (?,?,?,?,?,?)');
    foreach (['2026-10-08 15:59:59','2026-10-08 16:00:00','2026-10-10 15:59:59','2026-10-10 16:00:00'] as $time) $q->execute([$admin->id,'article%_.update','hotspot','42',json_encode($detail),$time]);
    $q->execute([$admin->id,'articleXY.update','hotspot','42','{}','2026-10-09 00:00:00']);
    $q->execute([$admin->id,'article%_.update','analysis','42','{}','2026-10-09 00:00:00']);
    $service=(new ReflectionClass(App\Service\AdminService::class))->newInstanceWithoutConstructor();
    $assert=static fn($ok,$message)=>$ok?:throw new RuntimeException($message);
    $expect=static function ($fn,$code=422) use ($assert) { try {$fn();} catch (RuntimeException $e) {$assert($e->getCode()===$code,'wrong error code '.$e->getCode());return;} throw new RuntimeException('request accepted'); };
    $passed=0;$errors=[];
    $check=static function($name,$fn) use (&$passed,&$errors) {try {$fn();++$passed;echo "PASS $name\n";}catch(Throwable $e){$errors[]=$name.': '.$e->getMessage();echo 'FAIL '.end($errors)."\n";}};
    $check('Beijing inclusive registration and attempt boundaries',function() use($service,$assert){$s=$service->overview('2026-10-09','2026-10-10')['statistics']??[];$assert(($s['totals']['registrations']??null)===2&&$s['totals']['attempts']===2&&$s['totals']['correctAttempts']===1,'missing/incorrect interval counts');$assert($s['registrationTrend']===['2026-10-09'=>1,'2026-10-10'=>1]&&$s['attemptsTrend']===$s['registrationTrend'],'UTC grouping not converted');});
    $check('visits and UTC study buckets preserve their actual meaning',function() use($service,$assert){$s=$service->overview('2026-10-09','2026-10-10')['statistics'];$assert($s['totals']['visits']===18&&$s['totals']['studySeconds']===210&&$s['totals']['activeStudyAccounts']===2,'visits/study/account totals');$assert($s['studyDateTimezone']==='UTC'&&$s['timestampTimezone']==='UTC'&&$s['timezone']==='Asia/Shanghai','source timezone absent');$assert($s['studyTrend']===['2026-10-09'=>60,'2026-10-10'=>150]&&$s['visitTrend']===['2026-10-09'=>7,'2026-10-10'=>11],'source buckets changed');});
    $check('empty trend zero fill and compatibility fields',function() use($service,$assert){$r=$service->overview('2027-01-01','2027-01-03');$s=$r['statistics'];$assert($s['dates']===['2027-01-01','2027-01-02','2027-01-03']&&array_sum($s['registrationTrend'])===0&&count($s['studyTrend'])===3,'missing empty days');foreach(['counts','recentUsers','todayUsers','registrationTrend','attemptsTrend','statusCounts','recentAudit'] as $key)$assert(array_key_exists($key,$r),'legacy field missing '.$key);$assert(str_ends_with($s['updatedAt'],'+08:00'),'updatedAt not Beijing ISO');});
    $check('default fourteen Beijing days independent of PHP timezone',function()use($service,$assert){date_default_timezone_set('America/Los_Angeles');try{$s=$service->overview()['statistics'];$assert(count($s['dates'])===14&&$s['to']===(new DateTimeImmutable('now',new DateTimeZone('Asia/Shanghai')))->format('Y-m-d'),'default range timezone');}finally{date_default_timezone_set('UTC');}});
    $check('invalid reversed oversized range rejected and 366 accepted',function()use($service,$expect,$assert){foreach([['2026-02-30','2026-03-01'],['2026-10-10','2026-10-09'],['2026-01-01','2027-01-02'],['2026-1-01','2026-01-02']]as[$from,$to])$expect(fn()=>$service->overview($from,$to));$assert(count($service->overview('2024-01-01','2024-12-31')['statistics']['dates'])===366,'366 days rejected');});
    $check('null and unknown content statuses aggregate published',function()use($pdo,$service,$assert){$pdo->exec("DELETE FROM predictions");$pdo->exec("INSERT INTO predictions (id,status) VALUES (1,NULL),(2,'legacy'),(3,'published'),(4,'hidden')");$s=$service->overview()['statusCounts']['predictions'];$assert($s===['published'=>3,'hidden'=>1]||$s===['hidden'=>1,'published'=>3],'legacy status count overwritten');});
    $check('audit combined filters UTC boundaries full JSON',function()use($service,$admin,$detail,$assert){$r=$service->auditLogs(1,20,'article%_', (int)$admin->id,'2026-10-09','2026-10-10','hotspot','42');$assert($r['total']===2&&count($r['items'])===2,'literal/combo/date filters');$assert($r['items'][0]['detail']===$detail&&$r['items'][0]['createdAt']==='2026-10-10T15:59:59Z','detail or UTC timestamp altered');});
    $check('audit stable and canonical pagination',function()use($service,$assert){$a=$service->auditLogs(999,2);$assert($a['page']===3&&count($a['items'])===2&&$a['total']===6,'last page not clamped');$a=$service->auditLogs(-2,999,'missing');$assert($a['page']===1&&$a['perPage']===100&&$a['items']===[],'empty pagination not normalized');$a=$service->auditLogs(1,1);$b=$service->auditLogs(2,1);$assert($a['items'][0]['id']>$b['items'][0]['id'],'unstable ordering');});
    $check('audit optional dates and invalid ranges',function()use($service,$expect,$assert){$assert($service->auditLogs(1,20,'',0,'','2026-10-08')['total']===1,'upper only boundary');$assert($service->auditLogs(1,20,'',0,'2026-10-11')['total']===1,'lower only boundary');$expect(fn()=>$service->auditLogs(1,20,'',0,'2026-02-30'));$expect(fn()=>$service->auditLogs(1,20,'',0,'2026-10-10','2026-10-09'));});
    $check('fresh disabled and demoted admin guards',function()use($service,$admin,$pdo,$expect){foreach([['status','disabled'],['role','user']]as[$column,$value]){$pdo->exec("UPDATE users SET $column='$value' WHERE id={$admin->id}");try{$expect(fn()=>$service->overview(),403);$expect(fn()=>$service->auditLogs(),403);}finally{$pdo->exec("UPDATE users SET status='active',role='admin' WHERE id={$admin->id}");}}App\Support\Auth::setUser(null);try{$expect(fn()=>$service->overview(),403);$expect(fn()=>$service->auditLogs(),403);}finally{App\Support\Auth::setUser($admin);}});
    $controller=(new ReflectionClass(App\Controller\AdminController::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty($controller,'service'))->setValue($controller,$service);
    (new ReflectionProperty($controller,'request'))->setValue($controller,new Hyperf\HttpServer\Request());
    $respond=static function(string $method,array $params) use ($controller) {
        Hyperf\Context\RequestContext::set((new Hyperf\HttpMessage\Server\Request('GET','/admin/'.$method))->withQueryParams($params));
        Hyperf\Context\Context::destroy('http.request.parsedData');
        Hyperf\Context\ResponseContext::set(new Hyperf\HttpMessage\Server\Response());
        return $controller->$method();
    };
    $check('controller returns 422 validation responses',function()use($respond,$assert){foreach(['overview','auditLogs'] as $method)foreach([['from'=>'2026-02-30'],['from'=>'2026-10-10','to'=>'2026-10-09'],['from'=>['bad']]]as$params){$r=$respond($method,$params);$assert($r->getStatusCode()===422,'controller invalid range accepted');$body=(string)$r->getBody();$assert(!str_contains($body,'SQLSTATE')&&!str_contains($body,'SELECT'),'SQL disclosed');}});
    $check('controller rejects malformed action filter',function()use($respond,$assert){$assert($respond('auditLogs',['action'=>['bad']])->getStatusCode()===422,'array action accepted');});
    echo "RESULT $passed passed, ".count($errors)." failed\n";$failed=$errors!==[];
});
exit($failed?1:0);
