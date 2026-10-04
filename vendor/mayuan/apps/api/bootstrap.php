<?php
declare(strict_types=1);
spl_autoload_register(function(string $class){if(str_starts_with($class,'Mayuan\\')){$path=__DIR__.'/src/'.substr($class,7).'.php';if(is_file($path))require $path;}});
