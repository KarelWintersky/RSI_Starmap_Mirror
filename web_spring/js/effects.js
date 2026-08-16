import * as THREE from 'three';

const glowCache = new Map();

export function glowTexture(color, core) {
  const key = `${color}|${core}`;
  if (glowCache.has(key)) return glowCache.get(key);
  const size = 256;
  const c = document.createElement('canvas');
  c.width = c.height = size;
  const ctx = c.getContext('2d');
  const g = ctx.createRadialGradient(size / 2, size / 2, 0, size / 2, size / 2, size / 2);
  g.addColorStop(0, core || color);
  g.addColorStop(0.3, color);
  g.addColorStop(1, 'rgba(0,0,0,0)');
  ctx.fillStyle = g;
  ctx.fillRect(0, 0, size, size);
  const tex = new THREE.CanvasTexture(c);
  glowCache.set(key, tex);
  return tex;
}

export function makeGlowSprite(color, scale) {
  const mat = new THREE.SpriteMaterial({
    map: glowTexture(color),
    blending: THREE.AdditiveBlending,
    depthWrite: false,
    transparent: true,
  });
  const s = new THREE.Sprite(mat);
  s.scale.setScalar(scale);
  return s;
}

const ringCache = new Map();

export function ringTexture(color) {
  if (ringCache.has(color)) return ringCache.get(color);
  const size = 128;
  const c = document.createElement('canvas');
  c.width = c.height = size;
  const ctx = c.getContext('2d');
  const g = ctx.createRadialGradient(size / 2, size / 2, size * 0.25, size / 2, size / 2, size * 0.5);
  g.addColorStop(0, 'rgba(0,0,0,0)');
  g.addColorStop(0.6, color);
  g.addColorStop(1, 'rgba(0,0,0,0)');
  ctx.fillStyle = g;
  ctx.fillRect(0, 0, size, size);
  const tex = new THREE.CanvasTexture(c);
  ringCache.set(color, tex);
  return tex;
}

export function makeRingSprite(color, scale) {
  const mat = new THREE.SpriteMaterial({
    map: ringTexture(color),
    blending: THREE.AdditiveBlending,
    depthWrite: false,
    transparent: true,
  });
  const s = new THREE.Sprite(mat);
  s.scale.setScalar(scale);
  return s;
}

export function makeLabelSprite(text, opts = {}) {
  const { fontSize = 44, color = '#cfe3ff', height = 5 } = opts;
  const c = document.createElement('canvas');
  const ctx = c.getContext('2d');
  ctx.font = `600 ${fontSize}px sans-serif`;
  const w = Math.ceil(ctx.measureText(text).width) + 16;
  c.width = w;
  c.height = fontSize + 12;
  ctx.font = `600 ${fontSize}px sans-serif`;
  ctx.fillStyle = 'rgba(6, 10, 22, 0.55)';
  ctx.fillRect(0, 0, w, c.height);
  ctx.fillStyle = color;
  ctx.textBaseline = 'middle';
  ctx.fillText(text, 8, c.height / 2);
  const mat = new THREE.SpriteMaterial({ map: new THREE.CanvasTexture(c), depthWrite: false, transparent: true });
  const s = new THREE.Sprite(mat);
  s.scale.set((height * w) / c.height, height, 1);
  return s;
}

export function makeStarfield(count, radius) {
  const pos = new Float32Array(count * 3);
  for (let i = 0; i < count; i++) {
    const r = radius * (0.55 + Math.random() * 0.45);
    const theta = Math.random() * Math.PI * 2;
    const phi = Math.acos(2 * Math.random() - 1);
    pos[i * 3] = r * Math.sin(phi) * Math.cos(theta);
    pos[i * 3 + 1] = r * Math.sin(phi) * Math.sin(theta);
    pos[i * 3 + 2] = r * Math.cos(phi);
  }
  const geo = new THREE.BufferGeometry();
  geo.setAttribute('position', new THREE.BufferAttribute(pos, 3));
  const mat = new THREE.PointsMaterial({
    color: '#8a93b5',
    size: 1.4,
    sizeAttenuation: false,
    transparent: true,
    opacity: 0.85,
    depthWrite: false,
  });
  return new THREE.Points(geo, mat);
}

export function makeOrbitLine(radius, color = '#3a4a6a', opacity = 0.5, segments = 128) {
  const pts = [];
  for (let i = 0; i <= segments; i++) {
    const a = (i / segments) * Math.PI * 2;
    pts.push(new THREE.Vector3(Math.cos(a) * radius, 0, Math.sin(a) * radius));
  }
  const geo = new THREE.BufferGeometry().setFromPoints(pts);
  return new THREE.Line(geo, new THREE.LineBasicMaterial({ color, transparent: true, opacity, depthWrite: false }));
}

