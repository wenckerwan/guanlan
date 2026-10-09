<?php
declare(strict_types=1);
if (!str_ends_with((string)getenv('DB_DATABASE'), '_admin_test')) throw new RuntimeException('Disposable admin test DB required');
define('BASE_PATH',dirname(__DIR__));
require BASE_PATH.'/vendor/autoload.php';
$container=require BASE_PATH.'/config/container.php';
Hyperf\Database\Model\Register::setConnectionResolver($container->get(Hyperf\Database\ConnectionResolverInterface::class));
$failed=false;
if (($argv[1] ?? '') === 'issue-worker') {
 Swoole\Coroutine\run(function():void {
  $auth=new App\Service\AuthService(new App\Service\MistakeAccountService(new App\Service\MistakeProfileService()));
  $snapshot=$auth->login('admin@example.test','final-password');
  if (!$snapshot) throw new RuntimeException('Worker login failed');
  App\Support\Auth::issue($snapshot);
  echo "issued";
 });
 exit(0);
}

Swoole\Coroutine\run(function()use(&$failed):void{
 $pdo=new PDO('mysql:host='.getenv('DB_HOST').';dbname='.getenv('DB_DATABASE'),getenv('DB_USERNAME'),getenv('DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 foreach(['users','user_tokens','mistake_accounts','mistake_students','mistake_profiles']as$table)$pdo->exec("DROP TABLE IF EXISTS $table");
 $pdo->exec("CREATE TABLE users (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,email VARCHAR(191) UNIQUE,display_name VARCHAR(191),role VARCHAR(16),status VARCHAR(16),user_group VARCHAR(16),mistake_code VARCHAR(32),password_hash VARCHAR(255),last_login_at DATETIME NULL,created_at DATETIME NULL,updated_at DATETIME NULL) ENGINE=InnoDB");
 $pdo->exec("CREATE TABLE user_tokens (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id BIGINT UNSIGNED,token_hash CHAR(64),user_agent VARCHAR(191),expires_at DATETIME,created_at DATETIME NULL,updated_at DATETIME NULL) ENGINE=InnoDB");
 $pdo->exec("CREATE TABLE mistake_accounts (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id BIGINT UNSIGNED UNIQUE,created_at DATETIME NULL,updated_at DATETIME NULL) ENGINE=InnoDB");
 $pdo->exec("CREATE TABLE mistake_students (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,owner_user_id BIGINT UNSIGNED UNIQUE,code VARCHAR(32),name VARCHAR(191),relation VARCHAR(64),detail_html TEXT,sort_order INT,created_at DATETIME NULL,updated_at DATETIME NULL) ENGINE=InnoDB");
 $pdo->exec("CREATE TABLE mistake_profiles (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,student_id BIGINT UNSIGNED UNIQUE,markdown TEXT,html TEXT,source_file VARCHAR(255),is_default BOOLEAN,created_at DATETIME NULL,updated_at DATETIME NULL) ENGINE=InnoDB");
 foreach(['hotspots','analysis_articles']as$table)$pdo->exec("ALTER TABLE $table ADD COLUMN comment_mode VARCHAR(16) DEFAULT 'open'");
 $pdo->exec("CREATE TABLE predictions LIKE hotspots");
 $pdo->exec("INSERT INTO predictions (id,slug,title,status) VALUES (1,'test-prediction','Test','published')");
 $auth=new App\Service\AuthService(new App\Service\MistakeAccountService(new App\Service\MistakeProfileService()));
 $admin=new App\Service\AdminService(new App\Service\MistakeService(),new App\Service\MistakeProfileService(),$auth);
 $user=App\Model\User::create(['email'=>'admin@example.test','display_name'=>'Admin','role'=>'admin','status'=>'active','password_hash'=>password_hash('old-password',PASSWORD_DEFAULT)]);
 $assert=static function(bool$ok,string$message):void{if(!$ok)throw new RuntimeException($message);};
 $passed=0;$failures=[];$check=static function(string$name,callable$test)use(&$passed,&$failures):void{try{$test();++$passed;echo"PASS $name\n";}catch(Throwable$e){$failures[]=$name.': '.$e->getMessage();echo'FAIL '.end($failures)."\n";}};
 $check('stale authenticated snapshot cannot issue after password reset',function()use($auth,$admin,$user,$assert,$pdo){
  $snapshot=$auth->login('admin@example.test','old-password');
  $admin->resetPassword($user->id,'new-password');
  try{App\Support\Auth::issue($snapshot);}catch(RuntimeException$e){$assert($e->getCode()===401,'incorrect error');$assert((int)$pdo->query('SELECT COUNT(*) FROM user_tokens')->fetchColumn()===0,'token persisted');return;}
  throw new RuntimeException('stale credential accepted');
 });
 $check('fresh password login issues resolvable token',function()use($auth,$assert){$u=$auth->login('admin@example.test','new-password');$token=App\Support\Auth::issue($u);$assert(App\Support\Auth::resolve($token)?->id===$u->id,'valid login rejected');});
 $check('issued token is revoked by subsequent reset',function()use($auth,$admin,$user,$assert){$u=$auth->login('admin@example.test','new-password');$token=App\Support\Auth::issue($u);$admin->resetPassword($user->id,'final-password');$assert(App\Support\Auth::resolve($token)===null,'old token still valid');});
 $check('concurrent issuance and reset leave no old-password session',function()use($admin,$user,$pdo,$assert){
  $pdo->exec("CREATE TRIGGER delay_token BEFORE INSERT ON user_tokens FOR EACH ROW DO SLEEP(0.5)");
  $pipes=[];$process=proc_open([PHP_BINARY,__FILE__,'issue-worker'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
  if(!is_resource($process))throw new RuntimeException('Worker failed to start');
  fclose($pipes[0]);$deadline=microtime(true)+5;$observed=false;
  while(microtime(true)<$deadline){
   $n=$pdo->query("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE STATE='User sleep'")->fetchColumn();
   if($n){$observed=true;break;}usleep(10000);
  }
  if($observed)$admin->resetPassword($user->id,'after-concurrent-reset');
  $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
  $exit=proc_close($process);$pdo->exec('DROP TRIGGER delay_token');
  $assert($observed,'Did not observe token insert overlap: '.$out.$err);
  $assert($exit===0&&$out==='issued','Worker issue failed: '.$err);
  $assert((int)$pdo->query('SELECT COUNT(*) FROM user_tokens')->fetchColumn()===0,'Old-password token survived reset');
 });
 putenv('MAIL_ENABLED=true');putenv('MAIL_ACCOUNTS=[{"username":"test@example.test","password":"test-only","host":"invalid.example"}]');
 $check('public registration still requires mail verification',function()use($auth,$assert){$r=$auth->register('public@example.test','test-password','Public');$assert(isset($r['error'])&&!isset($r['user']),'public verification bypassed');});
 App\Support\Auth::setUser($user->fresh());
 $check('admin creation works with mail enabled and provisions account',function()use($admin,$assert,$pdo){$r=$admin->createUser('created@example.test','test-password','Created','admin');$assert(isset($r['user'])&&$r['user']->role==='admin','creation blocked');$id=$r['user']->id;$assert((int)$pdo->query("SELECT COUNT(*) FROM mistake_accounts WHERE user_id=$id")->fetchColumn()===1,'account missing');$assert((int)$pdo->query("SELECT COUNT(*) FROM mistake_students WHERE owner_user_id=$id")->fetchColumn()===1,'student missing');});
 App\Support\Auth::setUser(null);
 $check('service rejects non-admin creation',function()use($admin,$pdo,$assert){$n=$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();try{$r=$admin->createUser('denied@example.test','test-password','Denied','admin');if(isset($r['error'])&&($r['status']??0)===403){$assert($pdo->query('SELECT COUNT(*) FROM users')->fetchColumn()===$n,'denied insert');return;}}catch(RuntimeException$e){$assert($e->getCode()===403,'wrong rejection');return;}throw new RuntimeException('creation accepted without admin');});
 $ordinary=App\Model\User::create(['email'=>'ordinary@example.test','role'=>'user','status'=>'active','password_hash'=>password_hash('test-password',PASSWORD_DEFAULT)]);
 App\Support\Auth::setUser($ordinary);
 $check('ordinary user cannot directly create administrator',function()use($admin,$assert,$pdo){$n=$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();try{$admin->createUser('escalated@example.test','test-password','','admin');}catch(RuntimeException$e){$assert($e->getCode()===403,'ordinary user not rejected');$assert($pdo->query('SELECT COUNT(*) FROM users')->fetchColumn()===$n,'unauthorized user created');return;}throw new RuntimeException('ordinary creation allowed');});
 App\Support\Auth::setUser($user->fresh());
 $check('failed provisioning rolls back user and account',function()use($admin,$pdo,$assert){
  $tables=['users','mistake_accounts','mistake_students','mistake_profiles'];$before=[];foreach($tables as$table)$before[$table]=$pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn();
  $pdo->exec("CREATE TRIGGER reject_profile BEFORE INSERT ON mistake_profiles FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected provisioning failure'");
  $rejected=false;try{$admin->createUser('rollback@example.test','test-password','Rollback','admin');}catch(Throwable$e){$rejected=true;}finally{$pdo->exec('DROP TRIGGER reject_profile');}
  $assert($rejected,'failed provisioning accepted');foreach($tables as$table)$assert($pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn()===$before[$table],'orphan left in '.$table);
 });
 $check('admin rejects malformed email and short password',function()use($admin,$assert){foreach([['invalid','long-password'],['valid@example.test','x']]as[$email,$password]){try{$r=$admin->createUser($email,$password,'','user');if(isset($r['error'])&&($r['status']??0)===422)continue;}catch(RuntimeException$e){$assert($e->getCode()===422,'wrong validation status');continue;}throw new RuntimeException('invalid creation accepted');}});
 $comment=new App\Service\CommentService();
 foreach(['hotspot'=>'hotspots','analysis'=>'analysis_articles','prediction'=>'predictions']as$type=>$table)$check("$type comment mode protects import",function()use($comment,$type,$table,$pdo,$assert){$pdo->exec("UPDATE content_maintenance SET maintained=0 WHERE table_name='$table'");$slug=$pdo->query("SELECT slug FROM $table WHERE id=1")->fetchColumn();$assert($comment->setMode($type,$slug,'closed'),'mode save failed');$assert((int)$pdo->query("SELECT maintained FROM content_maintenance WHERE table_name='$table'")->fetchColumn()===1,'maintenance not marked');try{App\Support\ContentMaintenance::import([$table],fn()=>throw new RuntimeException('import ran'));}catch(RuntimeException$e){$assert(str_contains($e->getMessage(),'拒绝覆盖'),'import not protected');return;}throw new RuntimeException('import allowed');});
 echo"RESULT $passed passed, ".count($failures)." failed\n";$failed=$failures!==[];
});
exit($failed?1:0);
