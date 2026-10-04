<?php
use Hyperf\Context\ApplicationContext;
$container=new Hyperf\Di\Container((new Hyperf\Di\Definition\DefinitionSourceFactory())());
return ApplicationContext::setContainer($container);
