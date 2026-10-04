/**
 * 星球网格构建：大星球（程序纹理 + 自发光 + 辉光壳）、概念卫星（InstancedMesh）、
 * 轨道线、引力线（关系）。几何体/材质共享复用，纹理程序生成一次缓存。
 */
import * as THREE from 'three';
import { hash32, orbitPosition } from './layout.mjs';

export const PLANET_RADIUS = 16;
export const SATELLITE_RADIUS = 2.6;
export const CENTER_RADIUS = 6;

const textureCache = new Map();

/** 星球表面程序纹理：模块色基底 + 经纬网格 + 噪点（缓存复用） */
function planetTexture(colorHex) {
  if (textureCache.has(colorHex)) return textureCache.get(colorHex);
  const size = 512;
  const canvas = document.createElement('canvas');
  canvas.width = canvas.height = size;
  const ctx = canvas.getContext('2d');
  const base = new THREE.Color(colorHex);
  const dark = base.clone().multiplyScalar(0.55);
  const light = base.clone().lerp(new THREE.Color('#ffffff'), 0.25);
  const g = ctx.createLinearGradient(0, 0, 0, size);
  g.addColorStop(0, '#' + light.getHexString());
  g.addColorStop(0.5, '#' + base.getHexString());
  g.addColorStop(1, '#' + dark.getHexString());
  ctx.fillStyle = g;
  ctx.fillRect(0, 0, size, size);
  // 经纬线
  ctx.strokeStyle = 'rgba(255,255,255,.13)';
  ctx.lineWidth = 1;
  for (let i = 1; i < 12; i++) {
    ctx.beginPath();
    ctx.moveTo((i * size) / 12, 0);
    ctx.lineTo((i * size) / 12, size);
    ctx.stroke();
  }
  for (let i = 1; i < 7; i++) {
    ctx.beginPath();
    ctx.moveTo(0, (i * size) / 7);
    ctx.lineTo(size, (i * size) / 7);
    ctx.stroke();
  }
  // 噪点（大陆/云斑感）
  let s = hash32('planet-noise:' + colorHex);
  const rnd = () => {
    s = (Math.imul(s, 1664525) + 1013904223) >>> 0;
    return s / 0xffffffff;
  };
  for (let i = 0; i < 260; i++) {
    const x = rnd() * size;
    const y = rnd() * size;
    const r = 2 + rnd() * 16;
    const gg = ctx.createRadialGradient(x, y, 0, x, y, r);
    const on = rnd() > 0.5;
    gg.addColorStop(0, on ? 'rgba(255,255,255,.07)' : 'rgba(0,0,20,.10)');
    gg.addColorStop(1, 'rgba(0,0,0,0)');
    ctx.fillStyle = gg;
    ctx.fillRect(x - r, y - r, r * 2, r * 2);
  }
  const texture = new THREE.CanvasTexture(canvas);
  texture.colorSpace = THREE.SRGBColorSpace;
  textureCache.set(colorHex, texture);
  return texture;
}

/** 共享几何体 */
const geoCache = new Map();
function sphereGeo(key, radius, w = 32, h = 24) {
  if (!geoCache.has(key)) geoCache.set(key, new THREE.SphereGeometry(radius, w, h));
  return geoCache.get(key);
}

/**
 * 把缓存的几何体/纹理作为不可见资产挂到场景：
 * scene.mjs 的 dispose() 会 traverse 并 dispose 它们（不渲染、零开销）。
 * 注意：共享几何/材质会在 holder 中被 dispose，本模块缓存与组件同生命周期，
 * 组件销毁后若再次挂载会重建缓存（dispose 过的对象不再入缓存引用）。
 */
export function markShared(scene) {
  const holder = new THREE.Group();
  holder.name = 'shared-assets';
  holder.visible = false;
  for (const geo of geoCache.values()) {
    const m = new THREE.Mesh(geo, new THREE.MeshBasicMaterial());
    holder.add(m);
  }
  for (const tex of textureCache.values()) {
    const m = new THREE.Mesh(new THREE.BufferGeometry(), new THREE.MeshBasicMaterial({ map: tex }));
    holder.add(m);
  }
  scene.add(holder);
  // 清空缓存引用：dispose 后不得复用（防 use-after-dispose）
  geoCache.clear();
  textureCache.clear();
}

