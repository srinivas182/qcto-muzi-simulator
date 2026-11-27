import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react';
import { DEFAULT_BRANDING, type Branding, type PublicTenant } from '@simuwork/core';
import { api, setRequestContext } from './api';
import { prefs } from './storage';

export interface Academy {
  slug: string;
  name: string;
  branding: Branding;
}

interface AcademyState {
  academy: Academy | null;
  ready: boolean;
  /** Look up an academy by code (slug) and remember it. Throws ApiError(404) when unknown. */
  choose: (code: string) => Promise<Academy>;
  update: (branding: Branding) => void;
  forget: () => Promise<void>;
}

const Ctx = createContext<AcademyState | null>(null);
const KEY = 'academy';

/** Accepts "ubuntu", "UBUNTU ", "ubuntu.simuwork.co.za" or a SimuWork link to that academy. */
export function normaliseCode(input: string): string | null {
  let value = input.trim().toLowerCase();
  const url = /^https?:\/\/([^/]+)/.exec(value);
  if (url) value = url[1]!;
  if (value.includes('.')) value = value.split('.')[0]!;
  return /^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/.test(value) ? value : null;
}

/** Academy QR codes are links to the academy's address, e.g. https://ubuntu.simuwork.co.za/join. */
export function academyFromQr(data: string): string | null {
  const m = /^https:\/\/([a-z0-9-]+)\.(simuwork\.(?:co\.za|test)|localhost)(?::\d+)?(?:\/|$)/i.exec(data.trim());
  return m ? normaliseCode(m[1]!) : null;
}

export function AcademyProvider({ children }: { children: ReactNode }) {
  const [academy, setAcademy] = useState<Academy | null>(null);
  const [ready, setReady] = useState(false);

  useEffect(() => {
    prefs.get<Academy>(KEY).then((saved) => {
      if (saved) {
        setRequestContext({ tenant: saved.slug });
        setAcademy(saved);
      }
      setReady(true);
    });
  }, []);

  const choose = useCallback(async (code: string) => {
    const slug = normaliseCode(code);
    if (!slug) throw new Error('invalid');
    const tenant = (await api<{ data: PublicTenant }>('/tenant', { tenant: slug })).data;
    const next: Academy = { slug: tenant.slug, name: tenant.name, branding: tenant.branding ?? DEFAULT_BRANDING };
    setRequestContext({ tenant: next.slug });
    setAcademy(next);
    await prefs.set(KEY, next);
    return next;
  }, []);

  const update = useCallback((branding: Branding) => {
    setAcademy((current) => {
      if (!current) return current;
      const next = { ...current, branding };
      void prefs.set(KEY, next);
      return next;
    });
  }, []);

  const forget = useCallback(async () => {
    setRequestContext({ tenant: null });
    setAcademy(null);
    await prefs.remove(KEY);
  }, []);

  const value = useMemo(() => ({ academy, ready, choose, update, forget }), [academy, ready, choose, update, forget]);
  return <Ctx.Provider value={value}>{children}</Ctx.Provider>;
}

export function useAcademy(): AcademyState {
  const ctx = useContext(Ctx);
  if (!ctx) throw new Error('useAcademy must be used inside AcademyProvider');
  return ctx;
}
