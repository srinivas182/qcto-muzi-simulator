import type { Branding } from '@simuwork/core';

/** Apply a provider's brand colours. Only --brand-* variables change; platform UI is untouched. */
export function applyBranding(branding: Pick<Branding, 'primary' | 'accent' | 'nav'> | null | undefined): void {
  const root = document.documentElement;
  if (!branding) {
    ['--brand-primary', '--brand-accent', '--brand-nav'].forEach((v) => root.style.removeProperty(v));
    return;
  }
  root.style.setProperty('--brand-primary', branding.primary);
  root.style.setProperty('--brand-accent', branding.accent);
  root.style.setProperty('--brand-nav', branding.nav);
}

export type ThemeMode = 'light' | 'dark' | 'system';
const KEY = 'sw-theme';

export function storedTheme(): ThemeMode {
  try {
    const v = localStorage.getItem(KEY);
    return v === 'light' || v === 'dark' ? v : 'system';
  } catch {
    return 'system';
  }
}

export function applyTheme(mode: ThemeMode): void {
  const dark = mode === 'dark' || (mode === 'system' && matchMedia('(prefers-color-scheme: dark)').matches);
  document.documentElement.classList.toggle('dark', dark);
  try {
    if (mode === 'system') localStorage.removeItem(KEY);
    else localStorage.setItem(KEY, mode);
  } catch {
    /* storage unavailable: theme still applies for this visit */
  }
}

export function isDark(): boolean {
  return document.documentElement.classList.contains('dark');
}
