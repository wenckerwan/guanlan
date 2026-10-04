<?php
require __DIR__.'/../../apps/api/bootstrap.php';
use Mayuan\Outbox;
if(!class_exists(Outbox::class)){fwrite(STDERR,"FAIL: durable outbox retry worker absent\n");exit(1);}
$db=new PDO('sqlite::memory:');$store=new Mayuan\Storage($db);$store->put('outbox-test',[['eventId'=>'summary-1','revision'=>1,'status'=>'pending','retries'=>0,'nextAttemptAt'=>0],['eventId'=>'summary-2','revision'=>2,'status'=>'pending','retries'=>0,'nextAttemptAt'=>0]]);$worker=new Outbox($store);
$worker->drain('outbox-test',fn($event)=>['status'=>429,'retryAfter'=>120],1000);
$rows=$store->get('outbox-test');check($rows[0]['status']==='superseded','older summary superseded');check($rows[1]['nextAttemptAt']===121000,'rate limit delay obeyed');
$worker->drain('outbox-test',fn($event)=>['status'=>200,'revision'=>1],121000);check($store->get('outbox-test')[1]['status']!=='sent','old ack cannot confirm latest revision');
$worker->drain('outbox-test',fn($event)=>['status'=>200,'revision'=>2],999999);check($store->get('outbox-test')[1]['status']==='sent','matching revision acknowledgment sent');echo "PASS durable outbox supersession, rate limit, revision acknowledgment\n";