/**
 * 挂载一个星系（模块大星球 + 辉光壳 + 标签锚点）。
 * @returns {group, planet, glow}
 */
export function createPlanet(scene, { id, title, color, position, mastered = 0, total = 0 }) {
  const group = new THREE.Group();
  group.position.set(position.x, position.y, position.z);
  group.name = 'planet:' + id;

  const material = new THREE.MeshStandardMaterial({
    map: planetTexture(color),
    color: '#ffffff',
    roughness: 0.62,
    metalness: 0.18,
    emissive: new THREE.Color(color),
    emissiveIntensity: 0.28,
  });
  const planet = new THREE.Mesh(sphereGeo('planet', PLANET_RADIUS), material);
  planet.userData = { kind: 'chapter', id, title };
  group.add(planet);

  // 大气辉光壳：略大、加色混合、反向渲染
  const glow = new THREE.Mesh(
    sphereGeo('glow', PLANET_RADIUS * 1.22, 32, 24),
    new THREE.MeshBasicMaterial({
      color,
      transparent: true,
      opacity: 0.16,
      blending: THREE.AdditiveBlending,
      side: THREE.BackSide,
      depthWrite: false,
    }),
  );
  group.add(glow);

  scene.add(group);
  return { group, planet, glow };
}

/**
 * 概念卫星：同模块一批实例（InstancedMesh），mastery 通过实例颜色区分。
 * @returns {mesh, idToIndex, setMastery, layout(t) 更新实例矩阵}
 */
export function createSatellites(scene, parent, nodes, orbits, mastery, { color, quality = 'high' } = {}) {
  const count = nodes.length;
  const geometry = sphereGeo('sat', SATELLITE_RADIUS, quality === 'low' ? 12 : 18, quality === 'low' ? 8 : 12);
  const material = new THREE.MeshStandardMaterial({
    color: '#ffffff',
    roughness: 0.45,
    metalness: 0.1,
    emissive: new THREE.Color(color),
    emissiveIntensity: 0.35,
  });
  const mesh = new THREE.InstancedMesh(geometry, material, count);
  mesh.name = 'satellites';
  mesh.instanceMatrix.setUsage(THREE.DynamicDrawUsage);
  mesh.userData = { kind: 'satellites' };

  const idToIndex = new Map();
  const dim = new THREE.Color(color).multiplyScalar(0.85);
  const bright = new THREE.Color(color).lerp(new THREE.Color('#ffffff'), 0.55);
  const masteredColor = new THREE.Color('#7ee2a8');
  const tmpColor = new THREE.Color();

  function applyColors() {
    nodes.forEach((n, i) => {
      const m = mastery[n.id]?.mastery;
      if (m === 'mastered') tmpColor.copy(masteredColor);
      else if (m === 'fuzzy') tmpColor.copy(bright);
      else tmpColor.copy(dim);
      mesh.setColorAt(i, tmpColor);
    });
    if (mesh.instanceColor) mesh.instanceColor.needsUpdate = true;
  }

  nodes.forEach((n, i) => {
    idToIndex.set(n.id, i);
    mesh.userData['node:' + i] = n;
  });
  applyColors();

  const dummy = new THREE.Object3D();
  const entries = nodes.map((n, i) => ({ i, orbit: orbits.get(n.id)?.orbit }));

  /** 每帧按公转轨道更新实例矩阵；t 固定则静止（reduced-motion） */
  function layout(t) {
    for (const { i, orbit } of entries) {
      if (!orbit) continue;
      const p = orbitPosition(orbit, t);
      dummy.position.set(p.x, p.y, p.z);
      dummy.updateMatrix();
      mesh.setMatrixAt(i, dummy.matrix);
    }
    mesh.instanceMatrix.needsUpdate = true;
  }
  layout(0);

  parent.add(mesh);
  return { mesh, idToIndex, layout, refresh: applyColors };
}

