<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
if(!Mayuan\GuanlanAdapter::status()['configured']){fwrite(STDERR,"Summary protocol unconfigured; no events dispatched.\n");exit(2);}
$dsn=getenv('MAYUAN_DB_DSN')?:'';if(!$dsn){fwrite(STDERR,"MAYUAN_DB_DSN required.\n");exit(2);}$db=new PDO($dsn,getenv('MAYUAN_DB_USER')?:null,getenv('MAYUAN_DB_PASSWORD')?:null);$store=new Mayuan\Storage($db);$adapter=new Mayuan\GuanlanAdapter();$worker=new Mayuan\Outbox($store);$q=$db->query("SELECT record_key FROM mayuan_records WHERE record_key LIKE 'user:%:outbox'");foreach($q->fetchAll(PDO::FETCH_COLUMN) as $key){$worker->drain($key,function($event)use($adapter){if(!str_starts_with($event['payload']['subject']??'','guanlan:'))return ['status'=>400];return $adapter->sendSummary($event['payload']);},(int)floor(microtime(true)*1000));}echo "Outbox pass completed.\n";
