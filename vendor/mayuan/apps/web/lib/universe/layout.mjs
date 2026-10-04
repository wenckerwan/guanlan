/**
 * 3D 宇宙布局（确定性生成：同一内容每次渲染坐标一致，便于测试与截图回归）。
 * 纯函数，不依赖 three.js，可在 node:test 中直接断言。
 *
 * 坐标系约定：y 轴向上，7 个星系分布在 XZ 水平圆上，总览相机在圆心上方俯视。
 */

export const UNIVERSE_RADIUS = 620; // 星系圆半径
export const GALAXY_TILT = 0.32; // 星系公转面整体起伏幅度（确定性，非随机）
export const CONCEPT_INNER = 8; // 内环卫星数上限，与现有 SVG 双环一致
export const RING_RADIUS = [26, 46]; // 内/外环公转半径
export const FOCUS_RING_RADIUS = 30; // 聚焦模式邻居环绕半径
export const FOCUS_CENTER = { x: 0, y: 0, z: 0 };

/** 字符串 → 稳定 32 位无符号散列（FNV-1a，跨平台确定） */
export function hash32(str) {
  let h = 0x811c9dc5;
  for (let i = 0; i < str.length; i++) {
    h ^= str.charCodeAt(i);
    h = Math.imul(h, 0x01000193);
  }
  return h >>> 0;
}

/** 由散列取 [0,1) 确定性伪随机值 */
export function hashUnit(str) {
  return hash32(str) / 0xffffffff;
}

/**
 * 星系（模块）布局：7 个模块均匀放在半径 R 的水平圆上，各自成星系团。
 * @returns Map<moduleId, {x,y,z,index}>
 */
export function galaxyLayout(modules) {
  const map = new Map();
  modules.forEach((m, i) => {
    const theta = (i / modules.length) * Math.PI * 2 - Math.PI / 2;
    map.set(m.id, {
      x: Math.cos(theta) * UNIVERSE_RADIUS,
      // 由模块 id 决定的确定性起伏，避免全部落在同一平面显得呆板
      y: (hashUnit('galaxy-y:' + m.id) - 0.5) * 2 * UNIVERSE_RADIUS * GALAXY_TILT * 0.25,
      z: Math.sin(theta) * UNIVERSE_RADIUS,
      index: i,
    });
  });
  return map;
}

/**
 * 概念环绕布局：模块内概念按现有"双环"思路映射到 3D 椭圆轨道。
 * 每个卫星的轨道倾角/相位/方向由 id 散列确定，同模块内稳定。
 * @returns Map<nodeId, {orbit:{rx,rz,tilt,tiltAxis,phase,speed,direction}, ring, slot}>
 */
export function conceptOrbits(modulesNodes) {
  // modulesNodes: Map<moduleId, node[]>（数组顺序 = 内容顺序，稳定）
  const map = new Map();
  for (const [moduleId, members] of modulesNodes) {
    members.forEach((n, index) => {
      const ring = index < CONCEPT_INNER ? 0 : 1;
      const slot = ring ? index - CONCEPT_INNER : index;
      const count = ring
        ? Math.max(1, members.length - CONCEPT_INNER)
        : Math.min(CONCEPT_INNER, members.length);
      const h = hash32('orbit:' + n.id);
      map.set(n.id, {
        module: moduleId,
        ring,
        slot,
        count,
        orbit: {
          r: RING_RADIUS[ring],
          // 椭圆压扁系数 0.78–1（由 id 决定）
          squash: 0.78 + ((h >>> 8) % 1000) / 1000 / 4.6,
          // 轨道面倾角 ±0.55 rad，绕一条由 id 决定的水平轴
          tilt: (((h >>> 4) % 1000) / 1000 - 0.5) * 1.1,
          tiltAxis: (((h >>> 14) % 1000) / 1000) * Math.PI * 2,
          phase: (slot / count) * Math.PI * 2 - Math.PI / 2,
          // 公转角速度（rad/s），缓慢；方向半数反向
          speed: 0.05 + ((h >>> 22) % 100) / 100 * 0.05,
          direction: h & 1 ? 1 : -1,
        },
      });
    });
  }
  return map;
}

/** 由轨道参数 + 时间求卫星世界坐标（相对其星系中心） */
export function orbitPosition(orbit, t) {
  const a = orbit.phase + orbit.direction * orbit.speed * t;
  const x = Math.cos(a) * orbit.r;
  const z0 = Math.sin(a) * orbit.r * orbit.squash;
  // 绕 tiltAxis（XZ 平面内一条水平轴）旋转 tilt
  const ux = Math.cos(orbit.tiltAxis);
  const uz = Math.sin(orbit.tiltAxis);
  const along = x * ux + z0 * uz;
  const perp = -x * uz + z0 * ux;
  const y = Math.sin(orbit.tilt) * perp;
  const perp2 = Math.cos(orbit.tilt) * perp;
  return { x: ux * along - uz * perp2, y, z: uz * along + ux * perp2 };
}

/**
 * 聚焦布局：选中概念居中，其邻居（与现有 focusLayout 同集合语义）环绕成卫星环。
 * @returns Map<nodeId, {x,y,z}>
 */
export function focusLayout3d(neighborIds, selectedId) {
  const sorted = [...neighborIds].sort((a, b) => a.localeCompare(b));
  const map = new Map();
  map.set(selectedId, { ...FOCUS_CENTER });
  sorted.forEach((id, i) => {
    const theta = (i / Math.max(1, sorted.length)) * Math.PI * 2 - Math.PI / 2;
    const r = sorted.length > 8 ? FOCUS_RING_RADIUS * 1.35 : FOCUS_RING_RADIUS;
    const h = hash32('focus:' + id);
    map.set(id, {
      x: Math.cos(theta) * r,
      y: (((h >>> 6) % 1000) / 1000 - 0.5) * r * 0.5,
      z: Math.sin(theta) * r,
    });
  });
  return map;
}

/**
 * 关系引力线端点：根据模式解析节点世界坐标。
 * @param positions Map<nodeId,{x,y,z}> 已解析的世界坐标
 */
export function relationSegments(relations, positions) {
  const out = [];
  for (const r of relations) {
    const a = positions.get(r.from);
    const b = positions.get(r.to);
    if (a && b) out.push({ relation: r, from: a, to: b });
  }
  return out;
}

/** 跨模块关系聚合（总览模式的星系连线，复用 moduleLinks 语义的 3D 版） */
export function galaxyLinks(nodes, relations) {
  const lookup = new Map(nodes.map((n) => [n.id, n.module]));
  const groups = new Map();
  for (const r of relations) {
    const from = lookup.get(r.from);
    const to = lookup.get(r.to);
    if (!from || !to || from === to) continue;
    const key = from + '>' + to;
    if (!groups.has(key)) groups.set(key, { id: key, from, to, count: 0, relations: [] });
    const g = groups.get(key);
    g.count++;
    g.relations.push(r);
  }
  return [...groups.values()];
}
