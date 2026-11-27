// Cold-start measurement on an Android emulator (roadmap commitment C4).
// Usage (with an emulator running): node scripts/cold-start.mjs path/to/app.apk
//
// For each run the app is force-stopped and launched fresh. Two numbers are taken from the device log:
//   - Displayed: Android's own time until the first frame (the splash screen);
//   - First screen: time until the first SimuWork screen is on screen (marker written by src/lib/startup.ts).
// The first launch after install is reported separately (it is the slowest one a learner sees).
// Emulator numbers are for spotting regressions between PRs; the < 2 s target is checked on a real
// budget phone before launch.
import { execFileSync } from 'node:child_process';
import { appendFileSync } from 'node:fs';

const apk = process.argv[2];
const RUNS = Number(process.env.COLD_START_RUNS ?? 5);
const PKG = 'com.mayuraconsultancy.simuwork';
const ACTIVITY = `${PKG}/.MainActivity`;
const MARKER = '[SimuWork] first screen';

const adb = (...args) => execFileSync('adb', args, { encoding: 'utf8' });
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

async function launch() {
  adb('shell', 'am', 'force-stop', PKG);
  adb('logcat', '-c');
  await sleep(1500);
  const out = adb('shell', 'am', 'start', '-W', '-n', ACTIVITY);
  const displayed = Number(/TotalTime:\s*(\d+)/.exec(out)?.[1] ?? NaN);

  // Wait for the first-screen marker, then measure from the launch request (both device clock).
  let firstScreen = NaN;
  for (let i = 0; i < 60 && Number.isNaN(firstScreen); i++) {
    const log = adb('logcat', '-d', '-v', 'epoch');
    const start = log.split('\n').find((l) => /START u0 .*cmp=com\.mayuraconsultancy\.simuwork/.test(l));
    const ready = log.split('\n').find((l) => l.includes(MARKER));
    if (start && ready) firstScreen = Math.round((parseFloat(ready) - parseFloat(start)) * 1000);
    else await sleep(500);
  }
  return { displayed, firstScreen };
}

const median = (xs) => {
  const v = xs.filter((x) => !Number.isNaN(x)).sort((a, b) => a - b);
  return v.length ? v[Math.floor(v.length / 2)] : NaN;
};
const fmt = (ms) => (Number.isNaN(ms) ? 'n/a' : `${(ms / 1000).toFixed(2)} s`);

adb('install', '-r', '-g', apk);
const first = await launch();
const runs = [];
for (let i = 0; i < RUNS; i++) runs.push(await launch());
adb('shell', 'am', 'force-stop', PKG);

const result = {
  first_launch: first,
  median: { displayed: median(runs.map((r) => r.displayed)), firstScreen: median(runs.map((r) => r.firstScreen)) },
  runs,
};
console.log(JSON.stringify(result, null, 2));

if (process.env.GITHUB_STEP_SUMMARY) {
  appendFileSync(process.env.GITHUB_STEP_SUMMARY, [
    `### Cold start on the CI emulator (median of ${RUNS})`,
    '',
    '| | Android "Displayed" | First SimuWork screen |',
    '|---|---:|---:|',
    `| Median cold start | ${fmt(result.median.displayed)} | ${fmt(result.median.firstScreen)} |`,
    `| First launch after install | ${fmt(first.displayed)} | ${fmt(first.firstScreen)} |`,
    '',
    'Emulator figures track changes between PRs. The < 2 s target is confirmed on a real budget phone before launch.',
    '',
  ].join('\n') + '\n');
}

if (Number.isNaN(result.median.firstScreen)) {
  console.error('The first SimuWork screen never appeared: the release app may be crashing at start-up.');
  process.exit(1);
}
