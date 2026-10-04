<?php
require __DIR__.'/../../apps/api/bootstrap.php';$a=new Mayuan\Application(new PDO('sqlite::memory:'),'guanlan');try{$a->dispatch('GET','/state',[],['id'=>'dev:admin','role'=>'admin']);throw new RuntimeException('production accepted old development identity');}catch(Mayuan\ApiError $e){if($e->status!==401)throw $e;echo "PASS old development session rejected in production\n";}
