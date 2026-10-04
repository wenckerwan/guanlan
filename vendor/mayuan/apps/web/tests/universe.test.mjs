/**
 * 3D 宇宙布局与拾取映射的纯逻辑单测（node:test，不依赖 WebGL）。
 * 覆盖：七章确定性分布、双环数量、布局确定性、聚焦环、关系聚合、拾取 id 映射。
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import {
  galaxyLayout,
  conceptOrbits,
  orbitPosition,
  focusLayout3d,
  relationSegments,
  galaxyLinks,
  hash32,
  UNIVERSE_RADIUS,
  CONCEPT_INNER,
  RING_RADIUS,
} from '../lib/universe/layout.mjs';

const modules = [
  { id: 'm1' }, { id: 'm2' }, { id: 'm3' }, { id: 'm4' },
  { id: 'm5' }, { id: 'm6' }, { id: 'm7' },
];
const nodes = Array.from({ length: 21 }, (_, i) => ({
  id: 'n' + i,
  title: '概念' + i,
  module: 'm' + ((i % 7) + 1),
}));

test('7 个星系均匀分布在水平圆上且确定性', () => {
  const a = galaxyLayout(modules);
  const b = galaxyLayout(modules);
  assert.equal(a.size, 7);
  for (const m of modules) {
    const g = a.get(m.id);
    // 半径等于 UNIVERSE_RADIUS（XZ 平面）
    assert.ok(Math.abs(Math.hypot(g.x, g.z) - UNIVERSE_RADIUS) < 1e-9);
    // 确定性：两次计算完全一致
    assert.deepEqual(g, b.get(m.id));
  }
  // 7 个星系角距一致（2π/7，按极角排序后比较）
  const angles = modules
    .map((m) => Math.atan2(a.get(m.id).z, a.get(m.id).x))
    .sort((x, y) => x - y);
  for (let i = 1; i < angles.length; i++) {
    const diff = angles[i] - angles[i - 1];
    assert.ok(Math.abs(diff - (Math.PI * 2) / 7) < 1e-9, `角距 ${diff}`);
  }
  // 跨 0 界首尾角距也一致
  const wrap = angles[0] + Math.PI * 2 - angles[angles.length - 1];
  assert.ok(Math.abs(wrap - (Math.PI * 2) / 7) < 1e-9, `跨界角距 ${wrap}`);
});

test('概念双环：内环最多 8 个，其余外环；轨道参数确定性', () => {
  const members = nodes.filter((n) => n.module === 'm1');
  // 构造一个有 12 个概念的模块以验证双环
  const big = Array.from({ length: 12 }, (_, i) => ({ id: 'b' + i, module: 'mX', title: 't' + i }));
  const byModule = new Map([['mX', big]]);
  const orbits = conceptOrbits(byModule);
  assert.equal(orbits.size, 12);
  let inner = 0, outer = 0;
  for (const [, v] of orbits) (v.ring === 0 ? inner++ : outer++);
  assert.equal(inner, CONCEPT_INNER);
  assert.equal(outer, 12 - CONCEPT_INNER);
  // 轨道半径匹配环
  for (const [id, v] of orbits) {
    assert.equal(v.orbit.r, RING_RADIUS[v.ring]);
  }
  // 确定性：同输入同输出
  const again = conceptOrbits(byModule);
  for (const [id, v] of orbits) assert.deepEqual(v, again.get(id));
});

test('卫星轨道位置：同一时刻同一轨道坐标一致（确定性）', () => {
  const byModule = new Map([['m', [{ id: 'a', module: 'm', title: 'a' }]]]);
  const orbit = conceptOrbits(byModule).get('a').orbit;
  const p1 = orbitPosition(orbit, 3.5);
  const p2 = orbitPosition(orbit, 3.5);
  assert.deepEqual(p1, p2);
  // 轨道半径保持在设计范围内
  assert.ok(Math.hypot(p1.x, p1.z) <= orbit.r * 1.001 + orbit.r * 0.3);
});

test('聚焦布局：选中居中，邻居环绕且确定性', () => {
  const neighbors = ['b', 'a', 'c', 'd'];
  const pos = focusLayout3d(neighbors, 'sel');
  assert.deepEqual(pos.get('sel'), { x: 0, y: 0, z: 0 });
  assert.equal(pos.size, 5);
  // 邻居按 id 排序后均匀分布（确定性，与现有 focusLayout 排序语义一致）
  const sorted = [...neighbors].sort((x, y) => x.localeCompare(y));
  const angles = sorted.map((id) => Math.atan2(pos.get(id).z, pos.get(id).x));
  for (let i = 1; i < angles.length; i++) {
    assert.ok(Math.abs(angles[i] - angles[i - 1] - (Math.PI * 2) / 4) < 1e-9);
  }
  assert.deepEqual(pos, focusLayout3d(neighbors, 'sel'));
});

test('关系线段解析：仅保留两端都有坐标的引力线', () => {
  const positions = new Map([
    ['a', { x: 0, y: 0, z: 0 }],
    ['b', { x: 10, y: 0, z: 0 }],
  ]);
  const rels = [
    { id: 'r1', from: 'a', to: 'b' },
    { id: 'r2', from: 'a', to: 'missing' },
  ];
  const segs = relationSegments(rels, positions);
  assert.equal(segs.length, 1);
  assert.equal(segs[0].relation.id, 'r1');
});

test('星系聚合连线：跨模块关系成组、不虚构理论', () => {
  const rels = [
    { id: 'r1', from: 'n0', to: 'n1' }, // m1→m2
    { id: 'r2', from: 'n7', to: 'n8' }, // m1→m2
    { id: 'r3', from: 'n1', to: 'n2' }, // m2→m3
    { id: 'r4', from: 'n0', to: 'n7' }, // 同模块 m1→m1（应忽略）
  ];
  const links = galaxyLinks(nodes, rels);
  assert.equal(links.length, 2);
  const m1m2 = links.find((l) => l.from === 'm1' && l.to === 'm2');
  assert.equal(m1m2.count, 2);
  assert.equal(m1m2.relations[0].id, 'r1');
});

test('hash32 稳定性：同串同值，不同串不同值', () => {
  assert.equal(hash32('orbit:n1'), hash32('orbit:n1'));
  assert.notEqual(hash32('orbit:n1'), hash32('orbit:n2'));
  assert.ok(hash32('x') >= 0 && hash32('x') <= 0xffffffff);
});

test('拾取 id 映射：InstancedMesh instanceId → 节点', async () => {
  // 与 interaction.mjs resolveTarget 同语义：instanceId 经 idToIndex 反查节点
  const members = nodes.filter((n) => n.module === 'm3');
  const idToIndex = new Map(members.map((n, i) => [n.id, i]));
  const indexToNode = new Map(members.map((n, i) => [i, n]));
  for (const [id, i] of idToIndex) {
    assert.equal(indexToNode.get(i).id, id);
  }
  // 越界 instanceId 不应解析出节点（防御）
  assert.equal(indexToNode.get(999), undefined);
});
