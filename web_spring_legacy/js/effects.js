import * as THREE from 'three';

// ─── Glow sprites ───────────────────────────────────────────────────

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

// ─── Ring sprites (jump points) ─────────────────────────────────────

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

// ─── Star shader (animated turbulence + fresnel corona) ─────────────

export function makeStarMaterial(color) {
  const c = new THREE.Color(color);
  return new THREE.ShaderMaterial({
    uniforms: {
      uColor1: { value: c },
      uColor2: { value: new THREE.Color('#ffffff') },
      uTime: { value: 0 },
    },
    vertexShader: `
      varying vec3 vNormal;
      varying vec3 vView;
      varying vec2 vUv;
      void main() {
        vNormal = normalize(normalMatrix * normal);
        vec4 mv = modelViewMatrix * vec4(position, 1.0);
        vView = -mv.xyz;
        vUv = uv;
        gl_Position = projectionMatrix * mv;
      }`,
    fragmentShader: `
      uniform vec3 uColor1;
      uniform vec3 uColor2;
      uniform float uTime;
      varying vec3 vNormal;
      varying vec3 vView;
      varying vec2 vUv;

      // simplex-ish hash
      vec3 hash3(vec3 p) {
        p = fract(p * vec3(443.8975, 397.2973, 491.1871));
        p += dot(p.zxy, p.yxz + 19.19);
        return fract(vec3(p.x * p.y, p.z * p.x, p.y * p.z));
      }

      float snoise(vec3 p) {
        vec3 i = floor(p);
        vec3 f = fract(p);
        f = f * f * (3.0 - 2.0 * f);
        float n = mix(
          mix(mix(dot(hash3(i), f), dot(hash3(i + vec3(1,0,0)), f - vec3(1,0,0)), f.x),
              mix(dot(hash3(i + vec3(0,1,0)), f - vec3(0,1,0)), dot(hash3(i + vec3(1,1,0)), f - vec3(1,1,0)), f.x), f.y),
          mix(mix(dot(hash3(i + vec3(0,0,1)), f - vec3(0,0,1)), dot(hash3(i + vec3(1,0,1)), f - vec3(1,0,1)), f.x),
              mix(dot(hash3(i + vec3(0,1,1)), f - vec3(0,1,1)), dot(hash3(i + vec3(1,1,1)), f - vec3(1,1,1)), f.x), f.y), f.z);
        return n * 1.15;
      }

      void main() {
        vec3 dir = normalize(vNormal);
        float t = uTime * 0.15;

        // animated turbulence on sphere surface
        float n1 = snoise(dir * 3.0 + t);
        float n2 = snoise(dir * 6.0 - t * 1.3) * 0.5;
        float n3 = snoise(dir * 12.0 + t * 0.7) * 0.25;
        float turbulence = (n1 + n2 + n3) * 0.4 + 0.5;

        // base surface color with turbulence
        vec3 surface = mix(uColor1, uColor2, turbulence * 0.6);

        // hot spots (brighter regions)
        float hotspot = pow(max(snoise(dir * 2.0 + t * 0.5), 0.0), 2.0);
        surface += uColor2 * hotspot * 0.4;

        // fresnel corona (rim glow)
        float fresnel = pow(1.0 - abs(dot(normalize(vNormal), normalize(vView))), 3.0);
        vec3 corona = uColor2 * fresnel * 0.6;

        gl_FragColor = vec4(surface + corona, 1.0);
      }`,
  });
}

// ─── Procedural planet textures (canvas noise) ─────────────────────

const texCache = new Map();

function noise2d(x, y) {
  const n = Math.sin(x * 127.1 + y * 311.7) * 43758.5453;
  return n - Math.floor(n);
}

function fbm(x, y, octaves = 5) {
  let val = 0, amp = 0.5, freq = 1;
  for (let i = 0; i < octaves; i++) {
    val += amp * noise2d(x * freq, y * freq);
    amp *= 0.5;
    freq *= 2.0;
  }
  return val;
}

