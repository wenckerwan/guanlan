<script setup lang="ts">
/**
 * 3D 知识宇宙（Three.js）：深空星云 + 7 色大星球 + 环绕概念卫星 + 引力线。
 * 仅用于 view='stars'；业务逻辑零改动 —— 对外只发 open/relation/chapter 事件。
 * 三种模式：overview（七章总览）/ galaxy（单星系概念环绕）/ focus（概念聚焦邻居环）。
 */
import * as THREE from 'three';
import { createUniverse } from '../../lib/universe/scene.mjs';
import { createBackground } from '../../lib/universe/background.mjs';
import {
  galaxyLayout,
  conceptOrbits,
  galaxyLinks,
  relationSegments,
  focusLayout3d,
  orbitPosition,
  UNIVERSE_RADIUS,
} from '../../lib/universe/layout.mjs';
import {
  PLANET_RADIUS,
  createPlanet,
  createSatellites,
  createOrbitRings,
  createGravityLine,
  createCenterPlanet,
  createFocusSatellites,
  mixColor,
  markShared,
} from '../../lib/universe/planets.mjs';
import {
  createPicker,
  createFlight,
  createLabel,
  prefersReducedMotion,
  detectQuality,
} from '../../lib/universe/interaction.mjs';

type NodeT = { id: string; title: string; module: string };
type RelationT = { id: string; from: string; to: string; label: string; kind?: string };
const props = defineProps<{
  nodes: NodeT[];
  relations: RelationT[];
  modules: any[];
  mastery: Record<string, any>;
  selectedId?: string;
  chapter: string;
}>();
const emit = defineEmits<{
  open: [node: NodeT];
  relation: [relation: RelationT];
  chapter: [id: string];
  webglFail: [];
}>();

const container = ref<HTMLElement | null>(null);
const hint = ref('拖动旋转 · 滚轮缩放 · 点击星球进入星系');
const hoverText = ref('');
const reducedMotion = prefersReducedMotion();
const quality = detectQuality();

let uni: any = null;
let background: any = null;
let picker: any = null;
let flight: any = null;
let stage: any = null; // 当前模式的 Three 对象集合
let mode = '';
let satTime = 0;
let zoomLevel = 1;

const moduleById = computed(() => new Map(props.modules.map((m: any) => [m.id, m])));
function moduleColor(id: string) {
  return (moduleById.value.get(id) as any)?.color || '#8fbdaf';
}

function disposeStage() {
  if (!uni || !stage) return;
  for (const fn of stage.updaters || []) uni.removeUpdater(fn);
  for (const obj of stage.objects) {
    if (obj.parent) obj.parent.remove(obj);
    else uni.scene.remove(obj);
  }
  // 独占几何/材质销毁防泄漏；共享缓存（星球/卫星几何与纹理）保留复用。
  for (const obj of stage.owned || []) {
    if (obj.geometry) obj.geometry.dispose();
    if (obj.material) obj.material.dispose();
  }
  stage = null;
}

function pickables() {
  return stage ? stage.pickables : [];
}

function onPick(target: any) {
  if (!target) return;
  if (target.type === 'node') emit('open', target.node);
  else if (target.type === 'relation') emit('relation', target.relation);
  else if (target.type === 'chapter') emit('chapter', target.id);
  else if (target.type === 'chapter-home') emit('chapter', 'all'); // 星系内点大星球回总览
}

function onHover(target: any) {
  if (!target) {
    hoverText.value = '';
    return;
  }
  hoverText.value =
    target.type === 'node'
      ? target.node.title
      : target.type === 'relation'
        ? `${target.relation.label}（点击查看）`
        : target.type === 'chapter-home'
          ? '返回七章总览'
          : `${target.title}（点击进入）`;
}

/** 右下缩放按钮：自写 dolly（OrbitControls 未公开 dollyIn/Out） */
function zoomBy(factor: number) {
  if (!uni) return;
  zoomLevel = Math.min(4, Math.max(0.25, zoomLevel * factor));
  const dir = uni.camera.position.clone().sub(uni.controls.target);
  const len = dir.length();
  const next = Math.min(uni.controls.maxDistance, Math.max(uni.controls.minDistance, len * factor));
  dir.setLength(next);
  uni.camera.position.copy(uni.controls.target).add(dir);
}

