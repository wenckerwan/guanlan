<?php
// Empty string creates a host-only cookie; null falls back to the internal URI host.
return ['handler'=>Hyperf\Session\Handler\FileHandler::class,'options'=>['path'=>BASE_PATH.'/runtime/sessions','session_name'=>'mayuan_session','expire_on_close'=>true,'cookie_lifetime'=>7200,'gc_maxlifetime'=>7200,'domain'=>'','cookie_same_site'=>'lax']];
