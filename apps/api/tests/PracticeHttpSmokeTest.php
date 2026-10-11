<?php
declare(strict_types=1);
if (!str_ends_with((string)getenv('DB_DATABASE'), '_admin_test')) throw new RuntimeException('Disposable database required');
$tokens=[]; $passed=0;
$assert=static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$http=static function (string $path, int $expected=200, string $method='GET', ?array $body=null, string $identity='a') use (&$tokens, &$passed): array {
    $headers="Content-Type: application/json\r\nConnection: close\r\n";
    if (isset($tokens[$identity])) $headers.="Authorization: Bearer {$tokens[$identity]}\r\n";
    $context=stream_context_create(['http'=>['method'=>$method,'timeout'=>15,'ignore_errors'=>true,'header'=>$headers,'content'=>$body===null?'':json_encode($body)]]);
    $raw=file_get_contents('http://127.0.0.1:9501/api/v1'.$path,false,$context);
    preg_match('/HTTP\/\S+ (\d+)/',$http_response_header[0]??'',$match); $status=(int)($match[1]??0);
    if ($status!==$expected) throw new RuntimeException("$method $path expected $expected got $status");
    $result=json_decode((string)$raw,true);
    if (!is_array($result)) throw new RuntimeException('Invalid response JSON');
    ++$passed; echo "PASS HTTP $method $path $status\n";
    return $result['data']??$result;
};
foreach (['a','b','admin'] as $identity) {
    $login=$http('/auth/login',200,'POST',['email'=>"practice-$identity@example.test",'password'=>'Practice-test-20261011!'],'guest');
    $tokens[$identity]=$login['token'];
}
try {
    $guest=$http('/practice/banks/current-affairs-2026',200,'GET',null,'guest');
    $assert(count($guest['questions'])===6 && $guest['questionCount']===101,'guest visibility');
    $bank=$http('/practice/banks/current-affairs-2026');
    $assert(count($bank['questions'])===101,'logged-in visibility');
    $q=array_values(array_filter($bank['questions'],fn($q)=>$q['questionRef']==='SZ08-1'))[0];
    $id=static fn()=>sprintf('%08x-%04x-4%03x-8%03x-%012x',random_int(0,0xffffffff),random_int(0,0xffff),random_int(0,0xfff),random_int(0,0xfff),random_int(0,0xffffffffff));
    $body=['bankId'=>'current-affairs-2026','questionRef'=>'SZ08-1','questionVersion'=>$q['questionVersion'],'eventId'=>$id(),'chosen'=>['A'],'userId'=>999,'correct'=>['A'],'isRight'=>true];
    $http('/study/practice/attempts',401,'POST',$body,'guest');
    $wrong=$http('/study/practice/attempts',201,'POST',$body); $assert($wrong['isRight']===false,'client forged correctness accepted');
    $body['eventId']=$id(); $body['chosen']=['C'];
    $right=$http('/study/practice/attempts',201,'POST',$body); $s=$right['bankSummary'];
    $assert($right['isRight']===true && $s['submissionCount']===2 && $s['uniqueQuestionCount']===1 && (float)$s['firstAccuracy']===0.0 && (float)$s['latestAccuracy']===100.0 && $s['repeatCount']===1,'wrong then right metrics');
    $duplicate=$http('/study/practice/attempts',200,'POST',$body); $assert($duplicate['duplicate']===true && $duplicate['attemptId']===$right['attemptId'],'duplicate event');
    $body['chosen']=['D']; $http('/study/practice/attempts',409,'POST',$body);
    $body['eventId']=$id(); $body['chosen']=[]; $http('/study/practice/attempts',422,'POST',$body);
    $state=$http('/study/practice/banks/current-affairs-2026/state'); $assert($state['questions']['SZ08-1']['attemptCount']===2,'state persistence');
    $other=$http('/study/practice/banks/current-affairs-2026/state',200,'GET',null,'b'); $assert($other['summary']['submissionCount']===0,'account isolation');
    $stats=$http('/study/stats'); $assert($stats['total']===2 && $stats['right']===1 && $stats['currentAffairs']['repeatCount']===1,'main stats totals');
    $http('/study/progress');
    $history=$http('/study/practice/attempts?perPage=1'); $assert($history['total']===2 && count($history['items'])===1,'history pagination');
    $http('/study/favorites',200,'PUT',['targetType'=>'practice_question','targetId'=>'current-affairs-2026:SZ08-1','favorited'=>true,'title'=>'时政测试','url'=>'/practice/current-affairs#SZ08-1']);
    $state=$http('/study/practice/banks/current-affairs-2026/state'); $assert($state['questions']['SZ08-1']['favorited']===true,'favorite state');
    $http('/admin/attempts?source=practice&page=1&perPage=20',403,'GET',null,'a');
    $admin=$http('/admin/attempts?source=practice&page=1&perPage=20',200,'GET',null,'admin');
    $items=$admin['items']??$admin; $assert(count($items)>=2,'admin attempts missing');
    $date=(new DateTimeImmutable('now',new DateTimeZone('Asia/Shanghai')))->format('Y-m-d');
    $dashboard=$http('/admin/overview?from='.$date.'&to='.$date,200,'GET',null,'admin');
    $assert(($dashboard['statistics']['totals']['attempts']??$dashboard['totals']['attempts']??$dashboard['attempts']??0)>=2,'dashboard attempts missing');
    echo "PRACTICE_HTTP_COMPLETE $passed requests; wrong/right/duplicate/user-isolation/main/admin verified\n";
} finally {
    foreach (array_keys($tokens) as $identity) $http('/auth/logout',200,'POST',[],$identity);
}
