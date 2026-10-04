<?php
declare(strict_types=1);
namespace Mayuan;
final class Outbox {
 public function __construct(private Storage $store){}
 public function drain(string $key,callable $send,int $now):void{$this->store->transaction(function()use($key,$send,$now){$rows=$this->store->get($key,[]);$latest=0;foreach($rows as $r)$latest=max($latest,$r['revision']);foreach($rows as &$row){if(in_array($row['status'],['sent','superseded','paused','failed'],true))continue;if($row['revision']<$latest){$row['status']='superseded';continue;}if($row['nextAttemptAt']>$now)continue;try{$r=$send($row);}catch(\Throwable){$r=['status'=>503];}$status=$r['status']??503;if($status===200&&($r['revision']??null)===$row['revision']){$row['status']='sent';$row['acknowledgedAt']=$now;}elseif(in_array($status,[401,403],true)){$row['status']='paused';$row['errorCode']='authorization';}elseif($status>=400&&$status<500&&$status!==429){$row['status']='failed';$row['errorCode']='permanent_request_error';}else{$row['retries']++;$row['status']='pending';$row['errorCode']=$status===200?'revision_acknowledgment_mismatch':'http_'.$status;$delay=$status===429?max(1,min(86400,(int)($r['retryAfter']??60))):min(3600,2**min(12,$row['retries']));$row['nextAttemptAt']=$now+$delay*1000;}}unset($row);$this->store->put($key,$rows);});}
}
