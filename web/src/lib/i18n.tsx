import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react';
import en, { type MessageKey, type Messages } from '@simuwork/core/locales/en';

/**
 * Tiny i18n: English ships with the app; other languages load on demand (a few KB each),
 * so changing language never slows the first page.
 */
import { format, isLocale, type Locale } from '@simuwork/core';

export { format, isLocale, LOCALES, sectionKey, type Locale } from '@simuwork/core';

const loaders: Record<Exclude<Locale, 'en'>, () => Promise<{ default: Messages }>> = {
  zu: () => import('@simuwork/core/locales/zu'),
  af: () => import('@simuwork/core/locales/af'),
  xh: () => import('@simuwork/core/locales/xh'),
};

const KEY = 'sw-locale';

export function storedLocale(): Locale | null {
  try {
    const v = localStorage.getItem(KEY);
    return isLocale(v) ? v : null;
  } catch {
    return null;
  }
}

type T = (key: MessageKey, vars?: Record<string, string | number>) => string;

interface I18n {
  locale: Locale;
  setLocale: (l: Locale) => void;
  t: T;
  /** Translate a key that comes from the API (e.g. nav.<id>), falling back to the given text. */
  tx: (key: string, fallback: string, vars?: Record<string, string | number>) => string;
}

const Ctx = createContext<I18n | null>(null);

export function I18nProvider({ children, initial }: { children: ReactNode; initial?: Locale }) {
  const [locale, setLocaleState] = useState<Locale>(initial ?? storedLocale() ?? 'en');
  const [loaded, setLoaded] = useState<Partial<Record<Locale, Messages>>>({ en });
  // Until a language has loaded, English shows (no blank labels).
  const messages = loaded[locale] ?? en;

  useEffect(() => {
    document.documentElement.lang = locale;
    if (locale === 'en' || loaded[locale]) return;
    let live = true;
    loaders[locale]()
      .then((m) => live && setLoaded((all) => ({ ...all, [locale]: m.default })))
      .catch(() => undefined);
    return () => {
      live = false;
    };
  }, [locale, loaded]);

  const setLocale = useCallback((l: Locale) => {
    setLocaleState(l);
    try {
      localStorage.setItem(KEY, l);
    } catch {
      /* ignore */
    }
  }, []);

  const value = useMemo<I18n>(() => {
    const lookup = (key: string): string | undefined =>
      (messages as Record<string, string>)[key] ?? (en as Record<string, string>)[key];
    return {
      locale,
      setLocale,
      t: (key, vars) => format(lookup(key) ?? key, vars),
      tx: (key, fallback, vars) => format(lookup(key) ?? fallback, vars),
    };
  }, [locale, messages, setLocale]);

  return <Ctx.Provider value={value}>{children}</Ctx.Provider>;
}

export function useI18n(): I18n {
  const ctx = useContext(Ctx);
  if (!ctx) throw new Error('useI18n must be used inside I18nProvider');
  return ctx;
}
