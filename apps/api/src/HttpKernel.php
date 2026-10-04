<?php
declare(strict_types=1);
namespace Mayuan;
final class HttpKernel {
 public static function run():void{
 header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
 try{
 $mode=getenv('MAYUAN_MODE')==='development'?'development':'guanlan';
 $method=$_SERVER['REQUEST_METHOD']??'GET';$path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH);if(!str_starts_with($path,'/api/v2/'))throw new ApiError(404,'not_found','接口不存在');$path=substr($path,7);
 if(!in_array($method,['GET','HEAD'],true)){$origin=$_SERVER['HTTP_ORIGIN']??'';$allowed=array_filter(explode(',',getenv('MAYUAN_ORIGINS')?:''));if(!$origin||!in_array($origin,$allowed,true))throw new ApiError(403,'origin_rejected','请求来源未获授权');if(!str_starts_with(strtolower($_SERVER['CONTENT_TYPE']??''),'application/json'))throw new ApiError(415,'json_required','请求必须为 JSON');}
 $raw=file_get_contents('php://input');if(strlen($raw)>2097152)throw new ApiError(413,'body_too_large','请求过大');$body=[];if($raw!==''){try{$parsed=json_decode($raw,true,64,JSON_THROW_ON_ERROR);}catch(\Throwable){throw new ApiError(400,'invalid_json','JSON 格式无效');}if(!is_array($parsed)||array_is_list($parsed)&&$parsed!==[])throw new ApiError(400,'invalid_json','请求应为 JSON 对象');$body=$parsed;}
 ini_set('session.use_strict_mode','1');session_name('mayuan_session');session_set_cookie_params(['lifetime'=>0,'path'=>'/','httponly'=>true,'secure'=>$mode!=='development','samesite'=>'Lax']);session_start();
 $dsn=getenv('MAYUAN_DB_DSN');if(!$dsn){if($mode!=='development')throw new ApiError(503,'database_unconfigured','生产数据库未配置');$dir=__DIR__.'/../storage';if(!is_dir($dir))mkdir($dir,0700,true);$dsn='sqlite:'.$dir.'/mayuan.sqlite';}
 if($mode!=='development'&&!str_starts_with($dsn,'mysql:'))throw new ApiError(503,'database_configuration','生产环境需要 PDO MySQL 数据库');
 $db=new \PDO($dsn,getenv('MAYUAN_DB_USER')?:null,getenv('MAYUAN_DB_PASSWORD')?:null);$app=new Application($db,$mode);$user=$_SESSION['user']??null;if($mode!=='development'&&$user&&!str_starts_with($user['id'],'guanlan:')){$_SESSION=[];$user=null;}
 if($user&&str_starts_with($user['id'],'guanlan:')){try{$user=(new GuanlanAdapter())->verifyToken($_SESSION['guanlanToken']??'');$_SESSION['user']=$user;}catch(\Throwable $e){$_SESSION=[];throw new ApiError(401,'session_expired','观澜身份验证失败，请重新登录');}}
 (new RequestGuard())->principal($path,$user,$_SERVER['HTTP_X_MAYUAN_USER']??'');if($method==='POST'&&$path==='/guanlan/validate'){$user=(new GuanlanAdapter())->verifyToken((string)($body['token']??''));session_regenerate_id(true);$_SESSION['user']=$user;$_SESSION['guanlanToken']=$body['token'];$data=['user'=>$user];}
 else $data=$app->dispatch($method,$path,$body,$user);
 if($path==='/dev-login'&&$method==='POST'){session_regenerate_id(true);$_SESSION=['user'=>$data['user']];}
 if($path==='/logout'&&$method==='POST'){$_SESSION=[];session_destroy();setcookie(session_name(),'',time()-3600,'/','',$mode!=='development',true);}
 echo json_encode(['data'=>self::objects($data)],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
 }catch(ApiError $e){http_response_code($e->status);echo json_encode(['error'=>['code'=>$e->errorCode,'message'=>$e->getMessage()]],JSON_UNESCAPED_UNICODE);}catch(\Throwable $e){error_log('Mayuan API failure: '.get_class($e));http_response_code(500);echo json_encode(['error'=>['code'=>'internal_error','message'=>'服务暂时不可用']],JSON_UNESCAPED_UNICODE);}
 }
 public static function objects(array $data):array{foreach($data as $k=>&$v){if($k==='nodes'&&is_array($v)&&!array_is_list($v))$v=(object)$v;elseif($k==='nodes'&&$v===[])$v=(object)[];elseif(is_array($v))$v=self::objects($v);}return $data;}
}
