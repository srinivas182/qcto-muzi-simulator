// Builds the app's offline 3D view as one HTML file: dist/embed/sim-3d.html (served by the web host, shipped in
// offline packs). Budget: 160 KB gzip (the 150 KB 3D engine plus the scene), checked in CI.
import { execSync } from 'node:child_process';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { gzipSync } from 'node:zlib';

execSync('npx vite build --config vite.embed.config.ts --logLevel warn', { stdio: 'inherit' });
const js = readFileSync(new URL('../dist-embed/sim3d.js', import.meta.url), 'utf8');
const html = `<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no">
<title>SimuWork 3D</title><style>html,body{margin:0;height:100%;background:#e9eef2;overflow:hidden}canvas{display:block;width:100%;height:100%;touch-action:none}</style></head>
<body><canvas id="scene" aria-label="3D workplace"></canvas><script>${js.replace(/<\/script/gi, '<\\/script')}</script></body></html>`;
mkdirSync(new URL('../dist/embed/', import.meta.url), { recursive: true });
writeFileSync(new URL('../dist/embed/sim-3d.html', import.meta.url), html);
const kb = gzipSync(html).length / 1024;
console.log(`${kb.toFixed(1).padStart(7)} KB  embed/sim-3d.html (app 3D view, limit 160 KB)`);
if (kb > 160) { console.error('The app 3D view is over its 160 KB budget.'); process.exit(1); }
