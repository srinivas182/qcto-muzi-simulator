import { describe, expect, it } from 'vitest';
import { detectArea } from '../lib/area';
import { contrastWithWhite } from '@simuwork/core';
import { format } from '../lib/i18n';
import { filterItems } from '../components/shell/palette';
import en from '@simuwork/core/locales/en';
import af from '@simuwork/core/locales/af';
import xh from '@simuwork/core/locales/xh';
import zu from '@simuwork/core/locales/zu';
import { KNOWN_PATHS } from '../app/access';
import { QCTO_CODES } from '@simuwork/core';
import qctoEn from '@simuwork/core/locales/qcto-en';
import qctoAf from '@simuwork/core/locales/qcto-af';
import qctoXh from '@simuwork/core/locales/qcto-xh';
import qctoZu from '@simuwork/core/locales/qcto-zu';

const placeholders = (s: string) => [...s.matchAll(/\{(\w+)\}/g)].map((m) => m[1]).sort();

describe('translations', () => {
  for (const [name, messages] of Object.entries({ af, xh, zu })) {
    it(`${name} has every English key with the same placeholders`, () => {
      expect(Object.keys(messages).sort()).toEqual(Object.keys(en).sort());
      for (const [key, text] of Object.entries(en)) {
        expect(placeholders((messages as Record<string, string>)[key]!), `${name} ${key}`).toEqual(placeholders(text));
        expect((messages as Record<string, string>)[key]!.trim(), `${name} ${key}`).not.toBe('');
      }
    });
  }

  it('has a QCTO label for every code in every language', () => {
    const codes = Object.entries(QCTO_CODES).filter(([list]) => list !== 'titles').flatMap(([list, values]) => values.map((v) => `${list}.${v}`)).sort();
    for (const labels of [qctoEn, qctoAf, qctoXh, qctoZu]) expect(Object.keys(labels).sort()).toEqual(codes);
  });

  it('fills placeholders', () => {
    expect(format('Hello {name}', { name: 'Sipho' })).toBe('Hello Sipho');
    expect(format('Hello {name}')).toBe('Hello {name}');
  });
});

describe('helpers', () => {
  it('detects the HQ host', () => {
    expect(detectArea('admin.simuwork.co.za')).toBe('hq');
    expect(detectArea('ubuntu.simuwork.co.za')).toBe('tenant');
    expect(detectArea('learn.ubuntuskills.co.za')).toBe('tenant');
    expect(detectArea('localhost', 'hq')).toBe('hq');
  });

  it('matches the API contrast rule', () => {
    expect(contrastWithWhite('#4B45D6')).toBeGreaterThan(4.5);
    expect(contrastWithWhite('#FFE066')).toBeLessThan(4.5);
    expect(contrastWithWhite('#000000')).toBeCloseTo(21, 0);
  });

  it('ranks quick-search results', () => {
    const items = [{ label: 'Audit log' }, { label: 'Roles' }, { label: 'Learners', keywords: 'students' }];
    expect(filterItems(items, 'ro').map((i) => i.label)).toEqual(['Roles']);
    expect(filterItems(items, 'log')[0]!.label).toBe('Audit log');
    expect(filterItems(items, 'students')[0]!.label).toBe('Learners');
    expect(filterItems(items, '')).toHaveLength(3);
  });

  it('knows every menu path from the API catalogue', () => {
    expect(KNOWN_PATHS.has('/admin/roles')).toBe(true);
    expect(KNOWN_PATHS.has('/hq/studio/scenarios')).toBe(true);
    expect(KNOWN_PATHS.has('/learn')).toBe(true);
  });
});
