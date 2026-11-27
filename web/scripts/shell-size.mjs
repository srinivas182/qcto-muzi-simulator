// Speed budget: the JavaScript a browser must download before the first screen (the entry script
// plus everything it preloads) must stay within 170 KB gzip. Pages and languages load later.
import { readFileSync } from 'node:fs';
import { gzipSync } from 'node:zlib';
import { join } from 'node:path';

const dist = new URL('../dist/', import.meta.url).pathname;
const LIMIT_KB = Number(process.env.SHELL_LIMIT_KB ?? 170);
const html = readFileSync(join(dist, 'index.html'), 'utf8');
const files = [...html.matchAll(/(?:src|href)="\/(assets\/[^"]+\.js)"/g)].map((m) => m[1]);

let total = 0;
for (const f of files) {
  const kb = gzipSync(readFileSync(join(dist, f))).length / 1024;
  total += kb;
  console.log(`${kb.toFixed(1).padStart(7)} KB  ${f}`);
}
console.log(`${total.toFixed(1).padStart(7)} KB  first-load JavaScript (gzip), limit ${LIMIT_KB} KB`);
if (total > LIMIT_KB) {
  console.error('Over the speed budget.');
  process.exit(1);
}