/** 清空并重建当前模式场景 */
function rebuild() {
  if (!uni || !props.nodes?.length || !props.modules?.length) return;
  disposeStage();
  satTime = 0;
  const selected = props.selectedId ? props.nodes.find((n) => n.id === props.selectedId) : null;
  if (selected) buildFocus(selected);
  else if (props.chapter === 'all') buildOverview();
  else buildGalaxy(props.chapter);
}

/** —— 总览：7 色大星球 + 星系间引力线 —— */
function buildOverview() {
  mode = 'overview';
  hint.value = '点击大星球进入该章星系 · 拖动旋转 · 滚轮缩放';
  const galaxies = galaxyLayout(props.modules);
  const objects: any[] = [];
  const pick: any[] = [];
  const planetLabels: any[] = [];

  for (const m of props.modules) {
    const g = galaxies.get(m.id)!;
    const { group, planet } = createPlanet(uni.scene, {
      id: m.id,
      title: m.title,
      color: m.color,
      position: g,
    });
    const label = createLabel(`${m.title} · ${props.nodes.filter((n) => n.module === m.id).length} 个概念`, 'planet-label');
    label.position.set(0, PLANET_RADIUS * 1.45, 0);
    group.add(label);
    planetLabels.push(label);
    objects.push(group);
    pick.push(planet);
  }

  const modeUpdaters: any[] = [];
  const owned: any[] = [];
  // 星系间引力线（跨模块关系聚合）
  for (const link of galaxyLinks(props.nodes, props.relations)) {
    const a = galaxies.get(link.from);
    const b = galaxies.get(link.to);
    if (!a || !b) continue;
    const color = mixColor(moduleColor(link.from), moduleColor(link.to));
    const { line, setFlow, flow } = createGravityLine(uni.scene, {
      from: a,
      to: b,
      color,
      relation: { ...link, id: link.id, label: `${link.count} 条跨章联系` },
      flowing: !reducedMotion,
    });
    objects.push(line);
    if (flow) objects.push(flow);
    owned.push(line);
    pick.push(line);
    const updater = (elapsed: number) => setFlow((elapsed * 0.12 + link.count * 0.07) % 1);
    uni.addUpdater(updater);
    modeUpdaters.push(updater);
  }

  stage = { objects, pickables: pick, planetLabels, galaxies, owned, updaters: modeUpdaters };
  flight.flyTo({ x: 0, y: UNIVERSE_RADIUS * 1.35, z: UNIVERSE_RADIUS * 2.1 }, { x: 0, y: 0, z: 0 });
}

