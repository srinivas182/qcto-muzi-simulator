// Download-size budget for the Android release APK (roadmap commitment C4: under 25 MB).
// Usage: node scripts/apk-size.mjs path/to/app.apk
// Prints the size and what takes the space (native libraries, app code, JavaScript, resources), and writes
// a Markdown table to the GitHub job summary when run in CI.
import { appendFileSync, readFileSync, statSync } from 'node:fs';

const file = process.argv[2];
const LIMIT_MB = Number(process.env.APK_LIMIT_MB ?? 25);
if (!file) {
  console.error('Usage: node scripts/apk-size.mjs <apk>');
  process.exit(2);
}

const mb = (n) => (n / 1048576).toFixed(2);
const buf = readFileSync(file);
const total = statSync(file).size;

// Read the zip central directory (an APK is a zip) to group entries by what they are.
let eocd = buf.length - 22;
while (eocd >= 0 && buf.readUInt32LE(eocd) !== 0x06054b50) eocd--;
if (eocd < 0) throw new Error(`${file} is not a valid APK`);
const count = buf.readUInt16LE(eocd + 10);
let p = buf.readUInt32LE(eocd + 16);

const groups = new Map();
const group = (name) => {
  if (name.startsWith('lib/')) return 'Native libraries (lib/)';
  if (/^classes\d*\.dex$/.test(name)) return 'App code (dex)';
  if (name.startsWith('assets/') && /\.(bundle|hbc)$/.test(name)) return 'JavaScript (Hermes bytecode)';
  if (name.startsWith('assets/')) return 'Other assets';
  if (name.startsWith('res/') || name === 'resources.arsc') return 'Resources';
  return 'Other (manifest, signatures, …)';
};
const largest = [];
for (let i = 0; i < count; i++) {
  if (buf.readUInt32LE(p) !== 0x02014b50) throw new Error('Unexpected zip structure');
  const compressed = buf.readUInt32LE(p + 20);
  const nameLen = buf.readUInt16LE(p + 28);
  const extraLen = buf.readUInt16LE(p + 30);
  const commentLen = buf.readUInt16LE(p + 32);
  const name = buf.toString('utf8', p + 46, p + 46 + nameLen);
  const g = group(name);
  groups.set(g, (groups.get(g) ?? 0) + compressed);
  largest.push([name, compressed]);
  p += 46 + nameLen + extraLen + commentLen;
}
largest.sort((a, b) => b[1] - a[1]);

const ok = total / 1048576 <= LIMIT_MB;
const rows = [...groups.entries()].sort((a, b) => b[1] - a[1]);
console.log(`APK ${file}: ${mb(total)} MB (limit ${LIMIT_MB} MB)`);
for (const [g, n] of rows) console.log(`  ${mb(n).padStart(6)} MB  ${g}`);
console.log('Largest files:');
for (const [n, s] of largest.slice(0, 8)) console.log(`  ${mb(s).padStart(6)} MB  ${n}`);

if (process.env.GITHUB_STEP_SUMMARY) {
  const lines = [
    `### Android APK size: ${mb(total)} MB ${ok ? '✅' : '❌'} (budget ${LIMIT_MB} MB, arm64 phones)`,
    '',
    '| Part | Size |',
    '|---|---:|',
    ...rows.map(([g, n]) => `| ${g} | ${mb(n)} MB |`),
    '',
  ];
  appendFileSync(process.env.GITHUB_STEP_SUMMARY, lines.join('\n') + '\n');
}

if (!ok) {
  console.error(`Over the APK budget by ${mb(total - LIMIT_MB * 1048576)} MB.`);
  process.exit(1);
}
