import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react';
import { getLocales } from 'expo-localization';
import { format, isLocale, pickLocale, type Locale, type Messages } from '@simuwork/core';
import af from '@simuwork/core/locales/af';
import en, { type MessageKey } from '@simuwork/core/locales/en';
import xh from '@simuwork/core/locales/xh';
import zu from '@simuwork/core/locales/zu';
import { prefs } from './storage';

/**
 * Same catalogue as the web app (packages/core). On the phone every language ships inside the app
 * (a few KB each), so switching works instantly and offline.
 */
const CATALOGUES: Record<Locale, Messages> = { en, zu, af, xh };

interface I18n {
  locale: Locale;
  setLocale: (l: Locale) => void;
  t: (key: MessageKey, vars?: Record<string, string | number>) => string;
  tx: (key: string, fallback: string, vars?: Record<string, string | number>) => string;
  /** True once the person chose a language themselves (it then wins over academy defaults). */
  chosen: boolean;
}

const Ctx = createContext<I18n | null>(null);

export function I18nProvider({ children }: { children: ReactNode }) {
  const [locale, setLocaleState] = useState<Locale>(() => pickLocale(getLocales().map((l) => l.languageTag)));
  const [chosen, setChosen] = useState(false);

  useEffect(() => {
    prefs.get<string>('locale').then((saved) => {
      if (isLocale(saved)) {
        setLocaleState(saved);
        setChosen(true);
      }
    });
  }, []);

  const setLocale = useCallback((l: Locale) => {
    setLocaleState(l);
    setChosen(true);
    void prefs.set('locale', l);
  }, []);

  const value = useMemo<I18n>(() => {
    const messages = CATALOGUES[locale] as Record<string, string>;
    const lookup = (key: string) => messages[key] ?? (en as Record<string, string>)[key];
    return {
      locale,
      setLocale,
      chosen,
      t: (key, vars) => format(lookup(key) ?? key, vars),
      tx: (key, fallback, vars) => format(lookup(key) ?? fallback, vars),
    };
  }, [locale, setLocale, chosen]);

  return <Ctx.Provider value={value}>{children}</Ctx.Provider>;
}

export function useI18n(): I18n {
  const ctx = useContext(Ctx);
  if (!ctx) throw new Error('useI18n must be used inside I18nProvider');
  return ctx;
}