function generatePlanetTexture(appearance, seed = 0) {
  const key = `${appearance}|${seed}`;
  if (texCache.has(key)) return texCache.get(key);

  const w = 512, h = 256;
  const c = document.createElement('canvas');
  c.width = w;
  c.height = h;
  const ctx = c.getContext('2d');
  const img = ctx.createImageData(w, h);
  const d = img.data;
  const sx = seed * 137.5;

  for (let y = 0; y < h; y++) {
    for (let x = 0; x < w; x++) {
      const u = x / w;
      const v = y / h;
      const nx = u * 8 + sx;
      const ny = v * 4 + sx * 0.7;
      let r, g, b;

      switch (appearance) {
        case 'PLANET_BLUE': {
          // ocean world: deep blue base, lighter shallow areas, white cloud swirls
          const depth = fbm(nx, ny, 5);
          const cloud = fbm(nx * 1.5 + 10, ny * 1.5 + 10, 4);
          const isCloud = cloud > 0.62;
          if (isCloud) {
            const cw = (cloud - 0.62) / 0.38;
            r = 180 + cw * 60;
            g = 195 + cw * 50;
            b = 220 + cw * 35;
          } else {
            r = 20 + depth * 40;
            g = 60 + depth * 80;
            b = 140 + depth * 80;
          }
          break;
        }
        case 'PLANET_GREEN': {
          // terrestrial: green/brown land, blue water
          const land = fbm(nx, ny, 6);
          const isLand = land > 0.48;
          if (isLand) {
            const variation = fbm(nx * 3, ny * 3, 3);
            r = 60 + variation * 80;
            g = 100 + variation * 70;
            b = 40 + variation * 30;
          } else {
            const ocean = fbm(nx * 2 + 5, ny * 2 + 5, 3);
            r = 30 + ocean * 30;
            g = 70 + ocean * 50;
            b = 130 + ocean * 60;
          }
          // ice caps
          const lat = Math.abs(v - 0.5) * 2;
          if (lat > 0.82) {
            const ice = (lat - 0.82) / 0.18;
            r = r + (230 - r) * ice;
            g = g + (240 - g) * ice;
            b = b + (250 - b) * ice;
          }
          break;
        }
        case 'PLANET_DEFAULT':
        default: {
          // rocky: grey/brown with crater-like features
          const rock = fbm(nx, ny, 5);
          const crater = fbm(nx * 4 + 20, ny * 4 + 20, 3);
          const craterMask = crater > 0.65 ? (crater - 0.65) / 0.35 : 0;
          r = 100 + rock * 60 - craterMask * 30;
          g = 85 + rock * 50 - craterMask * 25;
          b = 70 + rock * 40 - craterMask * 20;
          // subtle atmospheric haze at edges (limb darkening approx)
          const limb = Math.pow(Math.abs(v - 0.5) * 2, 3);
          r = r * (1 - limb * 0.3);
          g = g * (1 - limb * 0.3);
          b = b * (1 - limb * 0.3);
          break;
        }
      }

      const i = (y * w + x) * 4;
      d[i] = Math.min(255, Math.max(0, r));
      d[i + 1] = Math.min(255, Math.max(0, g));
      d[i + 2] = Math.min(255, Math.max(0, b));
      d[i + 3] = 255;
    }
  }

  ctx.putImageData(img, 0, 0);
  const tex = new THREE.CanvasTexture(c);
  tex.colorSpace = THREE.SRGBColorSpace;
  texCache.set(key, tex);
  return tex;
}

export function planetTexture(appearance, seed) {
  const mapping = {
    PLANET_BLUE: 'PLANET_BLUE',
    PLANET_GREEN: 'PLANET_GREEN',
    PLANET_DEFAULT: 'PLANET_DEFAULT',
    DEFAULT: 'PLANET_DEFAULT',
  };
  return generatePlanetTexture(mapping[appearance] || 'PLANET_DEFAULT', seed);
}

// ─── Enhanced starfield (two layers, size variation) ────────────────

