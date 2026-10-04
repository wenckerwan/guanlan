<?php
use Hyperf\HttpServer\Router\Router;
Router::addRoute(['GET','POST','PUT'], '/api/v2/{path:.*}', [Mayuan\HyperfController::class,'handle']);
