import { createContext, useContext, useMemo, type ReactNode } from 'react';
import { useColorScheme } from 'react-native';
import { DEFAULT_BRANDING, type Branding } from '@simuwork/core';
import { useAcademy } from '../lib/academy';
import { useSettings } from '../lib/settings';

/**
 * Mobile design system (separate from web by design; only brand colours are shared).
 * System fonts keep the app small and fast; sizes follow a thumb-first type scale.
 */
type Surfaces = { bg: string; surface: string; surface2: string; text: string; muted: string; line: string; overlay: string };
const surfaces: { light: Surfaces; dark: Surfaces } = {
  light: { bg: '#F4F5FA', surface: '#FFFFFF', surface2: '#EEF0F7', text: '#12152B', muted: '#5F6483', line: '#E3E5EE', overlay: 'rgba(9,12,28,0.45)' },
  dark: { bg: '#080B1A', surface: '#11152D', surface2: '#181D3B', text: '#ECEEF8', muted: '#A0A6C4', line: '#252B4E', overlay: 'rgba(0,0,0,0.6)' },
};

/** Platform-owned colours for the locked footer: never taken from the academy brand. */
const footer = { light: { text: '#5F6483', link: '#4B45D6' }, dark: { text: '#A0A6C4', link: '#A9A5FF' } } as const;

export const space = { xs: 4, sm: 8, md: 12, lg: 16, xl: 20, xxl: 24, xxxl: 32 } as const;
export const radius = { sm: 10, md: 14, lg: 20, xl: 28, pill: 999 } as const;
export const type = {
  display: { fontSize: 32, lineHeight: 38, fontWeight: '800' as const, letterSpacing: -0.6 },
  title: { fontSize: 24, lineHeight: 30, fontWeight: '800' as const, letterSpacing: -0.4 },
  headline: { fontSize: 18, lineHeight: 24, fontWeight: '700' as const },
  body: { fontSize: 16, lineHeight: 23, fontWeight: '400' as const },
  label: { fontSize: 15, lineHeight: 20, fontWeight: '600' as const },
  caption: { fontSize: 13, lineHeight: 18, fontWeight: '500' as const },
  overline: { fontSize: 11, lineHeight: 14, fontWeight: '800' as const, letterSpacing: 1.4 },
};

/** Mix a hex colour with another (0..1 of the second). */
export function mix(a: string, b: string, amount: number): string {
  const p = (h: string) => [1, 3, 5].map((i) => parseInt(h.slice(i, i + 2), 16));
  const [x, y] = [p(a), p(b)];
  return '#' + x.map((v, i) => Math.round(v + (y[i]! - v) * amount).toString(16).padStart(2, '0')).join('');
}

export interface Theme {
  dark: boolean;
  c: Surfaces & {
    brand: string;
    brandSoft: string;
    accent: string;
    nav: string;
    onBrand: string;
    success: string;
    warning: string;
    danger: string;
    footerText: string;
    footerLink: string;
  };
}

const Ctx = createContext<Theme | null>(null);

export function buildTheme(dark: boolean, branding: Pick<Branding, 'primary' | 'accent' | 'nav'>): Theme {
  const s = dark ? surfaces.dark : surfaces.light;
  return {
    dark,
    c: {
      ...s,
      brand: branding.primary,
      brandSoft: mix(s.surface, branding.primary, dark ? 0.24 : 0.12),
      accent: branding.accent,
      nav: branding.nav,
      onBrand: '#FFFFFF',
      success: dark ? '#5FD3B1' : '#0B7A5C',
      warning: dark ? '#F5C46B' : '#8A5A00',
      danger: dark ? '#FF9A9E' : '#C92A2F',
      footerText: (dark ? footer.dark : footer.light).text,
      footerLink: (dark ? footer.dark : footer.light).link,
    },
  };
}

export function ThemeProvider({ children }: { children: ReactNode }) {
  const system = useColorScheme();
  const { appearance } = useSettings();
  const { academy } = useAcademy();
  const dark = appearance === 'dark' || (appearance === 'system' && system === 'dark');
  const branding = academy?.branding ?? DEFAULT_BRANDING;
  const theme = useMemo(() => buildTheme(dark, branding), [dark, branding]);
  return <Ctx.Provider value={theme}>{children}</Ctx.Provider>;
}

export function useTheme(): Theme {
  const t = useContext(Ctx);
  if (!t) throw new Error('useTheme must be used inside ThemeProvider');
  return t;
}
