<?php
use Hyperf\Server\Server;
use Hyperf\Server\Event;
return ['mode'=>SWOOLE_PROCESS,'servers'=>[['name'=>'http','type'=>Server::SERVER_HTTP,'host'=>'0.0.0.0','port'=>9501,'sock_type'=>SWOOLE_SOCK_TCP,'callbacks'=>[Event::ON_REQUEST=>[Hyperf\HttpServer\Server::class,'onRequest']]]],'settings'=>['enable_coroutine'=>true,'worker_num'=>2,'max_request'=>10000,'socket_buffer_size'=>2097152]];
