import { readdirSync, readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import en from '@simuwork/core/locales/en';
import { partOf } from '@simuwork/core/locales/parts';
import { splitLocale } from '../../plugins/localeParts';

const core = `${process.cwd()}/../packages/core/src/locales`;

describe('language parts', () => {
  for (const lang of ['zu', 'af', 'xh']) {
    it(`${lang}: the learner, staff and HQ parts together are the whole file, with no overlap`, () => {
      const src = readFileSync(`${core}/${lang}.ts`, 'utf8');
      const learner = splitLocale(src, 'learner');
      const staff = splitLocale(src, 'staff');
      const hq = splitLocale(src, 'hq');
      expect(Object.keys(learner).filter((k) => k in staff || k in hq)).toEqual([]);
      expect([...Object.keys(learner), ...Object.keys(staff), ...Object.keys(hq)].sort()).toEqual(Object.keys(en).sort());
      expect(hq['ss.preview']).toBeTruthy();
      expect(learner['common.cancel']).toBeTruthy();
    });
  }

  it('every text a learner screen uses is in the learner part', () => {
    const dir = `${process.cwd()}/src`;
    const files = [...readdirSync(`${dir}/pages/learn`).map((f) => `${dir}/pages/learn/${f}`), `${dir}/components/qcto/QctoDetailsDrawer.tsx`];
    const keys = files.flatMap((f) => [...readFileSync(f, 'utf8').matchAll(/\bt\('([a-z][\w.]+)'/g)].map((m) => m[1]!));
    expect(keys.length).toBeGreaterThan(20);
    expect(keys.filter((k) => partOf(k) !== 'learner')).toEqual([]);
  });

  it('provider screens never need the HQ part', () => {
    const dir = `${process.cwd()}/src`;
    const walk = (d: string): string[] => readdirSync(d, { withFileTypes: true }).flatMap((e) => (e.isDirectory() ? walk(`${d}/${e.name}`) : [`${d}/${e.name}`]));
    const files = walk(dir).filter((f) => /\.tsx?$/.test(f) && !f.includes('/pages/hq/') && !f.includes('/test/'));
    const keys = files.flatMap((f) => [...readFileSync(f, 'utf8').matchAll(/\bt\('([a-z][\w.]+)'/g)].map((m) => m[1]!));
    expect(keys.filter((k) => partOf(k) === 'hq')).toEqual([]);
  });
});
