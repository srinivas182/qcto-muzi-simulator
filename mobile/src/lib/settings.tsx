import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react';
import { prefs } from './storage';

export type Appearance = 'system' | 'light' | 'dark';

interface Settings {
  appearance: Appearance;
  setAppearance: (a: Appearance) => void;
  /** Download big files (simulators, videos) on Wi-Fi only. On by default: mobile data is expensive. */
  dataSaver: boolean;
  setDataSaver: (on: boolean) => void;
}

const Ctx = createContext<Settings | null>(null);

export function SettingsProvider({ children }: { children: ReactNode }) {
  const [appearance, setA] = useState<Appearance>('system');
  const [dataSaver, setD] = useState(true);

  useEffect(() => {
    prefs.get<Appearance>('appearance').then((a) => a && setA(a));
    prefs.get<boolean>('dataSaver').then((d) => d !== null && setD(d));
  }, []);

  const setAppearance = useCallback((a: Appearance) => {
    setA(a);
    void prefs.set('appearance', a);
  }, []);
  const setDataSaver = useCallback((on: boolean) => {
    setD(on);
    void prefs.set('dataSaver', on);
  }, []);

  const value = useMemo(() => ({ appearance, setAppearance, dataSaver, setDataSaver }), [appearance, setAppearance, dataSaver, setDataSaver]);
  return <Ctx.Provider value={value}>{children}</Ctx.Provider>;
}

export function useSettings(): Settings {
  const ctx = useContext(Ctx);
  if (!ctx) throw new Error('useSettings must be used inside SettingsProvider');
  return ctx;
}
