import * as THREE from 'three';
import { sysWorld } from './coords.js';
import { makeGlowSprite, makeLabelSprite, makeStarfield } from './effects.js';

// Галактика: спрайты систем, подписи, линии туннелей, звёздное поле фона.
export class GalaxyScene {
  constructor(app) {
    this.app = app;
    this.group = new THREE.Group();
    app.scene.add(this.group);
    this.clickables = [];
    this.systemSprites = new Map();
    this.systemLabels = new Map();
    this.center = new THREE.Vector3();
    this.viewRadius2D = 100;
    this.viewRadius3D = 150;
    this.starfield = null;
    this._hovered = null;
  }

  async build() {
    const cfg = this.app.data.config;
    const systems = this.app.data.systemsList;
    if (!systems.length) return;

    const pos = new Map();
    let maxDist = 1;
    const acc = new THREE.Vector3();
    for (const s of systems) {
      const p = sysWorld(s.position_x, s.position_y, s.position_z, this.app.data.galaxyScale);
      pos.set(s.code, p);
      acc.add(p);
    }
    this.center.copy(acc).divideScalar(systems.length);
    for (const p of pos.values()) {
      maxDist = Math.max(maxDist, p.distanceTo(this.center));
    }
    this.viewRadius2D = maxDist * 1.25;
    this.viewRadius3D = maxDist * 1.6;

    this.starfield = makeStarfield(
      cfg.starfield?.count || 1500,
      cfg.starfield?.radius || maxDist * 2.5,
    );
    this.app.scene.add(this.starfield);

    const starSize = cfg.galaxyStarSize || 2.5;
    for (const s of systems) {
      const p = pos.get(s.code);
      const color = s.affiliation?.[0]?.color || '#cfd8ff';
      const sprite = makeGlowSprite(color, starSize);
      sprite.position.copy(p);
      sprite.userData.system = s;
      sprite.userData.baseScale = starSize;
      this.group.add(sprite);
      this.systemSprites.set(s.code, sprite);
      this.clickables.push(sprite);

      const label = makeLabelSprite(s.name.toUpperCase(), { color: '#cfe3ff', height: 4 });
      label.position.copy(p);
      label.position.y += starSize * 0.8;
      label.visible = false;
      this.group.add(label);
      this.systemLabels.set(s.code, label);
    }

    for (const t of this.app.data.tunnelsList) {
      const a = this.systemSprites.get(this.systemCodeOf(t.entry?.code));
      const b = this.systemSprites.get(this.systemCodeOf(t.exit?.code));
      if (!a || !b) continue;
      const geo = new THREE.BufferGeometry().setFromPoints([a.position, b.position]);
      const line = new THREE.Line(
        geo,
        new THREE.LineBasicMaterial({ color: '#35506e', transparent: true, opacity: 0.35, depthWrite: false }),
      );
      this.group.add(line);
    }
  }

  systemCodeOf(jpCode) {
    return jpCode ? jpCode.split('.')[0] : null;
  }

  show() {
    this.group.visible = true;
  }

  hide() {
    this.group.visible = false;
    this.clearHover();
  }

  setLabelsVisible(v) {
    for (const l of this.systemLabels.values()) l.visible = v;
  }

  setHover(systemCode) {
    if (this._hovered === systemCode) return;
    this.clearHover();
    if (!systemCode) return;
    const sprite = this.systemSprites.get(systemCode);
    const label = this.systemLabels.get(systemCode);
    if (sprite) {
      sprite.scale.setScalar(sprite.userData.baseScale * 1.45);
      this._hovered = systemCode;
    }
    if (label) label.visible = true;
  }

  clearHover() {
    if (!this._hovered) return;
    const sprite = this.systemSprites.get(this._hovered);
    if (sprite) sprite.scale.setScalar(sprite.userData.baseScale);
    const label = this.systemLabels.get(this._hovered);
    if (label) label.visible = false;
    this._hovered = null;
  }
}
