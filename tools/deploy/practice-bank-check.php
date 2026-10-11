<?php
declare(strict_types=1);
$pdo=new PDO('mysql:host='.getenv('DB_HOST').';dbname='.getenv('DB_DATABASE'),getenv('DB_USERNAME'),getenv('DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$package=json_decode((string)file_get_contents('/opt/guanlan/resources/private/current-affairs-2026.json'),true,512,JSON_THROW_ON_ERROR);
$bank=$pdo->query("SELECT * FROM practice_banks WHERE slug='current-affairs-2026'")->fetch(PDO::FETCH_ASSOC);
if(!$bank || $bank['content_version']!==$package['contentVersion'] || $bank['source_sha256']!==$package['sourceSha256']) throw new RuntimeException('Bank version mismatch');
$q=$pdo->prepare('SELECT * FROM practice_questions WHERE bank_id=? AND is_active=1 ORDER BY sort_order'); $q->execute([$bank['id']]); $rows=$q->fetchAll(PDO::FETCH_ASSOC);
if(count($rows)!==101) throw new RuntimeException('Active question count mismatch');
$single=0; $multi=0; $options=0;
foreach($rows as $index=>$row){
    $source=$package['questions'][$index];
    foreach(['question_ref'=>'questionRef','question_version'=>'questionVersion','type'=>'type','module'=>'module','stem'=>'stem','answer'=>'answer','analysis'=>'analysis','event'=>'event','knowledge'=>'knowledge'] as $stored=>$input){
        if($row[$stored]!==$source[$input])throw new RuntimeException('Question field mismatch: '.$source['questionRef'].' '.$stored);
    }
    foreach(['options','sources'] as $field){if(json_decode($row[$field],true)!==$source[$field])throw new RuntimeException('Question JSON mismatch: '.$source['questionRef']);}
    $options+=count(json_decode($row['options'],true));
    $single+=($row['type']==='single'?1:0); $multi+=($row['type']==='multi'?1:0);
}
if($single!==52||$multi!==49||$options!==404)throw new RuntimeException('Types/options mismatch');
echo "PRACTICE_BANK_VERIFIED 101 questions / 404 options / 52 single / 49 multi; all source fields identical; status={$bank['status']}\n";