export function makeAtmosphereMesh(radius, color = '#4aa8ff') {
  const geo = new THREE.SphereGeometry(radius * 1.03, 32, 32);
  const mat = new THREE.ShaderMaterial({
    transparent: true,
    depthWrite: false,
    blending: THREE.AdditiveBlending,
    side: THREE.BackSide,
    uniforms: {
      uColor: { value: new THREE.Color(color) },
      uIntensity: { value: 0.7 },
    },
    vertexShader: `
      varying vec3 vNormal;
      varying vec3 vView;
      void main() {
        vNormal = normalize(normalMatrix * normal);
        vec4 mv = modelViewMatrix * vec4(position, 1.0);
        vView = -mv.xyz;
        gl_Position = projectionMatrix * mv;
      }`,
    fragmentShader: `
      uniform vec3 uColor;
      uniform float uIntensity;
      varying vec3 vNormal;
      varying vec3 vView;
      void main() {
        float fresnel = pow(1.0 - abs(dot(normalize(vNormal), normalize(vView))), 2.5);
        gl_FragColor = vec4(uColor, fresnel * uIntensity);
      }`,
  });
  return new THREE.Mesh(geo, mat);
}

export function makeBlackHoleDisk(radius) {
  const geo = new THREE.RingGeometry(radius * 1.5, radius * 5, 72);
  const mat = new THREE.ShaderMaterial({
    transparent: true,
    depthWrite: false,
    blending: THREE.AdditiveBlending,
    side: THREE.DoubleSide,
    uniforms: {
      uColor: { value: new THREE.Color('#ff9a3c') },
      uTime: { value: 0 },
    },
    vertexShader: `
      varying vec2 vUv;
      void main() {
        vUv = uv;
        gl_Position = projectionMatrix * modelViewMatrix * vec4(position, 1.0);
      }`,
    fragmentShader: `
      uniform vec3 uColor;
      uniform float uTime;
      varying vec2 vUv;
      void main() {
        vec2 p = vUv * 2.0 - 1.0;
        float r = length(p);
        float streak = 0.55 + 0.45 * sin(90.0 * p.x + uTime * 2.0);
        float a = smoothstep(0.0, 0.14, r) * smoothstep(1.0, 0.65, r);
        gl_FragColor = vec4(uColor * streak, a * 0.85);
      }`,
  });
  const mesh = new THREE.Mesh(geo, mat);
  return {
    mesh,
    update(t) {
      mat.uniforms.uTime.value = t;
      mesh.rotation.z += 0.001;
    },
  };
}

export function makeBelt(radius, width, count = 400, color = '#7a6a5a') {
  const pos = new Float32Array(count * 3);
  for (let i = 0; i < count; i++) {
    const r = radius + (Math.random() - 0.5) * width;
    const a = Math.random() * Math.PI * 2;
    const y = (Math.random() - 0.5) * width * 0.18;
    pos[i * 3] = Math.cos(a) * r;
    pos[i * 3 + 1] = y;
    pos[i * 3 + 2] = Math.sin(a) * r;
  }
  const geo = new THREE.BufferGeometry();
  geo.setAttribute('position', new THREE.BufferAttribute(pos, 3));
  return new THREE.Points(geo, new THREE.PointsMaterial({
    color,
    size: 0.16,
    transparent: true,
    opacity: 0.7,
    depthWrite: false,
  }));
}

export function makeField(center, radius, count = 300, color = '#6a5a7a') {
  const pos = new Float32Array(count * 3);
  for (let i = 0; i < count; i++) {
    pos[i * 3] = center.x + (Math.random() - 0.5) * 2 * radius;
    pos[i * 3 + 1] = center.y + (Math.random() - 0.5) * 2 * radius * 0.4;
    pos[i * 3 + 2] = center.z + (Math.random() - 0.5) * 2 * radius;
  }
  const geo = new THREE.BufferGeometry();
  geo.setAttribute('position', new THREE.BufferAttribute(pos, 3));
  return new THREE.Points(geo, new THREE.PointsMaterial({
    color,
    size: 0.14,
    transparent: true,
    opacity: 0.6,
    depthWrite: false,
  }));
}

export function makeOortShell(radius, count = 700) {
  const pos = new Float32Array(count * 3);
  for (let i = 0; i < count; i++) {
    const a = Math.random() * Math.PI * 2;
    const phi = Math.acos(2 * Math.random() - 1);
    const r = radius * (0.96 + Math.random() * 0.08);
    pos[i * 3] = r * Math.sin(phi) * Math.cos(a);
    pos[i * 3 + 1] = r * Math.sin(phi) * Math.sin(a);
    pos[i * 3 + 2] = r * Math.cos(phi);
  }
  const geo = new THREE.BufferGeometry();
  geo.setAttribute('position', new THREE.BufferAttribute(pos, 3));
  const mesh = new THREE.Points(geo, new THREE.PointsMaterial({
    color: '#40506a',
    size: 0.6,
    transparent: true,
    opacity: 0.22,
    depthWrite: false,
  }));
  return { mesh, update() { mesh.rotation.y += 0.0001; } };
}