/** —— 单星系：大星球 + 概念卫星环绕 + 概念间引力线 —— */
function buildGalaxy(chapterId: string) {
  mode = 'galaxy';
  const module = moduleById.value.get(chapterId) as any;
  const members = props.nodes.filter((n) => n.module === chapterId);
  hint.value = `${module?.title || ''} · 点击卫星阅读概念 · 点击连线查看关系`;
  const galaxies = galaxyLayout(props.modules);
  const center = galaxies.get(chapterId)!;
  const objects: any[] = [];
  const pick: any[] = [];

  const group = new THREE.Group();
  group.position.set(center.x, center.y, center.z);
  uni.scene.add(group);
  objects.push(group);
  const owned: any[] = []; // 独占几何/材质（轨道环、引力线），模式切换时销毁

  const { planet } = createPlanet(group, {
    id: chapterId,
    title: module?.title,
    color: module?.color,
    position: { x: 0, y: 0, z: 0 },
  });
  planet.userData = { kind: 'chapter-home', id: chapterId, title: module?.title }; // 点大星球回总览

  const byModule = new Map([[chapterId, members]]);
  const orbits = conceptOrbits(byModule);
  const sats = createSatellites(uni.scene, group, members, orbits, props.mastery, {
    color: module?.color,
    quality,
  });
  pick.push(sats.mesh);
  stage = { objects, pickables: pick, sats, orbits, center };

  createOrbitRings(group, module?.color);
  // 轨道环与引力线都是独占几何，统一交给 disposeStage 销毁
  for (const ring of group.children.filter((c: any) => c.userData?.owned)) owned.push(ring);

  // 概念间引力线（模块内关系）
  const worldPos = new Map<string, any>();
  function resolveWorld(t: number) {
    for (const n of members) {
      const o = orbits.get(n.id)!.orbit;
      const p = orbitPosition(o, t);
      worldPos.set(n.id, { x: p.x + center.x, y: p.y + center.y, z: p.z + center.z });
    }
  }
  const inner = props.relations.filter(
    (r) => members.some((n) => n.id === r.from) && members.some((n) => n.id === r.to),
  );
  const lineBuilders: any[] = [];
  resolveWorld(0);
  for (const seg of relationSegments(inner, worldPos)) {
    const color = module?.color || '#8fbdaf';
    const { line, setFlow, flow } = createGravityLine(uni.scene, {
      from: seg.from,
      to: seg.to,
      color,
      relation: seg.relation,
      flowing: !reducedMotion && quality === 'high',
    });
    objects.push(line);
    if (flow) objects.push(flow);
    owned.push(line);
    pick.push(line);
    lineBuilders.push({ seg, line, setFlow });
  }

  // 每帧：卫星公转（reduced-motion 静止）+ 引力线端点跟随
  let lineTick = 0;
  const galaxyUpdater = (elapsed: number, delta: number) => {
    if (mode !== 'galaxy' || !stage?.sats) return;
    if (!reducedMotion) satTime += delta;
    stage.sats.layout(satTime);
    // 引力线重建节流（每 15 帧）
    if (!reducedMotion && ++lineTick % 15 === 0) {
      resolveWorld(satTime);
      for (const { seg, line } of lineBuilders) {
        const a = worldPos.get(seg.relation.from);
        const b = worldPos.get(seg.relation.to);
        if (!a || !b) continue;
        const pos = line.geometry.attributes.position;
        for (let i = 0; i < pos.count; i++) {
          const t = i / (pos.count - 1);
          const lift = Math.sin(t * Math.PI) * Math.hypot(a.x - b.x, a.y - b.y, a.z - b.z) * 0.12;
          pos.setXYZ(
            i,
            a.x + (b.x - a.x) * t,
            a.y + (b.y - a.y) * t + lift,
            a.z + (b.z - a.z) * t,
          );
        }
        pos.needsUpdate = true;
      }
    }
  };
  uni.addUpdater(galaxyUpdater);
  stage.updaters = [galaxyUpdater];
  stage.owned = owned;

  flight.flyTo(
    { x: center.x, y: center.y + 95, z: center.z + 150 },
    { x: center.x, y: center.y, z: center.z },
  );
}

/** —— 聚焦：选中概念居中，邻居环绕成卫星环 —— */
function buildFocus(node: NodeT) {
  mode = 'focus';
  hint.value = `${node.title} · 点击邻居概念跳转阅读`;
  const color = moduleColor(node.module);
  const objects: any[] = [];
  const pick: any[] = [];

  const center = createCenterPlanet(uni.scene, { color, title: node.title });
  center.userData = { kind: 'node', node };
  objects.push(center);
  pick.push(center);
  const centerLabel = createLabel(node.title, 'planet-label');
  centerLabel.position.set(0, 9, 0);
  center.add(centerLabel);

  const neighborIds = props.relations
    .filter((r) => r.from === node.id || r.to === node.id)
    .map((r) => (r.from === node.id ? r.to : r.from));
  const neighbors = props.nodes.filter((n) => neighborIds.includes(n.id));
  const positions = focusLayout3d(neighborIds, node.id);
  const { group, meshes } = createFocusSatellites(uni.scene, neighbors, positions, props.mastery, props.modules);
  objects.push(group);
  for (const mesh of meshes.values()) pick.push(mesh);

  for (const [id, mesh] of meshes) {
    const n = props.nodes.find((x) => x.id === id)!;
    const label = createLabel(n.title, 'sat-label');
    label.position.set(0, 4.2, 0);
    mesh.add(label);
    // 邻居标签也跟随移出场景（mesh 在 group 内，group 已在 objects）
  }

  // 中心与邻居的引力线
  const owned: any[] = [];
  for (const r of props.relations.filter((r) => r.from === node.id || r.to === node.id)) {
    const other = r.from === node.id ? r.to : r.from;
    const p = positions.get(other);
    if (!p) continue;
    const { line } = createGravityLine(uni.scene, {
      from: { x: 0, y: 0, z: 0 },
      to: p,
      color: mixColor(color, moduleColor(props.nodes.find((n) => n.id === other)?.module)),
      relation: r,
      curved: false,
    });
    objects.push(line);
    owned.push(line);
    pick.push(line);
  }

  stage = { objects, pickables: pick, focusMeshes: meshes, updaters: [], owned };
  flight.flyTo({ x: 0, y: 26, z: 62 }, { x: 0, y: 0, z: 0 });
}

