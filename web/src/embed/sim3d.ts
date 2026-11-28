/**
 * The app's 3D simulator view (Sprint 4.6c). Built as one self-contained HTML file (scripts/build-embed.mjs),
 * shipped in the offline pack and opened in a WebView from the phone's storage, so it works with no data.
 *
 * The app runs the scenario (the same reducer and scoring); this page only draws the scene:
 *   app → page   {type: 'state', scene, holding}   {type: 'flash', kind}
 *   page → app   {type: 'ready'}   {type: 'tap', id}
 */
import type { SimEffect } from '@simuwork/core';
import { Bench } from '../sim/bench';

type Incoming = { type: 'state'; scene: Record<string, string>; holding: string | null } | { type: 'flash'; kind: SimEffect };
type Bridge = { postMessage: (data: string) => void };

const canvas = document.getElementById('scene') as HTMLCanvasElement;
const send = (msg: object) => (window as unknown as { ReactNativeWebView?: Bridge }).ReactNativeWebView?.postMessage(JSON.stringify(msg));
const bench = new Bench(canvas, (id) => send({ type: 'tap', id }));

const receive = (raw: unknown) => {
  try {
    const m = (typeof raw === 'string' ? JSON.parse(raw) : raw) as Incoming;
    if (m.type === 'state') bench.apply(m.scene, m.holding);
    if (m.type === 'flash') bench.flash(m.kind);
  } catch { /* ignore anything that is not ours */ }
};
window.addEventListener('message', (e) => receive(e.data));
document.addEventListener('message', (e) => receive((e as MessageEvent).data)); // Android WebView
send({ type: 'ready' });
