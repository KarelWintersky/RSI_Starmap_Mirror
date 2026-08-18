import { sphToCart, sysWorld } from './coords.js';

export class Body {
  constructor(system, row) {
    this.system = system;
    this.row = row;
    this.id = row.id;
    this.code = row.code;
    this.name = row.name || null;
    this.type = row.type;
    this.parentId = row.parent_id ?? null;
    this.distance = row.distance ?? 0;
    this.size = row.size ?? 0;
    this.appearance = row.appearance || 'DEFAULT';
    this.shaderData = row.shader_data || null;
    this.textureSource = row.texture?.source || null;
    this.showOrbitlines = !!row.show_orbitlines;
    this.children = [];
    this.local = sphToCart(this.distance, row.latitude ?? 0, row.longitude ?? 0);
    this.worldPos = null;
  }

  get isStar() { return this.type === 'STAR'; }
  get isBlackHole() { return this.type === 'BLACKHOLE'; }
  get isOort() { return this.type === 'OORT'; }
  get isPlanet() { return this.type === 'PLANET'; }
  get isMoon() { return this.type === 'SATELLITE'; }
}

export class SystemModel {
  constructor(store, bootupRow, detail) {
    this.store = store;
    this.bootupRow = bootupRow;
    this.detail = detail;
    this.id = detail.id;
    this.code = detail.code;
    this.name = detail.name;
    this.position = sysWorld(detail.position_x, detail.position_y, detail.position_z, store.galaxyScale);
    this.oortRadius = detail.oort_radius ?? 40;
    this.bodies = [];
    this.bodyByCode = new Map();
    this.bodyById = new Map();
    this.roots = [];
    for (const row of detail.celestial_objects) {
      const b = new Body(this, row);
      this.bodies.push(b);
      this.bodyByCode.set(b.code, b);
      this.bodyById.set(b.id, b);
    }
    for (const b of this.bodies) {
      if (b.parentId != null && this.bodyById.has(b.parentId)) {
        this.bodyById.get(b.parentId).children.push(b);
      } else {
        this.roots.push(b);
      }
    }
    // мировые позиции: дерево от корня вниз
    const place = (body, acc) => {
      body.worldPos = acc.clone().add(body.local);
      for (const c of body.children) place(c, body.worldPos);
    };
    for (const r of this.roots) place(r, this.position.clone());
    this.star = this.bodies.find((b) => b.isStar || b.isBlackHole) || this.roots[0];
  }
}

export class DataStore {
  constructor(apiBase = '/api/starmap') {
    this.apiBase = apiBase;
    this.bootup = null;
    this.config = {};
    this.galaxyScale = 1;
    this.systems = new Map();
  }

  async loadBootup() {
    const json = await (await fetch(`${this.apiBase}/bootup`)).json();
    this.bootup = json.data;
    this.config = this.bootup.config || {};
    this.galaxyScale = this.config.galaxyScale || 1;
    return this.bootup;
  }

  get systemsList() {
    return this.bootup?.systems?.resultset || [];
  }

  get tunnelsList() {
    return this.bootup?.tunnels?.resultset || [];
  }

  async loadSystem(code) {
    if (this.systems.has(code)) return this.systems.get(code);
    const bootupRow = this.systemsList.find((s) => s.code === code);
    const json = await (await fetch(`${this.apiBase}/star-systems/${code}`)).json();
    const detail = json.data.resultset[0];
    const sys = new SystemModel(this, bootupRow, detail);
    this.systems.set(code, sys);
    return sys;
  }
}
