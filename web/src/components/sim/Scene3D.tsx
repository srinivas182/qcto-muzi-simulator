import { useEffect, useRef } from 'react';
import {
  BoxGeometry, Color, CylinderGeometry, DirectionalLight, Group, HemisphereLight, Mesh, MeshLambertMaterial, type Object3D,
  PerspectiveCamera, PointLight, Raycaster, Scene, TorusGeometry, Vector2, WebGLRenderer,
} from 'three';
import type { SimEffect } from '@simuwork/core';

/**
 * The PC bench in real-time 3D (three.js, loaded only when a simulator opens; budget 150 KB gzip).
 *
 * Built from simple shapes, so the scene itself costs no download. It renders only when something changes,
 * which keeps budget phones cool and their batteries alive. Drag to look around, tap an object to use it.
 */
export default function Scene3D({ scene, holding, effect, onTap, label }: {
  scene: Record<string, string>; holding: string | null; effect: { kind: SimEffect; key: number } | null;
  onTap: (id: string) => void; label: string;
}) {
  const canvas = useRef<HTMLCanvasElement>(null);
  const world = useRef<Bench | null>(null);
  const tap = useRef(onTap);
  useEffect(() => { tap.current = onTap; }, [onTap]);

  useEffect(() => {
    const bench = new Bench(canvas.current!, (id) => tap.current(id));
    world.current = bench;
    return () => bench.dispose();
  }, []);
  useEffect(() => { world.current?.apply(scene, holding); }, [scene, holding]);
  useEffect(() => { if (effect) world.current?.flash(effect.kind); }, [effect]);

  return <canvas ref={canvas} role="img" aria-label={label} className="block h-full w-full touch-none select-none" />;
}

const mat = (c: number) => new MeshLambertMaterial({ color: c });
function box(w: number, h: number, d: number, color: number, id?: string): Mesh {
  const m = new Mesh(new BoxGeometry(w, h, d), mat(color));
  if (id) m.userData.id = id;
  return m;
}

/** The scene, kept outside React: React only tells it the state of each object. */
class Bench {
  private renderer: WebGLRenderer;
  private camera = new PerspectiveCamera(42, 1, 0.1, 20);
  private scene = new Scene();
  private obj = {} as Record<'wall-switch' | 'case-panel' | 'ram-old' | 'ram-fitted' | 'ram-new' | 'strap' | 'monitor' | 'pc-power' | 'slot-a1' | 'screwdriver' | 'psu-cable', Object3D>;
  private screen: Mesh;
  private flashLight = new PointLight(0xffa040, 0, 3);
  private yaw = 0;
  private zoom = 1;
  private frame = 0;
  private flashUntil = 0;
  private resize: ResizeObserver;
  private down: { x: number; y: number; yaw: number } | null = null;
  private moved = false;
  private readonly cleanup: () => void;
  private readonly canvas: HTMLCanvasElement;
  private readonly onTap: (id: string) => void;

