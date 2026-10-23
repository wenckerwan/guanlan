<?php
declare(strict_types=1);
namespace Hyperf\DbConnection {
 class Db {public static array $calls=[]; public static mixed $record=null;public static function table($table){self::$calls[]=$table;return new class {public function where($key,$value){if($key!=='record_key'||$value!=='content')throw new \RuntimeException('wrong source');return $this;}public function first($columns){return Db::$record;}};}}
}
namespace {
 require __DIR__.'/../src/Service/MayuanSearchSource.php';
 function check($ok,$message){if(!$ok)throw new \RuntimeException($message);}
 $source=new App\Service\MayuanSearchSource();
 Hyperf\DbConnection\Db::$record=(object)['body'=>json_encode(['draft'=>['nodes'=>[['id'=>'secret']]],'published'=>['nodes'=>[['id'=>'public']]]])];
 putenv('MAYUAN_SEARCH_DATABASE=mayuan');check($source->published()['nodes'][0]['id']==='public','only published current state, never draft');
 Hyperf\DbConnection\Db::$record=null;check($source->published()===null,'absent database record never falls back to seed');
 putenv('MAYUAN_SEARCH_DATABASE=bad.name');$rejected=false;try{$source->published();}catch(RuntimeException){$rejected=true;}check($rejected,'reject unsafe schema identifiers');putenv('MAYUAN_SEARCH_DATABASE');
 echo "MayuanSearchSourceTest: PASS\n";
}
