<?php
declare(strict_types=1);
$bootstrap=__DIR__.'/../../apps/api/bootstrap.php';
if(!file_exists($bootstrap)){fwrite(STDERR,"FAIL: backend bootstrap absent; authenticated isolation and grading unavailable\n");exit(1);}
require $bootstrap;
use Mayuan\Application;use Mayuan\ApiError;
function check($v,$m){if(!$v)throw new RuntimeException($m);echo "PASS $m\n";}
function rejected($f,$status,$m){try{$f();}catch(ApiError $e){check($e->status===$status,$m);return;}throw new RuntimeException($m);}
$db=new PDO('sqlite::memory:');$a=new Application($db,'development');$u=['id'=>'dev:learner','name'=>'学习者','role'=>'learner'];$v=['id'=>'dev:second','role'=>'learner'];$admin=['id'=>'dev:admin','role'=>'admin'];
check($a->dispatch('GET','/session',[],null)['user']===null,'anonymous session is readable');
rejected(fn()=>$a->dispatch('GET','/state',[],null),401,'private state needs authentication');
$c=$a->dispatch('GET','/content',[],null);check(count($c['nodes'])===101,'original concepts retained');check(!isset($c['exercises'][0]['answer'])&&!isset($c['exercises'][0]['reason']),'public exercise excludes solution');
$event=['id'=>'visit-1','type'=>'visit','payload'=>['nodeId'=>'marxism'],'baseRevision'=>0];$s=$a->dispatch('POST','/events',$event,$u);check($s['revision']===1,'server revision increases');check($a->dispatch('POST','/events',$event,$u)['revision']===1,'identical retry is idempotent');
$changed=$event;$changed['payload']['nodeId']='matter';rejected(fn()=>$a->dispatch('POST','/events',$changed,$u),409,'event identifiers are immutable');
check($a->dispatch('GET','/state',[],$v)['revision']===0,'users are isolated');
rejected(fn()=>$a->dispatch('POST','/events',['id'=>'stale','type'=>'mastery','payload'=>['nodeId'=>'marxism','mastery'=>'mastered'],'baseRevision'=>0],$u),409,'stale self assessment rejected');
$r=$a->dispatch('POST','/attempts',['eventId'=>'answer-1','exerciseId'=>'q1','chosen'=>0,'reason'=>'猜测','correct'=>true],$u);check($r['correct']===false&&$r['answer']===1,'server authoritative grading');check($a->dispatch('POST','/attempts',['eventId'=>'answer-1','exerciseId'=>'q1','chosen'=>0,'reason'=>'猜测','correct'=>true],$u)['state']['summary']['attempts']===1,'answer retry counts once');
rejected(fn()=>$a->dispatch('GET','/admin/content',[],$u),403,'admin permissions server enforced');
$ac=$a->dispatch('GET','/admin/content',[],$admin);$bad=$ac['draft'];$bad['relations'][0]['to']='unknown';rejected(fn()=>$a->dispatch('PUT','/admin/content',['content'=>$bad,'baseRevision'=>$ac['revision']],$admin),422,'content references validated');
$good=$ac['draft'];$good['nodes'][0]['summary']='新版摘要';$draft=$a->dispatch('PUT','/admin/content',['content'=>$good,'baseRevision'=>$ac['revision']],$admin);check($a->dispatch('GET','/content',[],null)['nodes'][0]['summary']!=='新版摘要','draft remains unpublished');$published=$a->dispatch('POST','/admin/publish',['revision'=>$draft['revision']],$admin);check($a->dispatch('GET','/content',[],null)['nodes'][0]['summary']==='新版摘要','publication persistent');$a->dispatch('POST','/admin/rollback',['version'=>$ac['published']['contentVersion']],$admin);check($a->dispatch('GET','/content',[],null)['nodes'][0]['summary']!=='新版摘要','rollback restores published revision');
rejected(fn()=>$a->dispatch('POST','/import',['progress'=>['version'=>1,'nodes'=>['unknown'=>[]]],'confirm'=>true],$u),422,'invalid import rejected');
$backup=$a->dispatch('GET','/export',[],$u);$import=$a->dispatch('POST','/import',['progress'=>$backup,'confirm'=>true],$v);check($import['summary']['attempts']===0&&count($import['historicalAnswers'])===1,'imported answers stay historical unverified');
$a2=new Application($db,'guanlan');rejected(fn()=>$a2->dispatch('POST','/dev-login',['account'=>'admin'],null),403,'development identity disabled in production');
check($a2->dispatch('GET','/session',[],null)['integration']['configured']===false,'missing integration honestly unconfigured');
foreach(["roundtrip.php","legacy.php","production-session.php","content-validation.php","outbox.php","recall-history.php","import-dedup.php","principal.php"] as $file)require __DIR__."/".$file;echo "ALL API DOMAIN TESTS PASSED\n";
