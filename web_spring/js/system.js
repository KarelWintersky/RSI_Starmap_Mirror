import * as THREE from 'three';
import { clamp, lerp } from './coords.js';
import {
  makeAtmosphereMesh,
  makeBelt,
  makeBlackHoleDisk,
  makeField,
  makeGlowSprite,
  makeOortShell,
  makeOrbitLine,
  makeRingSprite,
  makeStarMaterial,
  planetTexture,
} from './effects.js';
import { loadPlanetModel, loadGenericModel, MODEL_MAP, TEXTURE_MAP } from './models.js';

// Система: тела (звёзды, планеты, спутники, гиперканалы, пояса, ЧД, OORT),
// орбиты, подписи, анимации. Позиции — мировые (тела вокруг system.position).
export class SystemScene {
  constructor(app, system) {
    this.app = app;
    this.system = system;
    this.group = new THREE.Group();
    this.group.visible = false;
    app.scene.add(this.group);
    this.clickables = [];
    this.animations = [];
    this._hovered = null;
    this.textureLoader = new THREE.TextureLoader();
    this.build();
  }

  show() {
    this.group.visible = true;
  }

  hide() {
    this.group.visible = false;
    this.clearHover();
  }

  planetRadii() {
    const planets = this.system.bodies.filter((b) => b.isPlanet);
    let min = Infinity;
    let max = -Infinity;
    for (const p of planets) {
      min = Math.min(min, p.size);
      max = Math.max(max, p.size);
    }
    return { min, max };
  }

  bodyRadius(b, pr) {
    switch (b.type) {
      case 'STAR': return clamp(this.system.oortRadius * 0.004, 0.08, 0.25);
      case 'BLACKHOLE': return 0.12;
      case 'PLANET': {
        if (pr.min === pr.max) return 0.12;
        const n = (b.size - pr.min) / (pr.max - pr.min);
        const r = lerp(0.08, 0.4, Math.pow(n, 0.8));
        const orbit = b.worldPos.distanceTo(this.system.star.worldPos);
        return Math.min(r, Math.max(orbit * 0.22, 0.06));
      }
      case 'SATELLITE': return clamp(b.size / 4000, 0.04, 0.14);
      case 'MANMADE': return clamp(b.size * 0.01, 0.1, 0.5);
      case 'JUMPPOINT': return 0.6;
      case 'LZ': return 0.2;
      case 'POI': return 0.3;
      case 'ANOMALY': return 0.7;
      default: return 0.5;
    }
  }

  addClick(obj, body, radius) {
    if (radius) {
      const cs = new THREE.Mesh(
        new THREE.SphereGeometry(radius, 12, 12),
        new THREE.MeshBasicMaterial({ transparent: true, opacity: 0, depthWrite: false }),
      );
      cs.position.copy(obj.position);
      cs.userData.body = body;
      this.clickables.push(cs);
      this.group.add(cs);
      return;
    }
    obj.userData.body = body;
    this.clickables.push(obj);
  }

  build() {
    const pr = this.planetRadii();
    const star = this.system.star;

    for (const b of this.system.bodies) {
      const r = this.bodyRadius(b, pr);
      b.clickRadius = Math.max(r, 0.4);
      switch (b.type) {
        case 'STAR': this.buildStar(b, r); break;
        case 'BLACKHOLE': this.buildBlackHole(b, r); break;
        case 'PLANET': this.buildPlanet(b, r); break;
        case 'SATELLITE': this.buildPlanet(b, r); break;
        case 'MANMADE': this.buildManmade(b, r); break;
        case 'JUMPPOINT': this.buildJumpPoint(b, r); break;
        case 'ASTEROID_BELT': this.buildBelt(b); break;
        case 'ASTEROID_FIELD': this.buildField(b); break;
        case 'ANOMALY': this.buildAnomaly(b); break;
        case 'LZ': this.buildLZ(b); break;
        case 'POI': this.buildPOI(b); break;
        case 'OORT': break; // отключено: огромная синяя сфера при отдалении
      }
      if (b.showOrbitlines && b.distance > 0) {
        const center = b.parentId != null && this.system.bodyById.has(b.parentId)
          ? this.system.bodyById.get(b.parentId).worldPos
          : this.system.position;
        const line = makeOrbitLine(b.distance, '#3a4a6a', 0.4);
        line.position.copy(center);
        this.group.add(line);
      }
    }

    if (star && star.isStar) {
      const color = star.shaderData?.sun?.color1 || '#ffe9a0';
      const light = new THREE.PointLight(new THREE.Color(color), 1.2, this.system.oortRadius * 1.6);
      light.position.copy(star.worldPos);
      this.group.add(light);
    }
  }

