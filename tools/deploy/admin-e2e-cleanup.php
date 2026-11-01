<?php
declare(strict_types=1);
$pdo=new PDO('mysql:host='.getenv('DB_HOST').';dbname='.getenv('DB_DATABASE'),getenv('DB_USERNAME'),getenv('DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$id=49;$slug='admin-3319d1c7c567';$title='后台验收测试（20261010，完成后删除）';
$pdo->beginTransaction();
try {
    $q=$pdo->prepare('SELECT * FROM hotspots WHERE id=? AND slug=? AND title=? FOR UPDATE');$q->execute([$id,$slug,$title]);$row=$q->fetch(PDO::FETCH_ASSOC);
    if(!$row || (int)$row['revision']!==4 || $row['status']!=='published' || !str_contains($row['markdown'],'验收第一版') || str_contains($row['markdown'],'旧会话'))throw new RuntimeException('Test article state differs; refusing cleanup');
    $q=$pdo->prepare("SELECT revision,snapshot FROM article_revisions WHERE type='hotspot' AND article_id=? ORDER BY revision");$q->execute([$id]);$history=$q->fetchAll(PDO::FETCH_ASSOC);
    if(array_map(fn($r)=>(int)$r['revision'],$history)!==[1,2,3,4])throw new RuntimeException('Unexpected revision history');
    $snapshots=array_map(fn($r)=>json_decode($r['snapshot'],true,512,JSON_THROW_ON_ERROR),$history);
    if($snapshots[0]['body']!==$snapshots[2]['body'] || $snapshots[0]['body']===$snapshots[1]['body'] || $snapshots[3]['body']!==$row['markdown'])throw new RuntimeException('Restore/history mismatch');
    foreach(['favorites','notes'] as $table){
        $q=$pdo->prepare("SELECT COUNT(*) FROM $table WHERE target_type='hotspot' AND target_id IN (?,?)");$q->execute([$slug,(string)$id]);if((int)$q->fetchColumn()>0)throw new RuntimeException('Article has external learning references; refusing deletion');
    }
    $q=$pdo->prepare("SELECT COUNT(*) FROM study_progress WHERE scope='hotspot' AND ref IN (?,?)");$q->execute([$slug,(string)$id]);if((int)$q->fetchColumn()>0)throw new RuntimeException('Article has study progress; refusing deletion');
    $q=$pdo->prepare("SELECT COUNT(*) FROM comments WHERE article_type='hotspot' AND article_slug=?");$q->execute([$slug]);if((int)$q->fetchColumn()>0)throw new RuntimeException('Article has comments; refusing deletion');
    // Only the explicitly named test article/history are removed. Keep audit attribution and all real learning records.
    $q=$pdo->prepare("DELETE FROM article_revisions WHERE type='hotspot' AND article_id=?");$q->execute([$id]);
    $q=$pdo->prepare('DELETE FROM hotspots WHERE id=? AND slug=? AND title=?');$q->execute([$id,$slug,$title]);if($q->rowCount()!==1)throw new RuntimeException('Cleanup identity mismatch');
    $pdo->commit();echo "RESTORE_AND_IMMUTABLE_HISTORY_VERIFIED\nTEST_ARTICLE_49_AND_FOUR_TEST_REVISIONS_REMOVED\n";
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
