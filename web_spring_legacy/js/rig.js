import * as THREE from 'three';
import { clamp, dampAngle } from './coords.js';

// Камера-риг: орбита вокруг цели (target) со сферическими координатами
// (radius, theta, phi). Программные перелёты flyTo() — плавные, с damping.
export class CameraRig {
  constructor(camera, dom) {
    this.camera = camera;
    this.dom = dom;
    this.target = new THREE.Vector3();
    this.radius = 80;
    this.theta = 0.7;
    this.phi = 1.15;
    this.minRadius = 0.5;
    this.maxRadius = 400;
    this.enableRotate = true;
    this.enableZoom = true;
    this.enablePan = true;
    this.goal = null;
    this._down = null;
    this.bind();
    this.apply();
  }

  setLimits(minR, maxR) {
    this.minRadius = minR;
    this.maxRadius = maxR;
    this.radius = clamp(this.radius, minR, maxR);
  }

  setTarget(t) {
    this.target.copy(t);
    if (this.goal) this.goal = null;
  }

  apply() {
    const { radius, theta, phi, target } = this;
    const sinPhi = Math.sin(phi);
    this.camera.position.set(
      target.x + radius * sinPhi * Math.sin(theta),
      target.y + radius * Math.cos(phi),
      target.z + radius * sinPhi * Math.cos(theta),
    );
    this.camera.lookAt(target);
  }

  update(dt) {
    if (this.goal) {
      const g = this.goal;
      const k = 1 - Math.exp(-9 * dt);
      this.target.lerp(g.target, k);
      this.radius = THREE.MathUtils.damp(this.radius, g.radius, 9, dt);
      this.theta = dampAngle(this.theta, g.theta, 9, dt);
      this.phi = dampAngle(this.phi, g.phi, 9, dt);
      const done =
        Math.abs(this.radius - g.radius) < 0.05 * Math.max(1, g.radius) &&
        this.target.distanceTo(g.target) < 0.05 &&
        Math.abs(dampAngle(this.theta, g.theta, 1, 1e9)) < 0.01 &&
        Math.abs(dampAngle(this.phi, g.phi, 1, 1e9)) < 0.01;
      if (done) {
        const cb = this.goal.onDone;
        this.goal = null;
        cb && cb();
      }
    }
    this.apply();
  }

  flyTo({ target, radius, theta = this.theta, phi = this.phi, onDone } = {}) {
    this.goal = { target: target.clone(), radius, theta, phi, onDone };
  }

  cancelGoal() {
    this.goal = null;
  }

  bind() {
    const dom = this.dom;
    dom.addEventListener('pointerdown', (e) => {
      this._down = { x: e.clientX, y: e.clientY, move: false, button: e.button };
      try {
        dom.setPointerCapture(e.pointerId);
      } catch (_) { /* noop */ }
    });
    dom.addEventListener('pointermove', (e) => {
      const d = this._down;
      if (!d) return;
      const dx = e.clientX - d.x;
      const dy = e.clientY - d.y;
      if (Math.abs(dx) + Math.abs(dy) > 4) d.move = true;
      if (!d.move) return;
      if (d.button === 0 && this.enableRotate) {
        this.cancelGoal();
        this.theta -= dx * 0.008;
        this.phi = clamp(this.phi - dy * 0.008, 0.05, Math.PI - 0.05);
      } else if ((d.button === 1 || d.button === 2) && this.enablePan) {
        this.cancelGoal();
        this.pan(dx, dy);
      }
      d.x = e.clientX;
      d.y = e.clientY;
    });
    dom.addEventListener('pointerup', () => {
      this._down = null;
    });
    dom.addEventListener('wheel', (e) => {
      e.preventDefault();
      if (!this.enableZoom) return;
      this.cancelGoal();
      this.radius = clamp(this.radius * Math.exp(e.deltaY * 0.0012), this.minRadius, this.maxRadius);
    }, { passive: false });
    dom.addEventListener('contextmenu', (e) => e.preventDefault());
  }

  pan(dx, dy) {
    const dir = new THREE.Vector3().subVectors(this.camera.position, this.target).normalize();
    const right = new THREE.Vector3().crossVectors(dir, this.camera.up).normalize();
    const up = new THREE.Vector3().crossVectors(right, dir);
    const worldPerPixel = (2 * Math.tan((this.camera.fov * Math.PI) / 360) * this.radius) / this.dom.clientHeight;
    this.target.addScaledVector(right, -dx * worldPerPixel);
    this.target.addScaledVector(up, dy * worldPerPixel);
  }
}

// Различение одиночного и двойного клика: нативный dblclick + таймер для одиночного.
export class Clicker {
  constructor(dom, { onSingle, onDouble }) {
    this.singleTimer = null;
    this.down = null;
    dom.addEventListener('pointerdown', (e) => {
      if (e.button !== 0) return;
      this.down = { move: false };
    });
    dom.addEventListener('pointermove', () => {
      if (this.down) this.down.move = true;
    });
    dom.addEventListener('pointerup', (e) => {
      if (e.button !== 0) return;
      const d = this.down;
      this.down = null;
      if (!d || d.move) return;
      this.singleTimer = setTimeout(() => {
        onSingle({ x: e.clientX, y: e.clientY, t: performance.now() });
      }, 250);
    });
    dom.addEventListener('dblclick', (e) => {
      clearTimeout(this.singleTimer);
      onDouble(e);
    });
  }
}
