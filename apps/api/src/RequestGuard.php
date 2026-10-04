<?php
declare(strict_types=1);
namespace Mayuan;
final class RequestGuard {
 public function principal(string $path,?array $user,string $expected):void{
 if(in_array($path,['/session','/content','/dev-login','/logout','/guanlan/validate'],true))return;
 if(!$user)throw new ApiError(401,'unauthenticated','请先登录');
 if($expected===''||!hash_equals((string)$user['id'],$expected))throw new ApiError(409,'session_changed','当前账号已切换，请刷新身份后再继续');
 }
}
