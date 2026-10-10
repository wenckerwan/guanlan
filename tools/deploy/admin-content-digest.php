<?php
declare(strict_types=1);
$pdo = new PDO('mysql:host='.getenv('DB_HOST').';dbname='.getenv('DB_DATABASE'),getenv('DB_USERNAME'),getenv('DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$out=[];
foreach(['analysis_articles','hotspots','papers','predictions','questions','content_maintenance'] as $table){
    $order=$table==='content_maintenance'?'table_name':'id';
    $rows=$pdo->query("SELECT * FROM $table ORDER BY $order")->fetchAll(PDO::FETCH_ASSOC);
    foreach($rows as &$row) foreach(['markdown','revision','content_source'] as $newColumn) unset($row[$newColumn]);
    unset($row);
    $out[$table]=['count'=>count($rows),'digest'=>hash('sha256',json_encode($rows,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))];
}
echo json_encode($out,JSON_PRETTY_PRINT).PHP_EOL;