export function makeStarfield(count, radius) {
  const group = new THREE.Group();

  // layer 1: main stars
  const pos1 = new Float32Array(count * 3);
  const size1 = new Float32Array(count);
  for (let i = 0; i < count; i++) {
    const r = radius * (0.55 + Math.random() * 0.45);
    const theta = Math.random() * Math.PI * 2;
    const phi = Math.acos(2 * Math.random() - 1);
    pos1[i * 3] = r * Math.sin(phi) * Math.cos(theta);
    pos1[i * 3 + 1] = r * Math.sin(phi) * Math.sin(theta);
    pos1[i * 3 + 2] = r * Math.cos(phi);
    size1[i] = 0.8 + Math.random() * 1.2;
  }
  const geo1 = new THREE.BufferGeometry();
  geo1.setAttribute('position', new THREE.BufferAttribute(pos1, 3));
  geo1.setAttribute('size', new THREE.BufferAttribute(size1, 1));
  group.add(new THREE.Points(geo1, new THREE.PointsMaterial({
    color: '#a0aad0',
    size: 1.6,
    sizeAttenuation: false,
    transparent: true,
    opacity: 0.9,
    depthWrite: false,
  })));

  // layer 2: faint dust stars (smaller, dimmer)
  const dustCount = Math.floor(count * 0.4);
  const pos2 = new Float32Array(dustCount * 3);
  for (let i = 0; i < dustCount; i++) {
    const r = radius * (0.5 + Math.random() * 0.5);
    const theta = Math.random() * Math.PI * 2;
    const phi = Math.acos(2 * Math.random() - 1);
    pos2[i * 3] = r * Math.sin(phi) * Math.cos(theta);
    pos2[i * 3 + 1] = r * Math.sin(phi) * Math.sin(theta);
    pos2[i * 3 + 2] = r * Math.cos(phi);
  }
  const geo2 = new THREE.BufferGeometry();
  geo2.setAttribute('position', new THREE.BufferAttribute(pos2, 3));
  group.add(new THREE.Points(geo2, new THREE.PointsMaterial({
    color: '#6a7090',
    size: 0.9,
    sizeAttenuation: false,
    transparent: true,
    opacity: 0.5,
    depthWrite: false,
  })));

  return group;
}

// ─── Orbit lines ────────────────────────────────────────────────────

export function makeOrbitLine(radius, color = '#3a4a6a', opacity = 0.5, segments = 128) {
  const pts = [];
  for (let i = 0; i <= segments; i++) {
    const a = (i / segments) * Math.PI * 2;
    pts.push(new THREE.Vector3(Math.cos(a) * radius, 0, Math.sin(a) * radius));
  }
  const geo = new THREE.BufferGeometry().setFromPoints(pts);
  return new THREE.Line(geo, new THREE.LineBasicMaterial({ color, transparent: true, opacity, depthWrite: false }));
}

// ─── Atmosphere (fresnel edge glow) ─────────────────────────────────

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

// ─── Black hole accretion disk ──────────────────────────────────────

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

// ─── Asteroid belt / field ──────────────────────────────────────────

export function makeBelt(radius, width, count = 400, color = '#7a6a5a') {
  const pos = new Float32Array(count * 3);
  const sizes = new Float32Array(count);
  for (let i = 0; i < count; i++) {
    const r = radius + (Math.random() - 0.5) * width;
    const a = Math.random() * Math.PI * 2;
    const y = (Math.random() - 0.5) * width * 0.18;
    pos[i * 3] = Math.cos(a) * r;
    pos[i * 3 + 1] = y;
    pos[i * 3 + 2] = Math.sin(a) * r;
    sizes[i] = 0.08 + Math.random() * 0.16;
  }
  const geo = new THREE.BufferGeometry();
  geo.setAttribute('position', new THREE.BufferAttribute(pos, 3));
  return new THREE.Points(geo, new THREE.PointsMaterial({
    color,
    size: 0.18,
    transparent: true,
    opacity: 0.75,
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

// ─── Oort cloud shell ──────────────────────────────────────────────

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
