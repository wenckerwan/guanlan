<?php
require __DIR__.'/../../apps/api/bootstrap.php';$c=json_decode(file_get_contents(__DIR__.'/../../apps/api/resources/content.json'),true);$c['contentVersion']=[];try{Mayuan\Content::validate($c);throw new RuntimeException('invalid version accepted');}catch(Mayuan\ApiError $e){echo "PASS invalid version rejected\n";}