  sunColor(b) {
    return b.shaderData?.sun?.color1 || '#ffe9a0';
  }

  buildStar(b, r) {
    const color = this.sunColor(b);
    const mat = makeStarMaterial(color);
    const mesh = new THREE.Mesh(new THREE.SphereGeometry(r, 48, 48), mat);
    mesh.position.copy(b.worldPos);
    this.group.add(mesh);
    this.starMesh = mesh;
    this.animations.push((t) => { mat.uniforms.uTime.value = t; });

    const core = makeGlowSprite(color, r * 6);
    core.position.copy(b.worldPos);
    this.group.add(core);
    const hsl = {};
    const cc = new THREE.Color(color);
    cc.getHSL(hsl);
    cc.setHSL(hsl.h, Math.max(0, hsl.s - 0.1), Math.min(1, hsl.l + 0.2));
    const corona = makeGlowSprite('#' + cc.getHexString(), r * 12);
    corona.position.copy(b.worldPos);
    this.group.add(corona);
    this.addClick(mesh, b, r * 1.8);
  }

  buildBlackHole(b, r) {
    // placeholder sphere (instant), DAE model loads async
    const mesh = new THREE.Mesh(
      new THREE.SphereGeometry(r, 32, 32),
      new THREE.MeshBasicMaterial({ color: '#000000' }),
    );
    mesh.position.copy(b.worldPos);
    this.group.add(mesh);
    this.addClick(mesh, b, r * 2);

    const disk = makeBlackHoleDisk(r);
    disk.mesh.position.copy(b.worldPos);
    this.group.add(disk.mesh);
    this.animations.push(disk.update);
    const glow = makeGlowSprite('#3a1d66', r * 6);
    glow.position.copy(b.worldPos);
    this.group.add(glow);

    // load DAE model, replace placeholder
    if (MODEL_MAP.BLACKHOLE) {
      loadGenericModel(MODEL_MAP.BLACKHOLE).then((scene) => {
        if (!scene) return;
        const box = new THREE.Box3().setFromObject(scene);
        const size = box.getSize(new THREE.Vector3());
        const maxDim = Math.max(size.x, size.y, size.z);
        if (maxDim > 0) scene.scale.setScalar((r * 2) / maxDim);
        scene.position.copy(b.worldPos);
        this.group.remove(mesh);
        this.group.add(scene);
        // re-wire click target
        scene.traverse((c) => { if (c.isMesh) c.userData.body = b; });
      });
    }
  }

  buildPlanet(b, r) {
    // placeholder sphere with procedural texture (instant)
    const seed = b.id * 7 + (b.row.longitude || 0);
    const tex = planetTexture(b.appearance, seed);
    const mat = new THREE.MeshStandardMaterial({
      map: tex,
      roughness: 0.85,
      metalness: 0.05,
    });
    const mesh = new THREE.Mesh(new THREE.SphereGeometry(r, 32, 32), mat);
    mesh.position.copy(b.worldPos);
    mesh.rotation.y = (b.row.longitude || 0) * Math.PI / 180;
    this.group.add(mesh);
    this.addClick(mesh, b, Math.max(r * 1.6, 0.6));

    // atmosphere for blue/green planets
    if (b.isPlanet && (b.appearance === 'PLANET_BLUE' || b.appearance === 'PLANET_GREEN')) {
      const atm = makeAtmosphereMesh(r, b.appearance === 'PLANET_BLUE' ? '#4aa8ff' : '#6fbf6a');
      atm.position.copy(b.worldPos);
      this.group.add(atm);
    }

    // load DAE model async, replace placeholder
    const modelUrl = MODEL_MAP[b.appearance];
    const texUrl = TEXTURE_MAP[b.appearance];
    if (modelUrl) {
      loadPlanetModel(b.appearance, r, this.textureLoader).then((daeScene) => {
        if (!daeScene) return;
        daeScene.position.copy(b.worldPos);
        daeScene.rotation.y = (b.row.longitude || 0) * Math.PI / 180;
        this.group.remove(mesh);
        this.group.add(daeScene);
        // re-wire click target
        daeScene.traverse((c) => { if (c.isMesh) c.userData.body = b; });
      });
    }
  }

