<?php
declare(strict_types=1);
require __DIR__.'/../src/Support/KnowledgeSearch.php';
use App\Support\KnowledgeSearch;
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
$mayuan=['modules'=>[['id'=>'m','title'=>'唯物论']], 'nodes'=>[['id'=>'matter','module'=>'m','title'=>'物质','summary'=>'客观实在','detail'=>'客观实在性','method'=>'','trap'=>'','example'=>'']]];
$history=['events'=>[['id'=>'published','publication'=>'original','title'=>'遵义会议','content'=>'会议内容','note'=>''],['id'=>'secret','publication'=>'pending','title'=>'秘密','content'=>'秘密'],['id'=>'archive','isGroup'=>true,'publication'=>'original','title'=>'档案','content'=>'档案']]];
$items=KnowledgeSearch::project($mayuan,$history);
check(count($items)===2,'only published history and concept nodes');
check($items[0]['url']==='/mayuan/?concept=matter','stable concept route');
check($items[1]['url']==='/history/?event=published','stable history route');
$result=KnowledgeSearch::select(array_merge($items,[['type'=>'question','title'=>'其他','snippet'=>'物质','url'=>'/q','meta'=>'']]),'物质','',1);
check($result['items'][0]['title']==='物质','exact title outranks body');
check($result['total']===2 && $result['returned']===1 && $result['truncated']===true,'truthful counts');
check($result['items'][0]['matchLocation']==='title','match metadata');
check(KnowledgeSearch::select($items,'客观实在性')['items'][0]['matchLocation']==='body','body match searchable');
check(KnowledgeSearch::select($items,'遵义会议')['items'][0]['subject']==='史纲','subject metadata');
echo "KnowledgeSearchTest: PASS\n";


// Repository release fixtures are used only as regression input, never a runtime fallback.
$root=dirname(__DIR__,3);
$mayuan=json_decode(file_get_contents($root.'/vendor/mayuan/apps/api/resources/content.json'),true,512,JSON_THROW_ON_ERROR);
$history=json_decode(file_get_contents(__DIR__.'/../storage/history/history.v1.json'),true,512,JSON_THROW_ON_ERROR);
$started=hrtime(true);$catalog=KnowledgeSearch::project($mayuan,$history);
foreach (['矛盾'=>'/mayuan/?concept=contradiction','实践'=>'/mayuan/?concept=practice','遵义会议'=>'/history/?event=p4-r055-e01','虎门销烟'=>'/history/?event=p1-r001-e01','五四运动'=>'/history/?event=p2-r038-e01'] as $keyword=>$url) {
    $found=KnowledgeSearch::select($catalog,$keyword);
    check($found['items'][0]['url']===$url,'release fixture rank: '.$keyword);
}
$aliases=KnowledgeSearch::select($catalog,'实践是检验认识真理性的唯一标准');
check($aliases['items'][0]['matchLocation']==='alias','curated alias matches the published definition');
$many=array_fill(0,31,['type'=>'question','title'=>'重复题目','snippet'=>'关键词','url'=>'/papers/test','meta'=>'马原']);
$bounded=KnowledgeSearch::select($many,'关键词','question',5);
check($bounded['total']===31 && $bounded['groups']['question']===31 && $bounded['returned']===5,'counts do not cap at old per-type limit');
printf("Release fixture: %d projected records, 6 queries in %.2f ms (pure in-memory, excludes DB)\n",count($catalog),(hrtime(true)-$started)/1e6);