/** 轨道参考线（每个星系内环/外环各一条虚线圆） */
export function createOrbitRings(parent, color) {
  const rings = [];
  for (const r of [26, 46]) {
    const curve = new THREE.EllipseCurve(0, 0, r, r * 0.92);
    const pts = curve.getPoints(96).map((p) => new THREE.Vector3(p.x, 0, p.y));
    const geo = new THREE.BufferGeometry().setFromPoints(pts);
    const mat = new THREE.LineBasicMaterial({
      color,
      transparent: true,
      opacity: 0.18,
      blending: THREE.AdditiveBlending,
      depthWrite: false,
    });
    const line = new THREE.LineLoop(geo, mat);
    line.userData = { owned: true }; // 独占几何，模式切换时销毁
    parent.add(line);
    rings.push(line);
  }
  return rings;
}

/**
 * 引力线（关系）：折线 + 加色混合，可被射线拾取。
 * @returns {line, flow} flow 为沿线移动光点（reduced-motion 时为 null）
 */
export function createGravityLine(parent, { from, to, color, relation, curved = true, flowing = false }) {
  const a = new THREE.Vector3(from.x, from.y, from.z);
  const b = new THREE.Vector3(to.x, to.y, to.z);
  let points;
  if (curved) {
    const mid = a
      .clone()
      .add(b)
      .multiplyScalar(0.5)
      .add(new THREE.Vector3(0, a.distanceTo(b) * 0.12, 0));
    const curve = new THREE.QuadraticBezierCurve3(a, mid, b);
    points = curve.getPoints(40);
  } else {
    points = [a, b];
  }
  const geo = new THREE.BufferGeometry().setFromPoints(points);
  const mat = new THREE.LineBasicMaterial({
    color,
    transparent: true,
    opacity: 0.42,
    blending: THREE.AdditiveBlending,
    depthWrite: false,
  });
  const line = new THREE.Line(geo, mat);
  line.userData = { kind: 'relation', relation };
  parent.add(line);

  let flow = null;
  if (flowing) {
    flow = new THREE.Mesh(
      sphereGeo('flow', 0.9, 8, 6),
      new THREE.MeshBasicMaterial({ color: 0xffffff, transparent: true, opacity: 0.9, blending: THREE.AdditiveBlending, depthWrite: false }),
    );
    parent.add(flow);
  }

  return {
    line,
    flow,
    /** t∈[0,1] 光点位置（外部用 elapsed*speed%1 驱动） */
    setFlow(t) {
      if (!flow) return;
      const idx = Math.min(points.length - 1, Math.floor(t * points.length));
      flow.position.copy(points[idx]);
    },
  };
}

/** 聚焦模式中央星球（选中概念） */
export function createCenterPlanet(scene, { color, title }) {
  const material = new THREE.MeshStandardMaterial({
    map: planetTexture(color),
    roughness: 0.55,
    metalness: 0.15,
    emissive: new THREE.Color(color),
    emissiveIntensity: 0.5,
  });
  const mesh = new THREE.Mesh(sphereGeo('center', CENTER_RADIUS, 28, 20), material);
  mesh.userData = { kind: 'center', title };
  scene.add(mesh);
  return mesh;
}

/** 聚焦模式邻居卫星（数量少，独立 Mesh 便于 hover/拾取与标签锚定） */
export function createFocusSatellites(scene, nodes, positions, mastery, modules) {
  const group = new THREE.Group();
  group.name = 'focus-satellites';
  const meshes = new Map();
  for (const n of nodes) {
    const color = modules.find((m) => m.id === n.module)?.color || '#8fbdaf';
    const mastered = mastery[n.id]?.mastery === 'mastered';
    const mat = new THREE.MeshStandardMaterial({
      color: mastered ? '#7ee2a8' : new THREE.Color(color).lerp(new THREE.Color('#ffffff'), 0.3),
      roughness: 0.45,
      metalness: 0.1,
      emissive: new THREE.Color(color),
      emissiveIntensity: mastered ? 0.55 : 0.3,
    });
    const mesh = new THREE.Mesh(sphereGeo('focus-sat', 2.2, 16, 12), mat);
    const p = positions.get(n.id);
    mesh.position.set(p.x, p.y, p.z);
    mesh.userData = { kind: 'node', node: n };
    group.add(mesh);
    meshes.set(n.id, mesh);
  }
  scene.add(group);
  return { group, meshes };
}

/** 混合两色（引力线取两端模块色混合） */
export function mixColor(a, b) {
  return new THREE.Color(a).lerp(new THREE.Color(b), 0.5).getStyle();
}
