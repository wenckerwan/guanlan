import {readdirSync,existsSync} from 'node:fs';
import path from 'node:path';
import {root,phpExecutable,run} from './runtime.mjs';
function find(dir,extension){return readdirSync(dir,{withFileTypes:true}).flatMap(e=>e.isDirectory()?find(path.join(dir,e.name),extension):e.name.endsWith(extension)?[path.join(dir,e.name)]:[]);}
const frontendTests=find(path.join(root,'apps/web/tests'),'.test.mjs');
if(!frontendTests.length)throw new Error('前端测试未就绪');
const nodeTests=[...find(path.join(root,'tests/runtime'),'.test.mjs'),...frontendTests];
await run(process.execPath,['--test',...nodeTests]);
const apiRunner=path.join(root,'tests/api/run.php');
if(!existsSync(apiRunner))throw new Error('PHP测试入口未就绪');
await run(phpExecutable(),[apiRunner]);
const httpTests=path.join(root,'tests/http/platform.test.mjs');
if(existsSync(httpTests))await run(process.execPath,['--test',httpTests]);
