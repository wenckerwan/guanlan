import test from 'node:test';
import assert from 'node:assert/strict';
import { createLocalStateStore, validateLocalStudyState } from '../lib/local-state.mjs';

const key = 'mayuan-v2-local-state';
const blank = () => ({ nodes: {}, attempts: [], recalls: [], historicalAnswers: [], favorites: [], notes: [], resume: { view: 'map', nodeId: null } });
function setup(raw = null) {
  const rows = new Map(raw == null ? [] : [[key, raw]]); let blocked = false, status; const timers = new Map(); let next = 0;
  const storage = { getItem(k) { if (blocked) throw Object.assign(Error('denied'), { name: 'SecurityError' }); return rows.get(k) ?? null }, setItem(k, v) { if (blocked) throw Object.assign(Error('quota'), { name: 'QuotaExceededError' }); rows.set(k, v) } };
  const store = createLocalStateStore({ storage: () => storage, blank, normalize: x => x, onStatus: value => { status = value }, setTimer: fn => { timers.set(++next, fn); return next }, clearTimer: id => timers.delete(id) });
  return { store, rows, timers, get status() { return status }, set blocked(v) { blocked = v } };
}
test('quota failure keeps memory exportable and retry saves the latest state', () => {
  const h = setup(); const s = h.store.read(); s.nodes.a = { visited: true }; h.blocked = true;
  assert.doesNotThrow(() => h.store.save(s, true)); assert.equal(h.status.mode, 'error');
  assert.equal(JSON.parse(h.store.exportState(s)).state.nodes.a.visited, true);
  h.blocked = false; assert.equal(h.store.retry(s), true); assert.equal(h.status.mode, 'saved');
  assert.equal(JSON.parse(h.rows.get(key)).nodes.a.visited, true);
});
for (const raw of ['{broken', '{"nodes":{},"recalls":{}}', '{"nodes":[],"attempts":[]}', '{"nodes":{"a":null}}', '{"nodes":{},"__proto__":{}}']) test(`damaged state is protected: ${raw}`, () => {
  const h = setup(raw); const s = h.store.read(); assert.equal(h.status.mode, 'corrupt');
  h.store.save(s, true); assert.equal(h.rows.get(key), raw);
  h.blocked = true; assert.equal(h.store.retry(s), false); assert.equal(h.rows.get(key), raw);
  h.blocked = false; assert.equal(h.store.retry(s), true);
  assert.ok([...h.rows].some(([k, v]) => k.startsWith(`${key}:recovery:`) && v === raw));
  assert.deepEqual(JSON.parse(h.rows.get(key)).nodes, {});
});
test('a failed initial read cannot overwrite unknown existing data', () => {
  const raw = JSON.stringify({ ...blank(), nodes: { old: { visited: true } } });
  const h = setup(raw); h.blocked = true; const s = h.store.read(); h.blocked = false;
  h.store.save(s, true); assert.equal(h.rows.get(key), raw);
  assert.equal(h.store.retry(s), true);
  assert.ok([...h.rows].some(([k, v]) => k.startsWith(`${key}:recovery:`) && v === raw));
});
test('page exit flushes the latest debounced state and clears timers', () => {
  const h = setup(); const s = h.store.read(); h.store.save(s); s.nodes.a = { visited: true }; h.store.save(s);
  assert.equal(h.status.mode, 'pending'); h.store.flush();
  assert.equal(JSON.parse(h.rows.get(key)).nodes.a.visited, true);
  assert.equal(h.timers.size, 0); h.store.dispose(); assert.equal(h.timers.size, 0);
});
test('valid existing local state remains readable', () => {
  const s = { ...blank(), nodes: { a: { visited: true, mastery: 'mastered' } } };
  const h = setup(JSON.stringify(s)); assert.deepEqual(h.store.read(), s); assert.equal(h.status.mode, 'saved');
});
test('invalid imported collections fail validation before merging', () => {
  assert.throws(() => validateLocalStudyState({ nodes: {}, attempts: {} }));
  assert.doesNotThrow(() => validateLocalStudyState(blank()));
});
test('an idle tab cannot overwrite a newer snapshot when hidden or closed', () => {
  const h = setup(); h.store.read();
  const newer = JSON.stringify({ ...blank(), attempts: [{ id: 'saved-in-another-tab' }] });
  h.rows.set(key, newer); h.store.flush(); h.store.dispose();
  assert.equal(h.rows.get(key), newer);
});
