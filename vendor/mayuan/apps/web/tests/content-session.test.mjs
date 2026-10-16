import test from 'node:test';
import assert from 'node:assert/strict';
import { loadContent } from '../lib/content-session.mjs';

test('expired old session is cleared before validating current login', async () => {
  const calls = [];
  const api = async (path, body) => {
    calls.push([path, body]);
    if (calls.length === 1) throw Object.assign(Error('old session expired'), {status: 401});
    if (path === '/session') return {mode: 'guanlan'};
    if (path === '/guanlan/validate') { assert.deepEqual(body, {token: 'test-token'}); return {}; }
    return {nodes: []};
  };
  assert.deepEqual(await loadContent(api, 'guanlan.token=test-token'), {nodes: []});
  assert.deepEqual(calls.map(c => c[0]), ['/session', '/session', '/guanlan/validate', '/content']);
});

test('anonymous production visitor cannot request content', async () => {
  const calls = [];
  await assert.rejects(loadContent(async path => { calls.push(path); return {mode: 'guanlan'}; }, ''), /请先登录/);
  assert.deepEqual(calls, ['/session']);
});

test('development content stays available without a production token', async () => {
  assert.deepEqual(await loadContent(async path => path === '/session' ? {mode:'development'} : {nodes:[]}, ''), {nodes:[]});
});

test('server failure is not retried as session expiry', async () => {
  let calls = 0;
  await assert.rejects(loadContent(async () => { calls++; throw Object.assign(Error('offline'), {status:503}); }, ''), /offline/);
  assert.equal(calls, 1);
});
