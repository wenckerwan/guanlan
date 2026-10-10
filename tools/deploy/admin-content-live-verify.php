<?php
declare(strict_types=1);
$pdo=new PDO('mysql:host='.getenv('DB_HOST').';dbname='.getenv('DB_DATABASE'),getenv('DB_USERNAME'),getenv('DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$hashes=[];
$token=function(string $role)use($pdo,&$hashes):string{
    $q=$pdo->prepare("SELECT id FROM users WHERE role=? AND status='active' ORDER BY id LIMIT 1");$q->execute([$role]);$id=$q->fetchColumn();
    if(!$id)throw new RuntimeException('Missing active verification role');
    $value=bin2hex(random_bytes(32));$hash=hash('sha256',$value);$hashes[]=$hash;
    $pdo->prepare("INSERT INTO user_tokens (user_id,token_hash,user_agent,expires_at,created_at,updated_at) VALUES (?,?,'admin-content-release-check',DATE_ADD(NOW(),INTERVAL 2 MINUTE),NOW(),NOW())")->execute([$id,$hash]);
    return $value;
};
$http=function(string $path,int $expected,string $token='',string $method='GET',?array $body=null):array{
    $ctx=stream_context_create(['http'=>['method'=>$method,'timeout'=>15,'ignore_errors'=>true,'header'=>"Connection: close\r\nContent-Type: application/json\r\n".($token!==''?"Authorization: Bearer $token\r\n":''),'content'=>$body===null?'':json_encode($body)]]);
    $raw=file_get_contents('http://127.0.0.1:9501/api/v1'.$path,false,$ctx);
    preg_match('/HTTP\/\S+ (\d+)/',$http_response_header[0]??'',$m);
    if((int)($m[1]??0)!==$expected)throw new RuntimeException("Unexpected HTTP status for $method $path");
    $result=json_decode($raw,true);if(!is_array($result))throw new RuntimeException('Invalid API JSON');
    echo "PASS $method $path $expected\n";return $result;
};
try{
    $admin=$token('admin');$user=$token('user');
    foreach(['/auth/me','/admin/overview?from=2026-10-10&to=2026-10-10','/admin/audit-logs','/admin/users','/admin/hotspots?page=1&perPage=2','/admin/analysis?page=1&perPage=2','/admin/papers','/admin/mocks','/admin/comments'] as $path)$http($path,200,$admin);
    foreach(['hotspot'=>'hotspots','analysis'=>'analysis_articles'] as $kind=>$table){
        $id=(int)$pdo->query("SELECT id FROM $table ORDER BY id LIMIT 1")->fetchColumn();if($id<1)throw new RuntimeException('Missing article');
        $route=$kind==='hotspot'?'hotspots':'analysis';
        $detail=$http("/admin/$route/$id",200,$admin);
        if(($detail['data']['revision']??0)<1)throw new RuntimeException('Missing migrated revision');
        foreach(['/revisions','/export','/source-diff'] as $suffix)$http("/admin/articles/$kind/$id$suffix",200,$admin);
        $http("/admin/articles/$kind/source-diff",200,$admin);
        $http("/admin/articles/$kind/status-batch",422,$admin,'POST',['status'=>'draft','items'=>[]]);
    }
    $preview=$http('/admin/articles/preview',200,$admin,'POST',['format'=>'markdown','body'=>"# 安全预览\n\n<script>alert(1)</script>\n\n[x](javascript:alert(1))"]);
    $html=$preview['data']['html']??'';
    if(!str_contains($html,'toc-0')||str_contains($html,'<script>')||str_contains($html,'href="javascript:'))throw new RuntimeException('Unsafe or missing renderer');
    $http('/admin/overview?from=2026-10-11&to=2026-10-10',422,$admin);
    $http('/admin/articles/preview',403,$user,'POST',['format'=>'markdown','body'=>'denied']);
    $http('/admin/hotspots',403,$user);
    $http('/admin/hotspots',401);
    $http('/admin/articles/preview',401,'','POST',['format'=>'markdown','body'=>'denied']);
    foreach(['2026_10_10_000001_add_article_editor_metadata','2026_10_10_000002_create_article_revisions'] as $migration){
        $q=$pdo->prepare('SELECT COUNT(*) FROM migrations WHERE migration=?');$q->execute([$migration]);if((int)$q->fetchColumn()!==1)throw new RuntimeException('Missing migration');
    }
    echo "LIVE_CONTENT_VERIFICATION_COMPLETE\n";
}finally{
    $q=$pdo->prepare('DELETE FROM user_tokens WHERE token_hash=?');foreach($hashes as $hash)$q->execute([$hash]);
    echo "TEMPORARY_VERIFICATION_SESSIONS_REMOVED\n";
}
