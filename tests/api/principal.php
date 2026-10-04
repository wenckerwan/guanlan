<?php
require __DIR__.'/../../apps/api/bootstrap.php';
if(!class_exists(Mayuan\RequestGuard::class)){fwrite(STDERR,"FAIL principal precondition unavailable\n");exit(1);}
$guard=new Mayuan\RequestGuard();$u=['id'=>'dev:second'];foreach(['dev:learner',''] as $expected){try{$guard->principal('/events',$u,$expected);throw new RuntimeException('stale principal accepted');}catch(Mayuan\ApiError $e){if($e->status!==409||$e->errorCode!=='session_changed')throw $e;}}
$guard->principal('/events',$u,'dev:second');$guard->principal('/session',$u,'');$guard->principal('/content',null,'');echo "PASS captured principal must match shared-cookie session\n";
