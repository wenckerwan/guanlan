import test from 'node:test';
import assert from 'node:assert/strict';
import {developmentEnvironment} from '../../scripts/runtime.mjs';
test('local start explicitly selects development and loopback API',()=>{
 const env=developmentEnvironment({PATH:'test'});
 assert.equal(env.MAYUAN_MODE,'development');
 assert.equal(env.NUXT_API_BASE,'http://127.0.0.1:8086');
 assert.equal(env.PATH,'test');
});
test('production mode must not silently become simulated login',()=>{
 assert.throws(()=>developmentEnvironment({MAYUAN_MODE:'guanlan'}),/开发启动器/);
 assert.throws(()=>developmentEnvironment({MAYUAN_MODE:'production'}),/开发启动器/);
});
