// Speed budget for the app: the Hermes bytecode bundle that every phone loads at start-up.
// Smaller bundles start faster on budget Android phones and make the app download smaller.
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { gzipSync } from 'node:zlib';

const dir = new URL('../dist/android/_expo/static/js/android/', import.meta.url).pathname;
const LIMIT_MB = Number(process.env.BUNDLE_LIMIT_MB ?? 3.5);
const files = readdirSync(dir).filter((f) => f.endsWith('.hbc') || f.endsWith('.js'));
if (files.length === 0) {
  console.error('No Android bundle found. Run "npm run bundle" first.');
  process.exit(1);
}
let total = 0;
for (const f of files) {
  const bytes = statSync(join(dir, f)).size;
  total += bytes;
  const gz = gzipSync(readFileSync(join(dir, f))).length;
  console.log(`${(bytes / 1048576).toFixed(2)} MB (${(gz / 1048576).toFixed(2)} MB compressed in the download)  ${f}`);
}
console.log(`JavaScript bundle: ${(total / 1048576).toFixed(2)} MB, limit ${LIMIT_MB} MB`);
if (total / 1048576 > LIMIT_MB) {
  console.error('Over the app bundle budget.');
  process.exit(1);
}
