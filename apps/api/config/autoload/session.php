<?php
return ['handler'=>Hyperf\Session\Handler\FileHandler::class,'options'=>['path'=>BASE_PATH.'/runtime/sessions','session_name'=>'mayuan_session','expire_on_close'=>true,'cookie_lifetime'=>7200,'gc_maxlifetime'=>7200,'domain'=>null,'cookie_same_site'=>'lax']];
