<?php
declare(strict_types=1);
if(!str_ends_with((string)getenv('DB_DATABASE'),'_admin_test'))throw new RuntimeException('Disposable test DB required');
define('BASE_PATH',dirname(__DIR__));require BASE_PATH.'/vendor/autoload.php';$c=require BASE_PATH.'/config/container.php';
Hyperf\Database\Model\Register::setConnectionResolver($c->get(Hyperf\Database\ConnectionResolverInterface::class));
$failed=false;
Swoole\Coroutine\run(function()use(&$failed){
 $pdo=new PDO('mysql:host='.getenv('DB_HOST').';dbname='.getenv('DB_DATABASE'),getenv('DB_USERNAME'),getenv('DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 foreach(['hotspots','analysis_articles','mocks','questions','papers']as$t){$pdo->exec("DROP TABLE IF EXISTS $t");}
 foreach(['hotspots','analysis_articles']as$t){$pdo->exec("CREATE TABLE $t (id INT PRIMARY KEY,slug VARCHAR(191),title VARCHAR(191),summary TEXT,status VARCHAR(16),period VARCHAR(32),category VARCHAR(32),priority VARCHAR(8)) ENGINE=InnoDB");$q=$pdo->prepare("INSERT INTO $t VALUES (?,?,?,?,?,?,?,?)");for($i=1;$i<=125;$i++)$q->execute([$i,'s'.$i,'文章'.$i,$i===1?'literal%_':'Summary',$i%2?'published':'hidden',$i%3?'2026年10月':'2026年9月',$i%3?'选择题':'分析题','A']);}
 $pdo->exec('CREATE TABLE mocks (id INT PRIMARY KEY) ENGINE=InnoDB');for($i=1;$i<=45;$i++)$pdo->exec("INSERT INTO mocks VALUES ($i)");
 $pdo->exec('CREATE TABLE questions (id INT PRIMARY KEY,pid VARCHAR(32),no INT) ENGINE=InnoDB');for($i=1;$i<=125;$i++)$pdo->exec("INSERT INTO questions VALUES ($i,'paper',$i)");
 $pdo->exec('CREATE TABLE papers (id INT PRIMARY KEY,sort_order INT) ENGINE=InnoDB');for($i=1;$i<=35;$i++)$pdo->exec("INSERT INTO papers VALUES ($i,0)");
 $s=(new ReflectionClass(App\Service\AdminService::class))->newInstanceWithoutConstructor();$n=0;$errors=[];
 $assert=fn($ok,$m)=>$ok?:throw new RuntimeException($m);
 $check=function($name,$test)use(&$n,&$errors){try{$test();++$n;echo"PASS $name\n";}catch(Throwable$e){$errors[]=$name.': '.$e->getMessage();echo'FAIL '.end($errors)."\n";}};
 foreach(['hotspots','analysis']as$method){
 $check("$method lists beyond 100",function()use($s,$method,$assert){$r=$s->$method(1,20,[]);$assert(isset($r['items'])&&$r['total']===125&&count($r['items'])===20,'missing paginated total');});
 $check("$method literal keyword",function()use($s,$method,$assert){$r=$s->$method(1,20,['q'=>'%_']);$assert($r['total']===1&&$r['items'][0]->id===1,'wildcard search');});
 $check("$method filters status and facet",function()use($s,$method,$assert){$f=['status'=>'hidden',($method==='hotspots'?'period':'category')=>($method==='hotspots'?'2026年9月':'分析题')];$r=$s->$method(1,20,$f);$assert($r['total']===20,'wrong filtered count');});
 $check("$method canonical last page and facets",function()use($s,$method,$assert){$r=$s->$method(999,20,[]);$assert($r['page']===7&&count($r['items'])===5,'invalid last page');$assert(count($r['filters'][$method==='hotspots'?'periods':'categories'])===2,'missing complete facets');});
 }
 $check('mock pages reach last records',function()use($s,$assert){$r=$s->mocks(999,20);$assert($r['page']===3&&$r['total']===45&&count($r['items'])===5,'mock truncation');});
 $check('questions beyond 100 and empty pages',function()use($s,$assert){$r=$s->paperQuestions('paper',7,20);$assert($r['total']===125&&count($r['items'])===5,'question truncation');$r=$s->paperQuestions('missing',5,20);$assert($r['page']===1&&$r['items']===[],'missing paper page');});
 $check('papers normalize last page after deletion',function()use($s,$assert,$pdo){$r=$s->papers(999,20);$assert($r['page']===2&&count($r['items'])===15,'papers stranded page');$pdo->exec('DELETE FROM papers WHERE id>20');$r=$s->papers(2,20);$assert($r['page']===1&&count($r['items'])===20,'empty last page not normalized');});
 $check('invalid status rejected',function()use($s){try{$s->hotspots(1,20,['status'=>'draft']);}catch(RuntimeException$e){return;}throw new RuntimeException('invalid status accepted');});
 echo"RESULT $n passed, ".count($errors)." failed\n";$failed=$errors!==[];
});exit($failed?1:0);
