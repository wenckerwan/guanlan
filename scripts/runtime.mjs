import {existsSync, readFileSync} from 'node:fs';
import {fileURLToPath} from 'node:url';
import path from 'node:path';
import {spawn, spawnSync} from 'node:child_process';

export const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
export function developmentEnvironment(env=process.env){
  if(env.MAYUAN_MODE && env.MAYUAN_MODE!=='development') throw new Error('开发启动器不允许覆盖正式身份模式，请使用部署配置启动');
  return {...env,MAYUAN_MODE:'development',NUXT_API_BASE:'http://127.0.0.1:8086',NUXT_PUBLIC_API_BASE:'/api/v2',MAYUAN_ORIGINS:'http://127.0.0.1:4179,http://localhost:4179',MAYUAN_COOKIE_SECURE:'0'};
}
export function phpExecutable(){
  const portable=path.resolve(root,'../.tools/php/php.exe');
  if(process.env.PHP_BINARY) return process.env.PHP_BINARY;
  if(existsSync(portable)) return portable;
  const check=spawnSync('php',['-v'],{stdio:'ignore'});
  if(check.status===0) return 'php';
  throw new Error('未找到 PHP，请安装 PHP 8.3 并启用 pdo_sqlite，或设置 PHP_BINARY');
}
export function nuxtExecutable(){
  const dir=path.join(root,'apps/web/node_modules/nuxt');
  const packageFile=path.join(dir,'package.json');
  if(!existsSync(packageFile)) throw new Error('请先在 v2/apps/web 执行 npm install');
  const pkg=JSON.parse(readFileSync(packageFile,'utf8'));
  const bin=typeof pkg.bin==='string'?pkg.bin:pkg.bin.nuxt;
  return path.resolve(dir,bin);
}
export function run(command,args,options={}){
  return new Promise((resolve,reject)=>{
    const child=spawn(command,args,{cwd:root,stdio:'inherit',...options});
    child.on('error',reject);
    child.on('exit',(code,signal)=>code===0?resolve():reject(new Error(`${command} 退出: ${code??signal}`)));
  });
}
