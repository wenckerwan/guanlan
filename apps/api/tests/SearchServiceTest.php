<?php
declare(strict_types=1);
namespace App\Support {
 class Auth { public static bool $loggedIn=false; public static function user(){return self::$loggedIn?(object)['id'=>1]:null;} }
 class MistakeAccess { public static function allowedCodes(){return ['A','000002'];} public static function canViewCode($code){return in_array($code,['A','000002'],true);} }
 class ContentStatus { const HIDDEN='hidden'; }
}
namespace App\Model {
 class Query {
  public array $clauses=[];
  public function __construct(public array $rows=[]){}
  private function add($key,$op=null,$value=null,$or=false){
   if(is_callable($key)){$nested=new self();$key($nested);$predicate=fn($row)=>$nested->matches($row);}
   else {$predicate=static function($row)use($key,$op,$value){$actual=$row->$key??'';if($op==='<>')return $actual!==$value;if($op==='in')return in_array($actual,$value,true);$needle=str_replace(['\\%','\\_'],['%','_'],trim((string)$value,'%'));return str_contains((string)$actual,$needle);};}
   $this->clauses[]=[$or,$predicate];return $this;
  }
  public function where($key,$op=null,$value=null){return $this->add($key,$op,$value);}
  public function orWhere($key,$op=null,$value=null){return $this->add($key,$op,$value,true);}
  public function whereIn($key,$values){return $this->add($key,'in',$values);}
  public function whereHas($key,$callback){$nested=new self();$callback($nested);$this->clauses[]=[false,fn($row)=>$nested->matches($row->$key)];return $this;}
  public function when($condition,$callback){if($condition)$callback($this);return $this;}
  public function with($key){return $this;}
  public function orderBy(...$args){return $this;}
  public function matches($row){$result=null;foreach($this->clauses as [$or,$predicate]){$hit=$predicate($row);$result=$result===null?$hit:($or?($result||$hit):($result&&$hit));}return $result??true;}
  public function get(){return array_values(array_filter($this->rows,fn($row)=>$this->matches($row)));}
 }
 class Base {public static array $fixtures=[];public static function query(){return new Query(self::$fixtures[static::class]??[]);} }
 class Question extends Base{} class Paper extends Base{} class AnalysisArticle extends Base{} class Hotspot extends Base{} class Prediction extends Base{} class Mock extends Base{} class MistakeItem extends Base{}
}
namespace App\Service {
 class HistoryService {public int $calls=0;public function latestPublished(){++$this->calls;return ['events'=>[['id'=>'h1','publication'=>'verified','title'=>'公开会议','content'=>'线索'],['id'=>'h2','publication'=>'pending','title'=>'秘密','content'=>'秘密']]];}}
 class MayuanSearchSource {public int $calls=0;public bool $fail=false;public function published(){++$this->calls;if($this->fail)throw new \RuntimeException('database absent');return ['nodes'=>[['id'=>'m1','title'=>'公开概念','summary'=>'定义','detail'=>'内容']]];}}
}
namespace {
 require __DIR__.'/../src/Support/KnowledgeSearch.php';require __DIR__.'/../src/Service/SearchService.php';
 function check($ok,$message){if(!$ok)throw new \RuntimeException($message);}
 $history=new App\Service\HistoryService();$mayuan=new App\Service\MayuanSearchSource();$service=new App\Service\SearchService($history,$mayuan);
 App\Model\Base::$fixtures[App\Model\Prediction::class]=[(object)['title'=>'秘密','summary'=>'秘密','html'=>'秘密','status'=>'hidden'],(object)['title'=>'列表可见','summary'=>'摘要','html'=>'正文暗号','status'=>'published','slug'=>'visible','layer'=>'预测']];
 App\Model\Base::$fixtures[App\Model\Question::class]=[(object)['stem'=>'秘密','year'=>2026,'no'=>1,'pid'=>'2026','module_name'=>'马原','type_cn'=>'单选','material'=>'','analysis'=>'','kaodian'=>'']];
 $guest=$service->search('秘密');check($guest['total']===0,'guest cannot search hidden predictions or authenticated question contents');
 check($mayuan->calls===0 && $history->calls===0,'guest never loads protected sources');
 check($service->search('正文暗号')['total']===0,'guest cannot infer locked article body through hits/counts');
 App\Support\Auth::$loggedIn=true;
 $result=$service->search('公开');check($result['total']===2,'authenticated published knowledge searchable');
 check(($service->search('秘密')['groups']['history']??0) === 0,'pending history excluded');
 check($service->search('正文暗号')['items'][0]['matchLocation']==='body','authenticated body match metadata');
 $mayuan->fail=true;$degraded=$service->search('公开');check($degraded['sources']['concept']==='unavailable' && $degraded['total']===1,'missing database explicitly degraded, no seed fallback');
 App\Model\Base::$fixtures[App\Model\MistakeItem::class]=[(object)['stem'=>'私密错题','student'=>(object)['code'=>'B'],'kaodian'=>'','chapter'=>'']];
 check($service->search('私密错题')['total']===0,'private mistakes excluded before counts');
 echo "SearchServiceTest: PASS\n";
}
