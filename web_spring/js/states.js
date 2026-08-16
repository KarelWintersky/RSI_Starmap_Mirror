// Состояния (уровни) SpringGalaxy: 2D-карта → 3D-галактика → звёздная система.

export class State {
  constructor(app) {
    this.app = app;
  }
  onEnter() {}
  onExit() {}
  update(_t, _dt) {}
  onSingle(_e) {}
  onDouble(_e) {}
  onHover(_e) {}
  onHoverEnd() {}
  onBack() {}
}

export class State2D extends State {
  onEnter() {
    const g = this.app.galaxy;
    g.show();
    g.setLabelsVisible(true);
    this.app.rig.enableRotate = false;
    this.app.rig.setLimits(g.viewRadius2D * 0.12, g.viewRadius2D * 2);
    this.app.hud.setLevel('Карта галактики');
    this.app.hud.setHint('двойной клик по звезде — перейти в 3D');
    this.app.rig.flyTo({
      target: g.center,
      radius: g.viewRadius2D,
      theta: 0,
      phi: 0.06,
    });
  }

  onHover(e) {
    const hit = this.app.raycastGalaxy(e);
    this.app.galaxy.setHover(hit ? hit.object.userData.system.code : null);
  }

  onHoverEnd() {
    this.app.galaxy.clearHover();
  }

  onDouble(e) {
    const hit = this.app.raycastGalaxy(e);
    if (hit) this.app.goGalaxy3D(hit.object);
  }

  onBack() {
    // верхний уровень — никуда выше
  }
}

export class State3D extends State {
  constructor(app, system = null) {
    super(app);
    this.focusSystem = system;
  }

  onEnter() {
    const g = this.app.galaxy;
    g.show();
    g.setLabelsVisible(false);
    this.app.rig.enableRotate = true;
    this.app.rig.setTarget(g.center);
    this.app.rig.setLimits(g.viewRadius3D * 0.05, g.viewRadius3D * 2.5);
    this.app.hud.setLevel('Галактика');
    this.app.hud.setHint('клик по звезде — войти в систему');
    this.app.rig.flyTo({
      target: this.focusSystem ? this.focusSystem.position : g.center,
      radius: g.viewRadius3D,
      theta: 0.7,
      phi: 1.1,
    });
  }

  onHover(e) {
    const hit = this.app.raycastGalaxy(e);
    this.app.galaxy.setHover(hit ? hit.object.userData.system.code : null);
  }

  onHoverEnd() {
    this.app.galaxy.clearHover();
  }

  onSingle(e) {
    const hit = this.app.raycastGalaxy(e);
    if (hit) this.app.enterSystem(hit.object.userData.system);
  }

  onDouble(e) {
    this.onSingle(e);
  }

  onBack() {
    this.app.goGalaxy2D();
  }
}

export class StateSystem extends State {
  constructor(app, scene) {
    super(app);
    this.scene = scene;
    this.system = scene.system;
  }

  onEnter() {
    this.scene.show();
    this.app.galaxy.hide();
    this.app.rig.enableRotate = true;
    this.app.rig.setLimits(0.5, this.system.oortRadius * 1.15);
    this.app.hud.setLevel(this.system.name);
    this.app.hud.setHint('клик по объекту — приблизить · двойной клик по пустоте — отдалить');
    const start = Math.max(this.system.oortRadius * 0.15, 4);
    this.app.rig.flyTo({
      target: this.system.star.worldPos,
      radius: start,
    });
  }

  onExit() {
    this.scene.hide();
  }

  update(t, _dt) {
    this.scene.update(t);
  }

  onHover(e) {
    const hit = this.app.raycastSystem(e);
    this.scene.setHover(hit ? hit.object.userData.body : null);
  }

  onHoverEnd() {
    this.scene.clearHover();
  }

  onSingle(e) {
    const hit = this.app.raycastSystem(e);
    if (hit) {
      this.focusBody(hit.object.userData.body);
    }
  }

  onDouble(e) {
    const hit = this.app.raycastSystem(e);
    if (!hit) {
      // zoom out в пределах OORT
      const target = this.system.position.clone().lerp(this.app.rig.target, 0.5);
      this.app.rig.flyTo({
        target,
        radius: Math.min(this.app.rig.radius * 2.4, this.system.oortRadius * 1.1),
      });
    } else {
      this.focusBody(hit.object.userData.body);
    }
  }

  focusBody(body) {
    const r = Math.max(body.clickRadius * 6, 0.8);
    this.app.rig.flyTo({ target: body.worldPos, radius: r });
    this.app.hud.setSelected(this.bodyTitle(body));
  }

  bodyTitle(body) {
    const name = body.name || body.row.designation || body.code;
    return String(name);
  }

  onBack() {
    this.app.goGalaxy3D();
  }
}
