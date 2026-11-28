import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import type { Plugin } from 'vite';
import { partOf, type LocalePart } from '../../packages/core/src/locales/parts.ts';

const PREFIX = 'virtual:locale/';
const LINE = /^\s*'([^']+)':\s*(.+?),?\s*$/;

/** Keep only one part's entries of a locale source file (one `'key': "value",` per line). */
export function splitLocale(source: string, part: LocalePart): Record<string, string> {
  const out: Record<string, string> = {};
  for (const line of source.split('\n')) {
    const m = LINE.exec(line);
    if (!m || partOf(m[1]!) !== part) continue;
    // The value is a JS string literal from our own source file (build time only).
    out[m[1]!] = Function(`"use strict"; return (${m[2]!.replace(/,$/, '')});`)() as string;
  }
  return out;
}

/**
 * `import zuLearner from 'virtual:locale/zu/learner'` gives the learner part of isiZulu as its own small chunk.
 * See packages/core/src/locales/parts.ts.
 */
export function localeParts(): Plugin {
  const dir = fileURLToPath(new URL('../../packages/core/src/locales/', import.meta.url));
  return {
    name: 'simuwork-locale-parts',
    resolveId: (id) => (id.startsWith(PREFIX) ? `\0${id}` : undefined),
    load(id) {
      if (!id.startsWith(`\0${PREFIX}`)) return undefined;
      const [lang, part] = id.slice(PREFIX.length + 1).split('/') as [string, LocalePart];
      if (!/^[a-z]{2}$/.test(lang) || !['learner', 'staff', 'hq'].includes(part)) return undefined;
      const file = `${dir}${lang}.ts`;
      this.addWatchFile(file);
      return `export default ${JSON.stringify(splitLocale(readFileSync(file, 'utf8'), part))};`;
    },
  };
}
