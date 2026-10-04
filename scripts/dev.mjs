import {spawn} from 'node:child_process';
import path from 'node:path';
import {root,developmentEnvironment,phpExecutable,nuxtExecutable} from './runtime.mjs';
const env=developmentEnvironment();
const children=[];
let stopping=false;
function stop(code=0){if(stopping)return;stopping=true;for(const c of children)c.kill();setTimeout(()=>process.exit(code),200);}
try{
  children.push(spawn(phpExecutable(),['-S','127.0.0.1:8086','-t',path.join(root,'apps/api/public'),path.join(root,'apps/api/public/router.php')],{cwd:root,env,stdio:'inherit'}));
  children.push(spawn(process.execPath,[nuxtExecutable(),'dev',path.join(root,'apps/web'),'--port','4179','--host','127.0.0.1'],{cwd:root,env:{...env,NUXT_TELEMETRY_DISABLED:'1'},stdio:'inherit'}));
  console.log('\n马原 V2 开发模式：http://127.0.0.1:4179\n本地模拟账号；真实观澜登录尚未启用。Ctrl+C 停止。\n');
  for(const c of children){c.on('error',e=>{console.error(e.message);stop(1);});c.on('exit',code=>{if(!stopping)stop(code??1);});}
  process.on('SIGINT',()=>stop());process.on('SIGTERM',()=>stop());
}catch(e){console.error(e.message);stop(1);}
