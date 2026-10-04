/**
 * 交互层：射线拾取（点选/hover）、相机飞行（自写 rAF 缓动，无 gsap）、
 * CSS2D 标签工厂、reduced-motion 探测。
 */
import * as THREE from 'three';
import { CSS2DObject } from 'three/examples/jsm/renderers/CSS2DRenderer.js';

/** prefers-reduced-motion 探测（结果可被外部覆盖） */
export function prefersReducedMotion() {
  try {
    return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  } catch {
    return false;
  }
}

/** 移动端/低端探测：影响像素比、星点数、反锯齿 */
export function detectQuality() {
  const ua = navigator.userAgent || '';
  const mobile = /Android|iPhone|iPad|iPod|Mobile/i.test(ua);
  const cores = navigator.hardwareConcurrency || 4;
  return mobile || cores <= 4 ? 'low' : 'high';
}

/** 射线拾取器：click 与节流 hover */
export function createPicker({ camera, domElement, getPickables, onOpen, onHover, controls }) {
  const raycaster = new THREE.Raycaster();
  raycaster.params.Line.threshold = 1.2;
  const pointer = new THREE.Vector2();
  let downAt = null;
  let hoverFrame = 0;
  let lastHover = null;

  function castAt(clientX, clientY) {
    const rect = domElement.getBoundingClientRect();
    pointer.x = ((clientX - rect.left) / rect.width) * 2 - 1;
    pointer.y = -((clientY - rect.top) / rect.height) * 2 + 1;
    raycaster.setFromCamera(pointer, camera);
    const pickables = getPickables();
    const hits = raycaster.intersectObjects(pickables, false);
    return hits.length ? hits[0] : null;
  }

  function resolveTarget(hit) {
    if (!hit) return null;
    const obj = hit.object;
    const data = obj.userData || {};
    if (data.kind === 'satellites' && hit.instanceId != null) {
      const node = data['node:' + hit.instanceId];
      return node ? { type: 'node', node, object: obj, instanceId: hit.instanceId } : null;
    }
    if (data.kind === 'node') return { type: 'node', node: data.node, object: obj };
    if (data.kind === 'chapter') return { type: 'chapter', id: data.id, title: data.title, object: obj };
    if (data.kind === 'chapter-home') return { type: 'chapter-home', id: data.id, title: data.title, object: obj };
    if (data.kind === 'relation') return { type: 'relation', relation: data.relation, object: obj };
    return null;
  }

  function onPointerDown(e) {
    downAt = { x: e.clientX, y: e.clientY };
  }
  function onPointerUp(e) {
    if (!downAt) return;
    const moved = Math.hypot(e.clientX - downAt.x, e.clientY - downAt.y);
    downAt = null;
    if (moved > 6) return; // 拖动不算点选
    const target = resolveTarget(castAt(e.clientX, e.clientY));
    if (target) onOpen(target);
  }
  function onPointerMove(e) {
    // 节流：每 3 帧最多拾取一次
    if (++hoverFrame % 3 !== 0) return;
    const target = resolveTarget(castAt(e.clientX, e.clientY));
    const key = target
      ? target.type + ':' + (target.node?.id || target.id || target.relation?.id) + ':' + (target.instanceId ?? '')
      : '';
    const lastKey = lastHover
      ? lastHover.type + ':' + (lastHover.node?.id || lastHover.id || lastHover.relation?.id) + ':' + (lastHover.instanceId ?? '')
      : '';
    if (key !== lastKey) {
      lastHover = target;
      onHover(target);
    }
  }

  domElement.addEventListener('pointerdown', onPointerDown);
  domElement.addEventListener('pointerup', onPointerUp);
  domElement.addEventListener('pointermove', onPointerMove);

  return {
    get hovering() {
      return lastHover;
    },
    dispose() {
      domElement.removeEventListener('pointerdown', onPointerDown);
      domElement.removeEventListener('pointerup', onPointerUp);
      domElement.removeEventListener('pointermove', onPointerMove);
    },
  };
}

/**
 * 相机飞行：自写 rAF 缓动（easeInOutCubic），同时插值相机位置与 controls.target。
 * reducedMotion 时瞬间切换。
 * @returns {flyTo, isFlying, cancel}
 */
export function createFlight(camera, controls, { reducedMotion = false, duration = 1.15 } = {}) {
  let raf = 0;
  let flying = null;

  function cancel() {
    if (raf) cancelAnimationFrame(raf);
    raf = 0;
    flying = null;
  }

  function flyTo(toPosition, toTarget, onDone) {
    cancel();
    const endP = new THREE.Vector3(toPosition.x, toPosition.y, toPosition.z);
    const endT = new THREE.Vector3(toTarget.x, toTarget.y, toTarget.z);
    if (reducedMotion || duration <= 0) {
      camera.position.copy(endP);
      controls.target.copy(endT);
      controls.update();
      if (onDone) onDone();
      return;
    }
    const startP = camera.position.clone();
    const startT = controls.target.clone();
    const start = performance.now();
    flying = true;
    controls.enabled = false;
    function step(now) {
      const t = Math.min(1, (now - start) / (duration * 1000));
      const e = t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2; // easeInOutCubic
      camera.position.lerpVectors(startP, endP, e);
      controls.target.lerpVectors(startT, endT, e);
      controls.update();
      if (t < 1) {
        raf = requestAnimationFrame(step);
      } else {
        raf = 0;
        flying = null;
        controls.enabled = true;
        if (onDone) onDone();
      }
    }
    raf = requestAnimationFrame(step);
  }

  return {
    flyTo,
    cancel,
    get isFlying() {
      return !!flying;
    },
  };
}

/**
 * CSS2D 标签工厂：文字为真实 HTML（可选中/可访问），pointerEvents 关闭以免挡拾取。
 * @param text 标签文本；@param className 附加类名
 */
export function createLabel(text, className = '') {
  const div = document.createElement('div');
  div.className = ('universe-label ' + className).trim();
  div.textContent = text;
  const obj = new CSS2DObject(div);
  obj.center.set(0.5, 1.15);
  return obj;
}

/** 设置标签可见性（距离/模式控制用） */
export function setLabelVisible(label, visible) {
  if (label && label.element) label.element.style.display = visible ? '' : 'none';
}
