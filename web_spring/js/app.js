import * as THREE from 'three';
import { DataStore } from './data.js';
import { GalaxyScene } from './galaxy.js';
import { SystemScene } from './system.js';
import { CameraRig, Clicker } from './rig.js';
import { State2D, State3D, StateSystem } from './states.js';
import { loadGenericModel } from './models.js';

const HUD = {
  setLevel(text) { document.getElementById('hud-level').textContent = text; },
  setHint(text) { document.getElementById('hud-hint').textContent = text; },
  setSelected(text) {
    const el = document.getElementById('hud-selected');
    el.textContent = text || '';
    el.classList.toggle('on', !!text);
  },
  setHover(text) {
    const el = document.getElementById('hud-hover');
    el.textContent = text || '';
    el.classList.toggle('on', !!text);
  },
  showLoader(text) {
    const el = document.getElementById('loader');
    el.textContent = text || 'Загрузка…';
    el.classList.add('on');
  },
  hideLoader() {
    document.getElementById('loader').classList.remove('on');
  },
};

export class App {
  constructor(dom) {
    this.dom = dom;
    this.hud = HUD;
    this.renderer = new THREE.WebGLRenderer({ antialias: true });
    this.renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
    this.renderer.setSize(window.innerWidth, window.innerHeight);
    dom.appendChild(this.renderer.domElement);

    this.scene = new THREE.Scene();
    this.scene.background = new THREE.Color('#04060e');
    this.scene.add(new THREE.AmbientLight(0x2a3350, 1.1));

    this.camera = new THREE.PerspectiveCamera(55, window.innerWidth / window.innerHeight, 0.05, 3000);
    this.rig = new CameraRig(this.camera, this.renderer.domElement);

    this.raycaster = new THREE.Raycaster();
    this.data = new DataStore();
    this.galaxy = new GalaxyScene(this);
    this.systemScenes = new Map();
    this.state = null;
    this._entering = false;
    this.time = 0;
    this.timer = new THREE.Timer();
    this.timer.connect(document);

    this.clicker = new Clicker(this.renderer.domElement, {
      onSingle: (e) => this.state && this.state.onSingle(e),
      onDouble: (e) => this.state && this.state.onDouble(e),
    });
    this.renderer.domElement.addEventListener('pointermove', (e) => {
      if (this.state) this.state.onHover(e);
    });
    this.renderer.domElement.addEventListener('pointerleave', () => {
      if (this.state) this.state.onHoverEnd();
    });
    document.getElementById('btn-back').addEventListener('click', () => {
      if (this.state) this.state.onBack();
    });
    window.addEventListener('resize', () => this.resize());
  }

  resize() {
    const w = window.innerWidth;
    const h = window.innerHeight;
    this.camera.aspect = w / h;
    this.camera.updateProjectionMatrix();
    this.renderer.setSize(w, h);
  }

  pointerFromEvent(e) {
    const x = e.clientX ?? e.x;
    const y = e.clientY ?? e.y;
    const rect = this.renderer.domElement.getBoundingClientRect();
    return new THREE.Vector2(
      ((x - rect.left) / rect.width) * 2 - 1,
      -((y - rect.top) / rect.height) * 2 + 1,
    );
  }

  raycastGalaxy(e) {
    this.raycaster.setFromCamera(this.pointerFromEvent(e), this.camera);
    const hits = this.raycaster.intersectObjects(this.galaxy.clickables, false);
    return hits[0] || null;
  }

  raycastSystem(e) {
    const sc = this.state && this.state.scene;
    if (!sc) return null;
    this.raycaster.setFromCamera(this.pointerFromEvent(e), this.camera);
    const hits = this.raycaster.intersectObjects(sc.clickables, false);
    return hits[0] || null;
  }

  changeState(next) {
    if (this.state) this.state.onExit();
    this.state = next;
    next.onEnter();
    this.hud.setSelected(null);
    this.hud.setHover(null);
  }

  goGalaxy2D() {
    this.changeState(new State2D(this));
  }

  goGalaxy3D(system) {
    this.changeState(new State3D(this, system));
  }

  async enterSystem(system) {
    if (this._entering) return;
    this._entering = true;
    this.hud.showLoader(`Загрузка: ${system.name}`);
    try {
      const model = await this.data.loadSystem(system.code);
      let sc = this.systemScenes.get(system.code);
      if (!sc) {
        sc = new SystemScene(this, model);
        this.systemScenes.set(system.code, sc);
      }
      this.changeState(new StateSystem(this, sc));
    } catch (err) {
      console.error(err);
    } finally {
      this.hud.hideLoader();
      this._entering = false;
    }
  }

  async start() {
    try {
      await this.data.loadBootup();
      await this.galaxy.build();
      this.loadSkybox();
      this.changeState(new State2D(this));
    } catch (err) {
      console.error(err);
      this.hud.showLoader('Ошибка загрузки данных (нужен php -S web_spring/server.php)');
    }
    this.loop();
  }

  async loadSkybox() {
    const urls = [
      '/static/starmap/models/SpaceCube_Back.dae',
      '/static/starmap/models/SpaceCube_Nebulas.dae',
    ];
    for (const url of urls) {
      try {
        const scene = await loadGenericModel(url);
        if (!scene) continue;
        // make all meshes additive, depthWrite=false (nebula/starfield layers)
        scene.traverse((c) => {
          if (c.isMesh && c.material) {
            c.material.transparent = true;
            c.material.depthWrite = false;
            c.material.side = THREE.DoubleSide;
            c.material.blending = THREE.NormalBlending;
            c.material.needsUpdate = true;
          }
        });
        this.scene.add(scene);
      } catch (err) {
        console.warn(`Skybox ${url} failed:`, err);
      }
    }
  }

  loop() {
    requestAnimationFrame(() => this.loop());
    this.timer.update();
    const dt = Math.min(this.timer.getDelta(), 0.05);
    this.time += dt;
    if (this.state) this.state.update(this.time, dt);
    this.rig.update(dt);
    this.renderer.render(this.scene, this.camera);
  }
}