  constructor(canvas: HTMLCanvasElement, onTap: (id: string) => void) {
    this.canvas = canvas;
    this.onTap = onTap;
    this.renderer = new WebGLRenderer({ canvas, antialias: false, powerPreference: 'low-power' });
    this.renderer.setPixelRatio(Math.min(window.devicePixelRatio, 1.5));
    this.scene.background = new Color(0xe9eef2);
    this.scene.add(new HemisphereLight(0xffffff, 0x8899aa, 1.6));
    const sun = new DirectionalLight(0xffffff, 1.4);
    sun.position.set(1.5, 3, 2);
    this.scene.add(sun, this.flashLight);
    this.flashLight.position.set(-0.3, 1.2, 0.4);

    const wall = box(4, 2.4, 0.05, 0xdfe5ea);
    wall.position.set(0, 1.2, -0.62);
    const bench = box(2.4, 0.08, 1.1, 0xb98b5e);
    bench.position.set(0, 0.75, 0);
    this.scene.add(wall, bench);

    // Wall socket with its switch.
    const socket = box(0.22, 0.14, 0.03, 0xffffff);
    socket.position.set(1.0, 1.05, -0.58);
    const sw = box(0.06, 0.08, 0.04, 0xc92a2f, 'wall-switch');
    sw.position.set(1.05, 1.05, -0.55);
    this.scene.add(socket, sw);
    this.obj['wall-switch'] = sw;

    // PC case, its power button and the removable side panel.
    const pc = new Group();
    pc.position.set(-0.45, 1.05, -0.1);
    pc.rotation.y = -0.7; // turned so you see both the front (power button) and the side that opens
    // An open shell (the right side is the removable panel), so the inside is hidden until the panel comes off.
    for (const [w, h, d, x, y, z] of [[0.01, 0.52, 0.55, -0.205, 0, 0], [0.42, 0.01, 0.55, 0, 0.255, 0], [0.42, 0.01, 0.55, 0, -0.255, 0], [0.42, 0.52, 0.01, 0, 0, 0.27], [0.42, 0.52, 0.01, 0, 0, -0.27]] as const) {
      const side = box(w, h, d, 0x2b3138);
      side.position.set(x, y, z);
      pc.add(side);
    }
    const board = box(0.01, 0.42, 0.45, 0x1f5f3a);
    board.position.set(-0.17, 0, 0);
    const slot = box(0.03, 0.02, 0.3, 0x111111, 'slot-a1');
    slot.position.set(-0.15, 0.05, 0);
    const ramOld = box(0.02, 0.09, 0.28, 0x2e7d32, 'ram-old');
    ramOld.position.set(-0.14, 0.1, 0);
    const ramFitted = box(0.02, 0.09, 0.28, 0x43a047, 'ram-new');
    ramFitted.position.copy(ramOld.position);
    pc.add(board, slot, ramOld, ramFitted);
    this.obj['ram-fitted'] = ramFitted;
    const button = new Mesh(new CylinderGeometry(0.03, 0.03, 0.02, 20), mat(0x9aa5b1));
    button.rotation.x = Math.PI / 2;
    button.position.set(0, 0.15, 0.28);
    button.userData.id = 'pc-power';
    const panel = box(0.01, 0.5, 0.53, 0x3a424b, 'case-panel');
    panel.position.set(0.215, 0, 0);
    // The power cable's plug at the back of the case (pulled out a little when "loose").
    const plug = box(0.08, 0.06, 0.06, 0x111418, 'psu-cable');
    plug.position.set(0.1, -0.17, -0.3);
    const cord = new Mesh(new CylinderGeometry(0.012, 0.012, 0.5, 8), mat(0x111418));
    cord.rotation.x = Math.PI / 2;
    cord.position.set(0, 0, -0.27);
    cord.userData.id = 'psu-cable';
    plug.add(cord);
    pc.add(button, panel, plug);
    this.scene.add(pc);
    Object.assign(this.obj, { 'pc-power': button, 'case-panel': panel, 'ram-old': ramOld, 'slot-a1': slot, 'psu-cable': plug });

    // Monitor.
    const monitor = new Group();
    monitor.position.set(0.35, 1.12, -0.35);
    const frameMesh = box(0.62, 0.38, 0.04, 0x1d2228, 'monitor');
    this.screen = box(0.56, 0.32, 0.01, 0x050608, 'monitor');
    this.screen.position.z = 0.021;
    const stand = box(0.06, 0.2, 0.06, 0x1d2228);
    stand.position.y = -0.27;
    monitor.add(frameMesh, this.screen, stand);
    this.scene.add(monitor);
    this.obj.monitor = monitor;

    // Things on the bench.
    const ramNew = box(0.28, 0.01, 0.09, 0x43a047, 'ram-new');
    ramNew.position.set(0.55, 0.795, 0.3);
    const strap = new Mesh(new TorusGeometry(0.06, 0.015, 8, 24), mat(0x2563eb));
    strap.rotation.x = Math.PI / 2;
    strap.userData.id = 'strap';
    const driver = new Mesh(new CylinderGeometry(0.012, 0.012, 0.22, 10), mat(0xf59e0b));
    driver.rotation.z = Math.PI / 2;
    driver.position.set(0.0, 0.8, 0.35);
    driver.userData.id = 'screwdriver';
    this.scene.add(ramNew, strap, driver);
    Object.assign(this.obj, { 'ram-new': ramNew, strap, screwdriver: driver });

    this.resize = new ResizeObserver(() => this.fit());
    this.resize.observe(canvas);
    const onDown = (e: PointerEvent) => { this.down = { x: e.clientX, y: e.clientY, yaw: this.yaw }; this.moved = false; canvas.setPointerCapture(e.pointerId); };
    const onMove = (e: PointerEvent) => {
      if (!this.down) return;
      const dx = e.clientX - this.down.x;
      if (Math.abs(dx) + Math.abs(e.clientY - this.down.y) > 8) this.moved = true;
      if (this.moved) { this.yaw = Math.max(-1, Math.min(1, this.down.yaw - dx / 250)); this.render(); }
    };
    const onUp = (e: PointerEvent) => { if (this.down && !this.moved) this.pick(e); this.down = null; };
    const onWheel = (e: WheelEvent) => { e.preventDefault(); this.zoom = Math.max(0.7, Math.min(1.4, this.zoom + e.deltaY / 1000)); this.render(); };
    canvas.addEventListener('pointerdown', onDown);
    canvas.addEventListener('pointermove', onMove);
    canvas.addEventListener('pointerup', onUp);
    canvas.addEventListener('wheel', onWheel, { passive: false });
    this.cleanup = () => {
      canvas.removeEventListener('pointerdown', onDown);
      canvas.removeEventListener('pointermove', onMove);
      canvas.removeEventListener('pointerup', onUp);
      canvas.removeEventListener('wheel', onWheel);
    };
    this.fit();
  }