  buildManmade(b, r) {
    // placeholder
    const mesh = new THREE.Mesh(
      new THREE.OctahedronGeometry(r, 0),
      new THREE.MeshStandardMaterial({ color: '#c8d2e4', roughness: 0.6, metalness: 0.5 }),
    );
    mesh.position.copy(b.worldPos);
    this.group.add(mesh);
    const glow = makeGlowSprite('#9fb6ff', r * 4);
    glow.position.copy(b.worldPos);
    this.group.add(glow);
    this.addClick(mesh, b, r * 1.6);

    // load SpaceStation.dae async
    if (MODEL_MAP.MANMADE) {
      loadGenericModel(MODEL_MAP.MANMADE).then((scene) => {
        if (!scene) return;
        const box = new THREE.Box3().setFromObject(scene);
        const size = box.getSize(new THREE.Vector3());
        const maxDim = Math.max(size.x, size.y, size.z);
        if (maxDim > 0) scene.scale.setScalar((r * 2) / maxDim);
        scene.position.copy(b.worldPos);
        this.group.remove(mesh);
        this.group.add(scene);
        scene.traverse((c) => { if (c.isMesh) c.userData.body = b; });
      });
    }
  }

  buildJumpPoint(b, r) {
    // ring sprite (instant)
    const ring = makeRingSprite('#55ddff', 0.9);
    ring.position.copy(b.worldPos);
    this.group.add(ring);
    const glow = makeGlowSprite('#66ccff', 0.5);
    glow.position.copy(b.worldPos);
    this.group.add(glow);
    this.addClick(ring, b);

    // load JumpHead.dae async
    if (MODEL_MAP.JUMPPOINT) {
      loadGenericModel(MODEL_MAP.JUMPPOINT).then((scene) => {
        if (!scene) return;
        const box = new THREE.Box3().setFromObject(scene);
        const size = box.getSize(new THREE.Vector3());
        const maxDim = Math.max(size.x, size.y, size.z);
        if (maxDim > 0) scene.scale.setScalar((r * 1.5) / maxDim);
        scene.position.copy(b.worldPos);
        this.group.add(scene);
      });
    }
  }

  buildBelt(b) {
    const belt = makeBelt(b.distance, Math.max(b.size, 0.6), 500, '#8a7460');
    belt.position.copy(this.system.position);
    this.group.add(belt);
  }

  buildField(b) {
    const field = makeField(b.worldPos, 1.6, 300, '#7a6688');
    this.group.add(field);
  }

  buildAnomaly(b) {
    const base = 2.2;
    const sprite = makeGlowSprite('#8a5cff', base);
    sprite.position.copy(b.worldPos);
    this.group.add(sprite);
    const phase = Math.random() * Math.PI * 2;
    this.animations.push((t) => {
      const s = base * (0.65 + 0.45 * Math.sin(t * 2.2 + phase));
      sprite.scale.setScalar(s);
    });
    this.addClick(sprite, b);
  }

  buildLZ(b) {
    const marker = makeGlowSprite('#41e05a', 1.0);
    marker.position.copy(b.worldPos);
    this.group.add(marker);
    this.addClick(marker, b);
  }

  buildPOI(b) {
    const marker = makeGlowSprite('#e0b04a', 1.4);
    marker.position.copy(b.worldPos);
    this.group.add(marker);
    this.addClick(marker, b);
  }

  buildOort(b) {
    const shell = makeOortShell(b.distance || this.system.oortRadius);
    shell.mesh.position.copy(this.system.position);
    this.group.add(shell.mesh);
    this.animations.push(shell.update);
  }

  setHover(body) {
    if (this._hovered === body) return this._hoveredName;
    this.clearHover();
    if (!body) return null;
    this._hovered = body;
    this._hoveredName = body.name || body.row.designation || body.code;
    return this._hoveredName;
  }

  clearHover() {
    if (!this._hovered) return;
    this._hovered = null;
    this._hoveredName = null;
  }

  update(t) {
    for (const a of this.animations) a(t);
  }
}
