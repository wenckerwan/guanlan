<?php
declare(strict_types=1);
if(getenv('PRACTICE_RELEASE_VERIFY')!=='20261011')throw new RuntimeException('Explicit release verification marker required');
$pdo=new PDO('mysql:host='.getenv('DB_HOST').';dbname='.getenv('DB_DATABASE'),getenv('DB_USERNAME'),getenv('DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$mode=$argv[1]??'';
$credentialsPath='/tmp/practice-live-credentials.json'; $statePath='/tmp/practice-live-state.json';
if($mode==='prepare'){
    if(is_file($statePath))throw new RuntimeException('Refusing to replace verification state');
    $credentials=json_decode((string)file_get_contents($credentialsPath),true,512,JSON_THROW_ON_ERROR);
    $ids=[]; $pdo->beginTransaction();
    try {
        foreach($credentials['accounts'] as $key=>$email){
            if(!preg_match('/^practice-release-20261011-[a-z0-9-]+@example\.test$/',$email))throw new RuntimeException('Unexpected fixture identity');
            $pdo->prepare("INSERT INTO users (email,password_hash,display_name,role,status,user_group,created_at,updated_at) VALUES (?,?,?,?,'active','user',UTC_TIMESTAMP(),UTC_TIMESTAMP())")
                ->execute([$email,password_hash($credentials['password'],PASSWORD_DEFAULT),'时政发布验证'.strtoupper($key),$key==='admin'?'admin':'user']);
            $ids[$key]=(int)$pdo->lastInsertId();
        }
        file_put_contents($statePath,json_encode(['ids'=>$ids,'accounts'=>$credentials['accounts']],JSON_THROW_ON_ERROR));chmod($statePath,0600);
        $pdo->commit(); echo "TEMPORARY_RELEASE_ACCOUNTS_CREATED\n";
    }catch(Throwable $e){$pdo->rollBack();throw $e;}
    exit;
}
$state=json_decode((string)file_get_contents($statePath),true,512,JSON_THROW_ON_ERROR);
if($mode==='cleanup'){
    $pdo->beginTransaction();
    try {
        foreach($state['ids'] as $key=>$id){
            $q=$pdo->prepare('SELECT email FROM users WHERE id=?');$q->execute([$id]);$email=$q->fetchColumn();
            if($email!==$state['accounts'][$key]||!str_starts_with($email,'practice-release-20261011-'))throw new RuntimeException('Cleanup identity mismatch');
            foreach(['practice_attempts','favorites','notes','attempts','study_progress','user_tokens','user_study_stats'] as $table){$pdo->prepare("DELETE FROM $table WHERE user_id=?")->execute([$id]);}
            $pdo->prepare('DELETE FROM users WHERE id=? AND email=?')->execute([$id,$email]);
        }
        $pdo->commit();
        foreach($state['ids'] as $id){$q=$pdo->prepare('SELECT COUNT(*) FROM practice_attempts WHERE user_id=?');$q->execute([$id]);if((int)$q->fetchColumn()!==0)throw new RuntimeException('Verification attempts remain');}
        unlink($statePath);unlink($credentialsPath);echo "TEMPORARY_RELEASE_ACCOUNTS_AND_ATTEMPTS_REMOVED\n";
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    exit;
}
if(!in_array($mode,['http','legacy'],true))throw new RuntimeException('Unsupported mode');
$credentials=json_decode((string)file_get_contents($credentialsPath),true,512,JSON_THROW_ON_ERROR);
$tokens=[];$passed=0;
$assert=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);};
$http=static function(string $path,int $expected=200,string $method='GET',?array $body=null,string $identity='a')use(&$tokens,&$passed):array{
    $header="Content-Type: application/json\r\nConnection: close\r\n".(isset($tokens[$identity])?"Authorization: Bearer {$tokens[$identity]}\r\n":'');
    $context=stream_context_create(['http'=>['method'=>$method,'timeout'=>15,'ignore_errors'=>true,'header'=>$header,'content'=>$body===null?'':json_encode($body)]]);
    $raw=file_get_contents('http://127.0.0.1:9501/api/v1'.$path,false,$context);preg_match('/HTTP\/\S+ (\d+)/',$http_response_header[0]??'',$match);
    if((int)($match[1]??0)!==$expected)throw new RuntimeException("Unexpected status for $method $path");
    $result=json_decode((string)$raw,true);if(!is_array($result))throw new RuntimeException('Invalid API JSON');++$passed;echo "PASS LIVE $method $path $expected\n";return $result['data']??$result;
};
try {
    foreach($credentials['accounts'] as $key=>$email){$result=$http('/auth/login',200,'POST',['email'=>$email,'password'=>$credentials['password']],'guest');$tokens[$key]=$result['token'];}
    if($mode==='legacy'){
        $question=$pdo->query("SELECT id,pid,module_name,answer FROM questions WHERE type IN ('single','multi') AND answer REGEXP '^[ABCD]{1,4}$' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if(!$question)throw new RuntimeException('Missing objective paper question');
        $attempt=$http('/study/attempts',201,'POST',['source'=>'paper','sourceRef'=>$question['pid'],'questionRef'=>(string)$question['id'],'module'=>$question['module_name'],'chosen'=>$question['answer'],'correct'=>$question['answer']],'b');
        $assert($attempt['isRight'],'Legacy grading');
        $summary=$http('/study/stats',200,'GET',null,'b');$assert($summary['total']===1&&$summary['right']===1&&$summary['currentAffairs']['submissionCount']===0,'Legacy/practice totals');
        $progress=$http('/study/progress',200,'GET',null,'b');$assert(count($progress)>=1,'Legacy progress persistence');
        echo "LIVE_LEGACY_PAPER_ATTEMPT_AND_PROGRESS_PASS\n";
    } else {
    $preview=$http('/practice/banks/current-affairs-2026',200,'GET',null,'guest');$assert(count($preview['questions'])===6,'Guest preview');
    $bank=$http('/practice/banks/current-affairs-2026');$assert(count($bank['questions'])===101,'Full bank');
    $question=array_values(array_filter($bank['questions'],fn($q)=>$q['questionRef']==='SZ08-1'))[0];
    $uuid=static fn()=>sprintf('%08x-%04x-4%03x-8%03x-%012x',random_int(0,0xffffffff),random_int(0,0xffff),random_int(0,0xfff),random_int(0,0xfff),random_int(0,0xffffffffff));
    $body=['bankId'=>$bank['bankId'],'questionRef'=>'SZ08-1','questionVersion'=>$question['questionVersion'],'eventId'=>$uuid(),'chosen'=>['A'],'correct'=>['A'],'isRight'=>true,'userId'=>$state['ids']['b']];
    $http('/study/practice/attempts',401,'POST',$body,'guest');$wrong=$http('/study/practice/attempts',201,'POST',$body);$assert(!$wrong['isRight'],'Forged judging');
    $body['eventId']=$uuid();$body['chosen']=['C'];$right=$http('/study/practice/attempts',201,'POST',$body);$metrics=$right['bankSummary'];
    $assert($metrics['submissionCount']===2&&$metrics['uniqueQuestionCount']===1&&$metrics['firstAccuracy']==0&&$metrics['latestAccuracy']==100&&$metrics['repeatCount']===1,'Confirmed statistics contract');
    echo "LIVE_EXPECTED_METRICS submissions=2 unique=1 first=0 latest=100 repeats=1\n";
    $retry=$http('/study/practice/attempts',200,'POST',$body);$assert($retry['duplicate']&&$retry['attemptId']===$right['attemptId'],'Event retry');
    $body['chosen']=['D'];$http('/study/practice/attempts',409,'POST',$body);$body['eventId']=$uuid();$body['chosen']=[];$http('/study/practice/attempts',422,'POST',$body);
    $other=$http('/study/practice/banks/current-affairs-2026/state',200,'GET',null,'b');$assert($other['summary']['submissionCount']===0,'Account isolation');
    $stats=$http('/study/stats');$assert($stats['total']===2&&$stats['right']===1&&$stats['currentAffairs']['repeatCount']===1,'Main statistics');
    $http('/study/progress');$history=$http('/study/practice/attempts?perPage=1');$assert($history['total']===2&&count($history['items'])===1,'History');
    $http('/study/favorites',200,'PUT',['targetType'=>'practice_question','targetId'=>$bank['bankId'].':SZ08-1','favorited'=>true,'title'=>'发布验证','url'=>'/practice/current-affairs#q-SZ08-1']);
    $stateResult=$http('/study/practice/banks/current-affairs-2026/state');$assert($stateResult['questions']['SZ08-1']['favorited'],'Favorite');
    $http('/admin/attempts?source=practice',403,'GET',null,'a');$admin=$http('/admin/attempts?source=practice&page=1&perPage=100',200,'GET',null,'admin');
    $ours=array_filter($admin['items'],fn($row)=>$row['userId']===$state['ids']['a']);$assert(count($ours)===2,'Admin attempts');
    $date=(new DateTimeImmutable('now',new DateTimeZone('Asia/Shanghai')))->format('Y-m-d');$overview=$http('/admin/overview?from='.$date.'&to='.$date,200,'GET',null,'admin');$assert($overview['statistics']['totals']['attempts']>=2,'Admin trend');
    $pid=$pdo->query('SELECT pid FROM papers ORDER BY id LIMIT 1')->fetchColumn();$qid=$pdo->query('SELECT id FROM questions ORDER BY id LIMIT 1')->fetchColumn();
    $http('/papers');if($pid)$http('/papers/'.rawurlencode($pid));if($qid)$http('/questions/'.$qid);
    echo "LIVE_PRACTICE_HTTP_COMPLETE $passed requests\n";
    }
} finally {foreach(array_keys($tokens)as $identity)$http('/auth/logout',200,'POST',[],$identity);}
