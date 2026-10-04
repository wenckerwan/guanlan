<?php
require __DIR__.'/../../apps/api/bootstrap.php';
$a=new Mayuan\Application(new PDO('sqlite::memory:'),'development');$u=['id'=>'dev:learner','role'=>'learner'];$a->dispatch('POST','/events',['id'=>'recall-good','type'=>'recall','payload'=>['nodeId'=>'marxism','rating'=>'good','mode'=>'clue']],$u);$a->dispatch('POST','/import',['progress'=>$a->dispatch('GET','/export',[],$u),'confirm'=>true],$u);echo "PASS good recall V2 backup roundtrip\n";
