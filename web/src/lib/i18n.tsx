import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react';
import en, { type MessageKey, type Messages } from '@simuwork/core/locales/en';

/**
 * Tiny i18n: English ships with the app; other languages load on demand (a few KB each),
 * so changing language never slows the first page.
 */
import { format, isLocale, type Locale } from '@simuwork/core';

export { format, isLocale, LOCALES, sectionKey, type Locale } from '@simuwork/core';

type Part = () => Promise<{ default: Record<string, string> }>;

/** Each language loads in two parts (see packages/core/src/locales/parts.ts): learners never download staff text. */
const loaders: Record<Exclude<Locale, 'en'>, { learner: Part; staff: Part; hq: Part }> = {
  zu: { learner: () => import('virtual:locale/zu/learner'), staff: () => import('virtual:locale/zu/staff'), hq: () => import('virtual:locale/zu/hq') },
  af: { learner: () => import('virtual:locale/af/learner'), staff: () => import('virtual:locale/af/staff'), hq: () => import('virtual:locale/af/hq') },
  xh: { learner: () => import('virtual:locale/xh/learner'), staff: () => import('virtual:locale/xh/staff'), hq: () => import('virtual:locale/xh/hq') },
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
  /** Staff screens call this so the staff part of the language loads too. */
  needStaff: () => void;
  /** HQ screens call this so the HQ part (content studios) loads too. */
  needHq: () => void;
}

const Ctx = createContext<I18n | null>(null);

export function I18nProvider({ children, initial }: { children: ReactNode; initial?: Locale }) {
  const [locale, setLocaleState] = useState<Locale>(initial ?? storedLocale() ?? 'en');
  const [loaded, setLoaded] = useState<Record<string, Record<string, string>>>({});
  const [staff, setStaff] = useState(false);
  const [hq, setHq] = useState(false);
  // Until a part has loaded, English shows (no blank labels).
  const messages = useMemo(
    () => (locale === 'en' ? en : { ...loaded[`${locale}/learner`], ...loaded[`${locale}/staff`], ...loaded[`${locale}/hq`] }) as Messages,
    [locale, loaded],
  );

  useEffect(() => {
    document.documentElement.lang = locale;
    if (locale === 'en') return;
    let live = true;
    const wanted: ('learner' | 'staff' | 'hq')[] = ['learner', ...(staff ? ['staff' as const] : []), ...(hq ? ['hq' as const] : [])];
    for (const part of wanted) {
      const key = `${locale}/${part}`;
      if (loaded[key]) continue;
      loaders[locale][part]()
        .then((m) => live && setLoaded((all) => ({ ...all, [key]: m.default })))
        .catch(() => undefined);
    }
    return () => {
      live = false;
    };
  }, [locale, loaded, staff, hq]);

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
      needStaff: () => setStaff(true),
      needHq: () => setHq(true),
    };
  }, [locale, messages, setLocale]);

  return <Ctx.Provider value={value}>{children}</Ctx.Provider>;
}

export function useI18n(): I18n {
  const ctx = useContext(Ctx);
  if (!ctx) throw new Error('useI18n must be used inside I18nProvider');
  return ctx;
}
