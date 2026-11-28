// Speed budgets, measured as a user experiences them (approved by Srini, 28 Nov 2026):
//  1. First load: the entry script plus everything it preloads must stay within 170 KB gzip (non-negotiable).
//  2. Each language file (loaded only for the language the user picks) must stay within 15 KB gzip.
//  3. The 3D engine (three.js), downloaded only when a simulator opens, must stay within 150 KB gzip.
//  4. Each other lazy chunk (a page, or a helper a page loads on demand) must stay within 25 KB gzip.
// A total of all files is not checked: no user downloads every page and every language.
import { readdirSync, readFileSync } from 'node:fs';
import { gzipSync } from 'node:zlib';
import { join } from 'node:path';

const dist = new URL('../dist/', import.meta.url).pathname;
const SHELL_KB = Number(process.env.SHELL_LIMIT_KB ?? 170);
const LANGUAGE_KB = 15;
const CHUNK_KB = 25;
const ENGINE_KB = 150;
const ENGINE = /^assets\/three-[\w-]+\.js$/;
const LANGUAGE = /^assets\/lang-(zu|af|xh)-(learner|staff|hq)-[\w-]+\.js$/;

const kb = (f) => gzipSync(readFileSync(join(dist, f))).length / 1024;
const html = readFileSync(join(dist, 'index.html'), 'utf8');
const shell = new Set([...html.matchAll(/(?:src|href)="\/(assets\/[^"]+\.js)"/g)].map((m) => m[1]));
const failures = [];

let total = 0;
for (const f of shell) {
  const size = kb(f);
  total += size;
  console.log(`${size.toFixed(1).padStart(7)} KB  ${f}`);
}
console.log(`${total.toFixed(1).padStart(7)} KB  first-load JavaScript (gzip), limit ${SHELL_KB} KB\n`);
if (total > SHELL_KB) failures.push(`First load is ${total.toFixed(1)} KB (limit ${SHELL_KB} KB).`);

const lazy = readdirSync(join(dist, 'assets')).map((f) => `assets/${f}`).filter((f) => f.endsWith('.js') && !shell.has(f));
let largest = { f: '', size: 0 };
for (const f of lazy) {
  const size = kb(f);
  const limit = LANGUAGE.test(f) ? LANGUAGE_KB : ENGINE.test(f) ? ENGINE_KB : CHUNK_KB;
  if (ENGINE.test(f)) console.log(`${size.toFixed(1).padStart(7)} KB  ${f} (3D engine, limit ${ENGINE_KB} KB)`);
  else if (!LANGUAGE.test(f) && size > largest.size) largest = { f, size };
  if (LANGUAGE.test(f)) console.log(`${size.toFixed(1).padStart(7)} KB  ${f} (language, limit ${LANGUAGE_KB} KB)`);
  if (size > limit) failures.push(`${f} is ${size.toFixed(1)} KB (limit ${limit} KB).`);
}
console.log(`${largest.size.toFixed(1).padStart(7)} KB  ${largest.f} (largest of ${lazy.length} lazy files, limit ${CHUNK_KB} KB each)`);

if (failures.length) {
  console.error(`\nOver the speed budget:\n  ${failures.join('\n  ')}`);
  process.exit(1);
}