  /** Move and colour objects to match the scenario state. */
  apply(s: Record<string, string>, holding: string | null) {
    const o = this.obj;
    const colour = (m: Object3D, c: number) => ((m as Mesh).material as MeshLambertMaterial).color.setHex(c);
    colour(o['wall-switch'], s['wall-switch'] === 'off' ? 0x6b7280 : 0xc92a2f);
    o['wall-switch'].rotation.x = s['wall-switch'] === 'off' ? 0.4 : -0.4;
    const open = s['case-panel'] === 'open';
    o['case-panel'].position.set(open ? 0.5 : 0.215, open ? -0.25 : 0, open ? 0.1 : 0);
    o['case-panel'].rotation.z = open ? Math.PI / 2 : 0;
    o['ram-old'].visible = s['ram-old'] !== 'out';
    o['ram-new'].visible = s['ram-new'] !== 'in';
    o['ram-fitted'].visible = s['ram-new'] === 'in';
    o['psu-cable'].position.z = s['psu-cable'] === 'loose' ? -0.36 : -0.3;
    o.strap.position.set(0.85, s.strap === 'on' ? 0.9 : 0.8, 0.25);
    colour(o.strap, s.strap === 'on' ? 0x16a34a : 0x2563eb);
    colour(this.screen, s.monitor === 'ok' ? 0x1d6fe0 : s.monitor === 'beeps' ? 0x5a0d10 : 0x050608);
    for (const [id, m] of Object.entries(o)) {
      if (m instanceof Mesh && m.material instanceof MeshLambertMaterial) m.material.emissive.setHex(id === holding ? 0x335500 : 0x000000);
    }
    this.render();
  }

  /** A consequence you can see: sparks from a live PC, a blue static crackle, a red screen for beeps. */
  flash(kind: SimEffect) {
    if (kind === 'none' || kind === 'customer') return;
    this.flashLight.color.setHex(kind === 'static' ? 0x60a5fa : kind === 'beep' ? 0xff3030 : 0xffa040);
    this.flashUntil = performance.now() + 700;
    const tick = () => {
      const left = this.flashUntil - performance.now();
      this.flashLight.intensity = left > 0 ? 6 * (0.5 + 0.5 * Math.sin(left / 30)) : 0;
      this.render();
      if (left > 0) this.frame = requestAnimationFrame(tick);
    };
    cancelAnimationFrame(this.frame);
    tick();
  }

  private pick(e: PointerEvent) {
    const r = this.canvas.getBoundingClientRect();
    const ray = new Raycaster();
    ray.setFromCamera(new Vector2(((e.clientX - r.left) / r.width) * 2 - 1, -((e.clientY - r.top) / r.height) * 2 + 1), this.camera);
    // The nearest visible thing wins, so you cannot reach through the case wall.
    const hit = ray.intersectObjects(this.scene.children, true).find((h) => h.object.visible);
    let o: Object3D | null = hit?.object ?? null;
    while (o && !o.userData.id) o = o.parent;
    if (o?.userData.id) this.onTap(String(o.userData.id));
  }

  private fit() {
    const w = this.canvas.clientWidth || 1;
    const h = this.canvas.clientHeight || 1;
    this.renderer.setSize(w, h, false);
    this.camera.aspect = w / h;
    this.render();
  }

  private render() {
    // Step back on narrow (phone) screens so the whole bench fits.
    const dist = Math.max(2.3, 2.9 / this.camera.aspect) * this.zoom;
    this.camera.position.set(Math.sin(this.yaw) * dist, 1.75, Math.cos(this.yaw) * dist);
    this.camera.lookAt(0, 0.95, -0.1);
    this.camera.updateProjectionMatrix();
    this.renderer.render(this.scene, this.camera);
  }

  dispose() {
    cancelAnimationFrame(this.frame);
    this.resize.disconnect();
    this.cleanup();
    this.renderer.dispose();
  }
}
