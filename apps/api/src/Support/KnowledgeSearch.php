<?php
declare(strict_types=1);
namespace App\Support;
/** Pure projection and ordering. No learning state, exercise answers, or editorial fields. */
final class KnowledgeSearch
{
    // Curated spelling aliases only. Canonical titles remain the content authority.
    private const ALIASES = ['实践检验标准' => ['实践是检验认识真理性的唯一标准']];
    public static function project(array $mayuan, array $history): array
    {
        $out=[];
        foreach ($mayuan['nodes'] ?? [] as $node) {
            $out[]=['type'=>'concept','title'=>$node['title'],'snippet'=>$node['summary'] ?? '',
                'body'=>implode(' ',array_map(fn($key)=>(string)($node[$key]??''),['detail','method','trap','example'])),
                'url'=>'/mayuan/?concept='.rawurlencode($node['id']),'meta'=>'马原','subject'=>'马原'];
        }
        foreach ($history['events'] ?? [] as $event) {
            if (($event['isGroup']??false) || !in_array($event['publication']??null,['original','verified'],true)) continue;
            $out[]=['type'=>'history','title'=>$event['title'],'snippet'=>$event['content']??'',
                'body'=>$event['note']??'','url'=>'/history/?event='.rawurlencode($event['id']), 'meta'=>'史纲','subject'=>'史纲'];
        }
        return $out;
    }
    public static function select(array $items,string $keyword,string $type='',int $limit=40): array
    {
        $keyword=mb_strtolower(trim($keyword));$matches=[];$groups=[];
        if ($keyword!=='') foreach ($items as $item) {
            $title=mb_strtolower($item['title']);$summary=strip_tags($item['snippet']);$body=strip_tags($item['body']??'');
            $aliases=implode(' ',self::ALIASES[$item['title']]??[]);
            $location=$title===$keyword?'title':(str_contains($title,$keyword)?'title':(str_contains(mb_strtolower($aliases),$keyword)?'alias':(str_contains(mb_strtolower($summary),$keyword)?'summary':(str_contains(mb_strtolower($body),$keyword)?'body':''))));
            // Existing SQL candidates may match additional fields (year/module/kaodian).
            if ($location==='' && !($item['_candidate']??false)) continue;
            $location=$location?:'body';
            $item['matchLocation']=$location;$item['subject']=$item['subject']??($item['meta']?:'综合');
            $item['_rank']=$title===$keyword?0:(in_array($location,['title','alias'],true)?1:2);
            $text=$location==='body'?$body:$summary;
            $pos=mb_stripos($text,$keyword);$item['snippet']=mb_substr(trim(preg_replace('/\s+/u',' ',$text)??''),max(0,($pos===false?0:$pos)-30),160);
            unset($item['body'],$item['_candidate']);$matches[]=$item;$groups[$item['type']]=($groups[$item['type']]??0)+1;
        }
        usort($matches,fn($a,$b)=>[$a['_rank'],$a['title'],$a['url']]<=>[$b['_rank'],$b['title'],$b['url']]);
        if($type!=='')$matches=array_values(array_filter($matches,fn($item)=>$item['type']===$type));
        $total=count($matches);$matches=array_slice($matches,0,max(1,$limit));foreach($matches as &$item)unset($item['_rank']);unset($item);
        return ['items'=>$matches,'total'=>$total,'groups'=>$groups,'returned'=>count($matches),'truncated'=>$total>count($matches)];
    }
}
