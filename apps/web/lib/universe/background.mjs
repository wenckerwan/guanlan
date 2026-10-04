/**
 * 深空背景：程序生成星云纹理的反向大球 + 分层星点。
 * 纹理一次生成缓存复用；星云球随相机移动（看似无穷远）。
 * 确定性：星点用线性同余伪随机（固定种子），每次渲染一致。
 */
import * as THREE from 'three';

/** 固定种子 LCG，生成 [0,1) 序列（确定性，便于截图回归） */
function lcg(seed) {
  let s = seed >>> 0;
  return () => {
    s = (Math.imul(s, 1664525) + 1013904223) >>> 0;
    return s / 0xffffffff;
  };
}

/** 程序星云纹理：Canvas 2D 多色径向渐变 + 噪点 → CanvasTexture（1024²） */
export function createNebulaTexture() {
  const size = 1024;
  const canvas = document.createElement('canvas');
  canvas.width = canvas.height = size;
  const ctx = canvas.getContext('2d');
  // 深空底色
  ctx.fillStyle = '#070b18';
  ctx.fillRect(0, 0, size, size);

  const rnd = lcg(20261003);
  // 多色星云团：粉紫 / 青蓝 / 暖金
  const blobs = [
    ['rgba(150,90,200,', 26],
    ['rgba(80,140,220,', 26],
    ['rgba(220,170,90,', 14],
    ['rgba(90,200,190,', 14],
  ];
  for (const [color, count] of blobs) {
    for (let i = 0; i < count; i++) {
      const x = rnd() * size;
      const y = rnd() * size;
      const r = 60 + rnd() * 240;
      const g = ctx.createRadialGradient(x, y, 0, x, y, r);
      g.addColorStop(0, color + (0.10 + rnd() * 0.10) + ')');
      g.addColorStop(1, color + '0)');
      ctx.fillStyle = g;
      ctx.fillRect(x - r, y - r, r * 2, r * 2);
    }
  }
  // 细噪点（星尘颗粒感）
  const image = ctx.getImageData(0, 0, size, size);
  const data = image.data;
  for (let i = 0; i < data.length; i += 4) {
    const n = (rnd() - 0.5) * 14;
    data[i] += n;
    data[i + 1] += n;
    data[i + 2] += n;
  }
  ctx.putImageData(image, 0, 0);

  const texture = new THREE.CanvasTexture(canvas);
  texture.colorSpace = THREE.SRGBColorSpace;
  return texture;
}

/** 圆点贴图（Points 用，中心亮边缘透明） */
function createDotTexture() {
  const c = document.createElement('canvas');
  c.width = c.height = 64;
  const ctx = c.getContext('2d');
  const g = ctx.createRadialGradient(32, 32, 0, 32, 32, 32);
  g.addColorStop(0, 'rgba(255,255,255,1)');
  g.addColorStop(0.35, 'rgba(255,255,255,.75)');
  g.addColorStop(1, 'rgba(255,255,255,0)');
  ctx.fillStyle = g;
  ctx.fillRect(0, 0, 64, 64);
  return new THREE.CanvasTexture(c);
}

/**
 * 挂载背景：返回 { group, update(camera) }。
 * @param quality 'high' | 'low'（移动端减星点数）
 */
export function createBackground(scene, { quality = 'high', reducedMotion = false } = {}) {
  const group = new THREE.Group();
  group.name = 'universe-background';

  // 星云大球（BackSide 内表面贴图）
  const nebula = new THREE.Mesh(
    new THREE.SphereGeometry(4200, 48, 32),
    new THREE.MeshBasicMaterial({
      map: createNebulaTexture(),
      side: THREE.BackSide,
      depthWrite: false,
      fog: false,
    }),
  );
  group.add(nebula);

  // 星点：3 层（远暗小点 / 中 / 近层加色混合微闪）
  const dot = createDotTexture();
  const counts = quality === 'low' ? [500, 260, 90] : [1600, 800, 240];
  const layers = [
    { radius: 3600, size: 5.2, opacity: 0.5, color: 0xaebbdd, additive: false },
    { radius: 3300, size: 7.5, opacity: 0.75, color: 0xffffff, additive: false },
    { radius: 3000, size: 9.5, opacity: 0.9, color: 0xcfe4ff, additive: true },
  ];
  const twinkles = [];
  layers.forEach((layer, li) => {
    const rnd = lcg(1000 + li * 77);
    const count = counts[li];
    const positions = new Float32Array(count * 3);
    for (let i = 0; i < count; i++) {
      // 均匀球壳分布
      const u = rnd() * 2 - 1;
      const phi = rnd() * Math.PI * 2;
      const s = Math.sqrt(1 - u * u);
      const r = layer.radius * (0.9 + rnd() * 0.1);
      positions[i * 3] = s * Math.cos(phi) * r;
      positions[i * 3 + 1] = u * r;
      positions[i * 3 + 2] = s * Math.sin(phi) * r;
    }
    const geometry = new THREE.BufferGeometry();
    geometry.setAttribute('position', new THREE.BufferAttribute(positions, 3));
    const material = new THREE.PointsMaterial({
      map: dot,
      size: layer.size,
      sizeAttenuation: true,
      transparent: true,
      opacity: layer.opacity,
      color: layer.color,
      depthWrite: false,
      blending: layer.additive ? THREE.AdditiveBlending : THREE.NormalBlending,
      fog: false,
    });
    const points = new THREE.Points(geometry, material);
    group.add(points);
    if (layer.additive && !reducedMotion) twinkles.push({ material, base: layer.opacity, seed: li * 2.1 });
  });

  scene.add(group);

  return {
    group,
    /** 每帧：星云球跟随相机（无穷远感）+ 近层星点微闪 */
    update(camera, elapsed) {
      group.position.copy(camera.position);
      for (const t of twinkles) {
        t.material.opacity = t.base * (0.75 + 0.25 * Math.sin(elapsed * 1.3 + t.seed));
      }
    },
  };
}
