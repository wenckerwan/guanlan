/**
 * Three.js 场景骨架：渲染器 / 相机 / 灯光 / OrbitControls / CSS2DRenderer / rAF 循环 / 销毁。
 * 仅管理渲染基础设施，业务对象由 planets/background/interaction 挂载。
 */
import * as THREE from 'three';
import { OrbitControls } from 'three/examples/jsm/controls/OrbitControls.js';
import { CSS2DRenderer } from 'three/examples/jsm/renderers/CSS2DRenderer.js';

/** 探测 WebGL 可用性（失败则上层回退到 SVG star 视图） */
export function webglAvailable() {
  try {
    const canvas = document.createElement('canvas');
    return !!(
      window.WebGLRenderingContext &&
      (canvas.getContext('webgl2') ||
        canvas.getContext('webgl') ||
        canvas.getContext('experimental-webgl'))
    );
  } catch {
    return false;
  }
}

export function createUniverse(container, { quality = 'high' } = {}) {
  const low = quality === 'low';
  const scene = new THREE.Scene();

  const camera = new THREE.PerspectiveCamera(
    55,
    container.clientWidth / Math.max(1, container.clientHeight),
    0.1,
    6000,
  );
  camera.position.set(0, 900, 1500);

  let renderer;
  try {
    renderer = new THREE.WebGLRenderer({ antialias: !low, alpha: false });
  } catch (e) {
    return null; // 交给上层回退
  }
  renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, low ? 1.5 : 2));
  renderer.setSize(container.clientWidth, container.clientHeight);
  renderer.setClearColor(0x070b18, 1);
  renderer.domElement.style.position = 'absolute';
  renderer.domElement.style.inset = '0';
  renderer.domElement.style.touchAction = 'none';
  container.appendChild(renderer.domElement);

  // CSS2D 标签层：HTML 文字，可选中/可访问
  const labelRenderer = new CSS2DRenderer();
  labelRenderer.setSize(container.clientWidth, container.clientHeight);
  labelRenderer.domElement.style.position = 'absolute';
  labelRenderer.domElement.style.inset = '0';
  labelRenderer.domElement.style.pointerEvents = 'none';
  container.appendChild(labelRenderer.domElement);

  // 灯光：1 主平行光（定明暗面）+ 环境光
  const sun = new THREE.DirectionalLight(0xffffff, 2.2);
  sun.position.set(600, 900, 400);
  scene.add(sun);
  scene.add(new THREE.AmbientLight(0x8fa3c8, 0.55));

  // 控制器：拖转 / 缩放 / 阻尼
  const controls = new OrbitControls(camera, renderer.domElement);
  controls.enableDamping = true;
  controls.dampingFactor = 0.06;
  controls.minDistance = 18;
  controls.maxDistance = 3200;
  controls.target.set(0, 0, 0);

  const clock = new THREE.Clock();
  const updaters = new Set(); // (elapsed, delta) => void
  let raf = 0;
  let disposed = false;
  let paused = false;

  function frame() {
    if (disposed) return;
    raf = requestAnimationFrame(frame);
    const delta = clock.getDelta();
    const elapsed = clock.elapsedTime;
    if (!paused) for (const fn of updaters) fn(elapsed, delta);
    controls.update();
    renderer.render(scene, camera);
    labelRenderer.render(scene, camera);
  }

  function onResize() {
    if (disposed) return;
    const w = container.clientWidth;
    const h = Math.max(1, container.clientHeight);
    camera.aspect = w / h;
    camera.updateProjectionMatrix();
    renderer.setSize(w, h);
    labelRenderer.setSize(w, h);
  }
  const resizeObserver = new ResizeObserver(onResize);
  resizeObserver.observe(container);

  frame();

  return {
    scene,
    camera,
    renderer,
    labelRenderer,
    controls,
    addUpdater: (fn) => updaters.add(fn),
    removeUpdater: (fn) => updaters.delete(fn),
    setPaused: (v) => {
      paused = v;
    },
    dispose() {
      if (disposed) return;
      disposed = true;
      cancelAnimationFrame(raf);
      resizeObserver.disconnect();
      controls.dispose();
      scene.traverse((obj) => {
        if (obj.geometry) obj.geometry.dispose();
        const mats = Array.isArray(obj.material) ? obj.material : [obj.material];
        for (const m of mats) {
          if (!m) continue;
          for (const key of Object.keys(m)) {
            const v = m[key];
            if (v && v.isTexture) v.dispose();
          }
          m.dispose();
        }
        // CSS2D 标签 DOM 一并清理
        if (obj.isCSS2DObject && obj.element && obj.element.parentNode) {
          obj.element.parentNode.removeChild(obj.element);
        }
      });
      renderer.dispose();
      renderer.domElement.remove();
      labelRenderer.domElement.remove();
    },
  };
}
