import path from 'node:path';
import {root,nuxtExecutable,run} from './runtime.mjs';
await run(process.execPath,[nuxtExecutable(),'build',path.join(root,'apps/web')],{env:{...process.env,NUXT_TELEMETRY_DISABLED:'1'}});