onMounted(async () => {
  await nextTick();
  const el = container.value;
  if (!el) return;
  uni = createUniverse(el, { quality });
  if (!uni) {
    emit('webglFail'); // 上层回退到 SVG star 视图
    return;
  }
  markShared(uni.scene); // 几何体缓存与 scene.mjs 共享，dispose 统一释放
  background = createBackground(uni.scene, { quality, reducedMotion });
  uni.addUpdater((elapsed: number) => background.update(uni.camera, elapsed));
  flight = createFlight(uni.camera, uni.controls, { reducedMotion });
  picker = createPicker({
    camera: uni.camera,
    domElement: uni.renderer.domElement,
    getPickables: pickables,
    onOpen: onPick,
    onHover,
  });
  rebuild();
});

onBeforeUnmount(() => {
  disposeStage();
  picker?.dispose();
  flight?.cancel();
  uni?.dispose();
  uni = null;
});

// props 变化 → 重建或刷新（模式切换重建；mastery 变化只刷新颜色）
let watchTimer: any = null;
watch(
  () => [props.selectedId, props.chapter],
  () => {
    if (!uni) return;
    // 防抖：避免父组件连续更新造成同一帧多次重建
    clearTimeout(watchTimer);
    watchTimer = setTimeout(() => rebuild(), 0);
  },
);
watch(
  () => props.mastery,
  () => {
    if (stage?.sats) stage.sats.refresh();
  },
  { deep: true },
);
</script>

<template>
  <div class="universe-wrap">
    <div ref="container" class="universe-canvas" role="application"
      aria-label="3D 知识宇宙：拖动旋转，滚轮缩放，点击星球与卫星阅读" />
    <div class="universe-hud">
      <span class="universe-hint">{{ hint }}</span>
      <span v-if="hoverText" class="universe-hover">{{ hoverText }}</span>
      <span v-if="reducedMotion" class="universe-rm">已按系统设置关闭动效</span>
    </div>
    <div class="universe-zoom">
      <button @click="zoomBy(1 / 1.3)" aria-label="拉近视角">＋</button>
      <button @click="zoomBy(1.3)" aria-label="拉远视角">－</button>
    </div>
  </div>
</template>

<style>
.universe-wrap {
  position: relative;
  border-radius: 18px;
  overflow: hidden;
  border: 1px solid #1d2a45;
  background: #070b18;
}
.universe-canvas {
  position: relative;
  width: 100%;
  height: 560px;
}
.universe-hud {
  position: absolute;
  left: 12px;
  right: 12px;
  bottom: 10px;
  display: flex;
  gap: 10px;
  align-items: center;
  pointer-events: none;
  flex-wrap: wrap;
}
.universe-hint,
.universe-hover,
.universe-rm {
  font-size: 11px;
  color: #cfe0f5;
  background: rgba(10, 16, 32, 0.72);
  border: 1px solid rgba(130, 160, 210, 0.35);
  padding: 4px 10px;
  border-radius: 999px;
  backdrop-filter: blur(4px);
}
.universe-hover {
  color: #ffe2a8;
  border-color: rgba(230, 190, 110, 0.5);
}
.universe-zoom {
  position: absolute;
  right: 12px;
  bottom: 44px;
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.universe-zoom button {
  width: 38px;
  height: 38px;
  border-radius: 50%;
  border: 1px solid rgba(130, 160, 210, 0.45);
  background: rgba(10, 16, 32, 0.78);
  color: #dce8fa;
  font-size: 17px;
  cursor: pointer;
}
.universe-label {
  pointer-events: none;
  user-select: text;
  color: #eaf2ff;
  font-size: 12px;
  font-weight: 600;
  text-shadow: 0 1px 6px rgba(0, 0, 0, 0.9);
  background: rgba(8, 13, 28, 0.55);
  padding: 2px 8px;
  border-radius: 999px;
  white-space: nowrap;
}
.universe-label.sat-label {
  font-size: 11px;
  font-weight: 500;
  opacity: 0.92;
}
@media (max-width: 720px) {
  .universe-canvas {
    height: 430px;
  }
}
</style>
