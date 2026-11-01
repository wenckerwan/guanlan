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
    // Preserve article/statistics/auth fixtures needed by the subsequent HTTP smoke.
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach (['mistake_items', 'mistake_students', 'comments'] as $table) $pdo->exec("DROP TABLE IF EXISTS $table");
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    foreach (['2026_09_22_000006_create_mistake_students_table.php'=>'CreateMistakeStudentsTable', '2026_09_22_000007_create_mistake_items_table.php'=>'CreateMistakeItemsTable'] as $file=>$class) {
        require BASE_PATH.'/migrations/'.$file;
        (new $class())->up();
    }
    $pdo->exec('ALTER TABLE mistake_students ADD owner_user_id BIGINT UNSIGNED NULL UNIQUE');
    $pdo->exec("CREATE TABLE comments (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,article_type VARCHAR(16),article_slug VARCHAR(191),parent_id BIGINT UNSIGNED NULL,floor INT UNSIGNED DEFAULT 0,content TEXT,status VARCHAR(16) DEFAULT 'approved',pinned BOOLEAN DEFAULT FALSE,created_at DATETIME NULL,updated_at DATETIME NULL,FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB");
    $pdo->exec("UPDATE users SET role='admin',status='active' WHERE id=1");
    $pdo->exec("UPDATE users SET role='user',status='active',email='bound%_=owner@example.test',display_name='字面%_=姓名' WHERE id=2");
    $pdo->exec("UPDATE users SET role='user',status='disabled' WHERE id=3");
    $admin=App\Model\User::find(1);
    App\Support\Auth::setUser($admin);
    $insert=$pdo->prepare('INSERT INTO mistake_students (code,name,relation,sort_order,owner_user_id) VALUES (?,?,?,?,?)');
    for ($i=1;$i<=125;$i++) $insert->execute([sprintf('S%03d',$i),$i===7?'姓名%_=特征':'考生'.$i,'测试关系',intdiv($i,3),$i===1?2:null]);
    $insert=$pdo->prepare('INSERT INTO mistake_items (student_id,module,error_type,sort_order,stem,options) VALUES (?,?,?,?,?,?)');
    for ($i=1;$i<=125;$i++) $insert->execute([1,['马原','史纲','思修'][$i%3],$i%2?'概念混淆':'审题失误',intdiv($i,3),'错题'.$i,'["A.选项"]']);
    $insert->execute([2,'另一考生模块','另一考生错因',0,'隔离','[]']);
    $insert=$pdo->prepare('INSERT INTO comments (user_id,article_type,article_slug,content,status,created_at) VALUES (?,?,?,?,?,?)');
    for ($i=1;$i<=125;$i++) $insert->execute([$i%2?2:3,['analysis','hotspot','prediction'][$i%3],'article-'.($i%5),'评论'.$i,$i%2?'pending':'approved','2026-10-10 00:00:00']);
    $insert->execute([2,'hotspot','literal','正文%_=字面','approved','2026-10-10 00:00:00']);
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    $insert->execute([999999,'analysis','orphan','已注销用户的既有评论','approved','2026-10-10 00:00:00']);
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');

    $comments=new App\Service\CommentService();
    $mistakes=class_exists(App\Service\AdminMistakeListService::class)?new App\Service\AdminMistakeListService():null;
    $assert=static function(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);};
    $expect=static function(callable $fn,int $code=422)use($assert):void {try{$fn();}catch(RuntimeException $e){$assert($e->getCode()===$code,'wrong error code '.$e->getCode());return;}throw new RuntimeException('request accepted');};
    $passed=0;$errors=[];
    $check=static function(string $name,callable $fn)use(&$passed,&$errors):void {try{$fn();++$passed;echo "PASS $name\n";}catch(Throwable $e){$errors[]=$name.': '.$e->getMessage();echo 'FAIL '.end($errors)."\n";}};
    $check('student pagination reaches all records with stable tie ordering',function()use($mistakes,$assert){$assert($mistakes!==null,'missing admin mistake read service');$codes=[];for($page=1;$page<=7;$page++){$r=$mistakes->students('', $page,20);$assert($r['total']===125,'student total');$codes=array_merge($codes,array_column($r['items'],'code'));}$assert($codes===array_map(fn($i)=>sprintf('S%03d',$i),range(1,125)),'missing duplicated or unstable students');});
    $check('student code name bound email and literal searches',function()use($mistakes,$assert){$assert($mistakes!==null,'missing admin mistake read service');foreach([[' S125 ','S125'],['姓名%_=','S007'],['bound%_=','S001']]as[$q,$code]){$r=$mistakes->students($q);$assert($r['total']===1&&$r['items'][0]['code']===$code,'student q filter '.$q);}});
    $check('student envelope preserves global counts owner and dataset metadata',function()use($mistakes,$assert){$assert($mistakes!==null,'missing admin mistake read service');$item=$mistakes->students('S001')['items'][0];$assert($item['itemCount']===125&&array_sum($item['moduleCounts'])===125&&array_sum($item['errorTypes'])===125,'counts not complete');$assert($item['ownerId']===2&&$item['ownerEmail']==='bound%_=owner@example.test'&&!$item['isDataset']&&$item['relation']==='测试关系','owner fields changed');$item=$mistakes->students('S125')['items'][0];$assert($item['itemCount']===0&&$item['ownerId']===null&&$item['isDataset'],'dataset empty metadata');});
    $check('student empty and oversized pages normalize',function()use($mistakes,$assert){$assert($mistakes!==null,'missing admin mistake read service');$r=$mistakes->students('',999,20);$assert($r['page']===7&&count($r['items'])===5,'last page');$r=$mistakes->students('missing',999,1000);$assert($r['page']===1&&$r['perPage']===100&&$r['items']===[],'empty page');});
    $check('item exact combination facets remain whole student and response preserved',function()use($mistakes,$assert){$assert($mistakes!==null,'missing admin mistake read service');$r=$mistakes->items('S001','马原','审题失误',999,7);$assert($r['total']===20&&$r['page']===3&&count($r['items'])===6&&$r['code']==='S001'&&$r['name']==='考生1','combination/page/identity');$assert(count($r['filters']['modules'])===3&&count($r['filters']['errorTypes'])===2&&!in_array('另一考生模块',$r['filters']['modules'],true),'facets filtered or crossed students');foreach($r['items']as$item)$assert($item['module']==='马原'&&$item['errorType']==='审题失误'&&$item['options']===['A.选项'],'item resource changed');});
    $check('items stable pagination empty and unknown student 404',function()use($mistakes,$assert,$expect){$assert($mistakes!==null,'missing admin mistake read service');$ids=[];for($page=1;$page<=7;$page++)$ids=array_merge($ids,array_column($mistakes->items('S001','','',$page,20)['items'],'id'));$assert($ids===range(1,125),'tie pagination loses items');$r=$mistakes->items('S001','未出现','',999,0);$assert($r['total']===0&&$r['page']===1&&$r['perPage']===1&&count($r['filters']['modules'])===3,'empty filtered response');$expect(fn()=>$mistakes->items('missing'),404);});
    $check('item facets retain empty legacy values from the whole student',function()use($mistakes,$pdo,$assert){$pdo->exec("INSERT INTO mistake_items (student_id,stem) VALUES (3,'旧数据空分类')");$r=$mistakes->items('S003');$assert($r['filters']['modules']===['']&&$r['filters']['errorTypes']===[''],'empty legacy facet omitted');});
    $check('comments combine all independent filters',function()use($comments,$assert){$r=$comments->adminList('pending','hotspot',1,20,['q'=>'评论31','articleSlug'=>'article-1','userId'=>2,'userQ'=>'字面%_=']);$assert($r['total']===1&&$r['items'][0]['id']===31,'comment combined filter');$assert($comments->adminList('','',1,20,['articleSlug'=>'article-10'])['total']===0,'slug not exact');$assert($comments->adminList('','',1,20,['q'=>'评论31','userId'=>3])['total']===0,'user filter ignored');});
    $check('comment keywords percent underscore equals are literal',function()use($comments,$assert){$r=$comments->adminList('','',1,20,['q'=>' %_= ']);$assert($r['total']===1&&$r['items'][0]['content']==='正文%_=字面','wildcard content');$r=$comments->adminList('','',1,100,['userQ'=>'bound%_=']);$assert($r['total']===64,'literal owner email search');$r=$comments->adminList('','',1,100,['userQ'=>'字面%_=']);$assert($r['total']===64,'literal owner name search');});
    $check('comment pages clamp with orphan disabled owner preserved',function()use($comments,$assert){$r=$comments->adminList('','',999,20);$assert($r['total']===127&&$r['page']===7&&count($r['items'])===7,'comment last page');$r=$comments->adminList('','',1,2);$assert($r['items'][0]['id']===127&&$r['items'][0]['user']['name']==='已注销'&&$r['items'][0]['user']['id']===0,'orphan omitted');$assert($comments->adminList('','',1,100,['userId'=>3])['total']===62,'disabled owners omitted');$r=$comments->adminList('','',999,999,['q'=>'missing']);$assert($r['page']===1&&$r['perPage']===100&&$r['items']===[],'comment empty pages');});
    $check('comment invalid enums ids and malformed strings rejected',function()use($comments,$expect){foreach([['draft','',[]],['','wrong',[]],['','',['userId'=>-1]],['','',['userId'=>0]],['','',['userId'=>'1.2']],['','',['userId'=>'abc']],['','',['userId'=>null]],['','',['userId'=>[]]],['','',['userId'=>true]],['','',['userId'=>'999999999999999999999999']],['','',['q'=>[]]],['','',['userQ'=>[]]],['','',['articleSlug'=>[]]]]as[$status,$type,$filters])$expect(fn()=>$comments->adminList($status,$type,1,20,$filters));});
    $check('all new list readers reject fresh disabled demoted or missing admin',function()use($comments,$mistakes,$pdo,$admin,$assert,$expect){$assert($mistakes!==null,'missing admin mistake read service');foreach([['status','disabled'],['role','user']]as[$column,$value]){$pdo->exec("UPDATE users SET $column='$value' WHERE id=1");try{foreach([fn()=>$mistakes->students(),fn()=>$mistakes->items('S001'),fn()=>$comments->adminList()]as$fn)$expect($fn,403);}finally{$pdo->exec("UPDATE users SET role='admin',status='active' WHERE id=1");}}App\Support\Auth::setUser(null);try{$expect(fn()=>$mistakes->students(),403);$expect(fn()=>$comments->adminList(),403);}finally{App\Support\Auth::setUser($admin);}});

    $commentController=(new ReflectionClass(App\Controller\CommentController::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty($commentController,'service'))->setValue($commentController,$comments);
    (new ReflectionProperty($commentController,'request'))->setValue($commentController,new Hyperf\HttpServer\Request());
    $respond=static function(object $controller,string $method,array $params,array $args=[]){Hyperf\Context\RequestContext::set((new Hyperf\HttpMessage\Server\Request('GET','/admin/test'))->withQueryParams($params));Hyperf\Context\Context::destroy('http.request.parsedData');Hyperf\Context\ResponseContext::set(new Hyperf\HttpMessage\Server\Response());return$controller->$method(...$args);};
    $check('comment controller validates enum id arrays and forwards filters',function()use($commentController,$respond,$assert){foreach([['status'=>'draft'],['articleType'=>'wrong'],['userId'=>'0'],['userId'=>'bad'],['q'=>['bad']],['articleSlug'=>['bad']],['page'=>['bad']]]as$params)$assert($respond($commentController,'adminIndex',$params)->getStatusCode()===422,'controller filter accepted');$r=$respond($commentController,'adminIndex',['q'=>'评论31','userId'=>'2','articleSlug'=>'article-1']);$body=json_decode((string)$r->getBody(),true);$assert($r->getStatusCode()===200&&$body['data']['total']===1,'controller filters not wired');});
    $check('student controller forwards envelopes filters and status codes',function()use($mistakes,$respond,$assert,$comments){$assert($mistakes!==null,'missing admin mistake read service');$controller=(new ReflectionClass(App\Controller\AdminController::class))->newInstanceWithoutConstructor();(new ReflectionProperty($controller,'mistakeLists'))->setValue($controller,$mistakes);(new ReflectionProperty($controller,'request'))->setValue($controller,new Hyperf\HttpServer\Request());$r=$respond($controller,'mistakeStudents',['q'=>'S125','page'=>'999']);$body=json_decode((string)$r->getBody(),true);$assert($r->getStatusCode()===200&&$body['data']['total']===1&&$body['data']['page']===1,'student controller envelope');$r=$respond($controller,'mistakeItems',['module'=>'马原','errorType'=>'审题失误','page'=>999],['S001']);$body=json_decode((string)$r->getBody(),true);$assert($body['data']['total']===20&&isset($body['data']['filters']),'item controller filters');$assert($respond($controller,'mistakeItems',[],['missing'])->getStatusCode()===404,'missing student not 404');$assert($respond($controller,'mistakeStudents',['q'=>[]])->getStatusCode()===422,'student malformed q');App\Support\Auth::setUser(App\Model\User::find(2));try{$assert($respond($controller,'mistakeItems',[],['S001'])->getStatusCode()===403,'ordinary user not forbidden');}finally{App\Support\Auth::setUser(App\Model\User::find(1));}});
    echo "RESULT $passed passed, ".count($errors)." failed\n";
    $failed=$errors!==[];
});
exit($failed?1:0);
