<?php
declare(strict_types=1);
$pdo=new PDO('mysql:host='.getenv('DB_HOST').';dbname='.getenv('DB_DATABASE'),getenv('DB_USERNAME'),getenv('DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
if((int)$pdo->query("SELECT COUNT(*) FROM hotspots WHERE id=49 OR slug='admin-3319d1c7c567'")->fetchColumn()!==0)throw new RuntimeException('Test article remains');
if((int)$pdo->query("SELECT COUNT(*) FROM article_revisions WHERE type='hotspot' AND article_id=49")->fetchColumn()!==0)throw new RuntimeException('Test history remains');
echo json_encode($pdo->query('SELECT table_name,maintained,updated_at FROM content_maintenance ORDER BY table_name')->fetchAll(PDO::FETCH_ASSOC));
