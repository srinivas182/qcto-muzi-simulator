import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import type { Session } from '@simuwork/core';
import { api, ApiError, deviceName, setRequestContext } from './api';
import { useAcademy } from './academy';
import { secure, TOKEN_KEY } from './storage';

interface AuthState {
  token: string | null;
  ready: boolean;
  session: Session | null | undefined;
  /** Store a token returned by a sign-in endpoint. */
  signedIn: (token: string) => Promise<void>;
  signOut: () => Promise<void>;
}

export interface TokenResponse {
  token?: string;
  two_factor_required?: boolean;
  challenge_token?: string;
}

const Ctx = createContext<AuthState | null>(null);

/** Extra fields every mobile sign-in request sends (long-lived device token). */
export const mobileClient = () => ({ client: 'mobile', device_name: deviceName() });

export function AuthProvider({ children }: { children: ReactNode }) {
  const qc = useQueryClient();
  const { academy, update } = useAcademy();
  const [token, setToken] = useState<string | null>(null);
  const [ready, setReady] = useState(false);

  useEffect(() => {
    secure.get(TOKEN_KEY).then((saved) => {
      setRequestContext({ token: saved });
      setToken(saved);
      setReady(true);
    });
  }, []);

  const session = useQuery({
    queryKey: ['session', academy?.slug],
    enabled: ready && !!token && !!academy,
    queryFn: async () => (await api<{ data: Session }>('/session')).data,
    // Offline: keep showing the last known session (restored from the persisted cache).
    retry: (count, e) => !(e instanceof ApiError && e.status !== 0) && count < 2,
    staleTime: 60_000,
    gcTime: 7 * 24 * 3600_000,
  });

  const clear = useCallback(async () => {
    await secure.remove(TOKEN_KEY);
    setRequestContext({ token: null });
    setToken(null);
    qc.clear();
  }, [qc]);

  // Token revoked or expired: sign out locally.
  useEffect(() => {
    if (session.error instanceof ApiError && session.error.status === 401) void clear();
  }, [session.error, clear]);

  useEffect(() => {
    const b = session.data?.tenant?.branding;
    if (b && JSON.stringify(b) !== JSON.stringify(academy?.branding)) update(b);
  }, [session.data?.tenant?.branding, academy?.branding, update]);

  const signedIn = useCallback(async (next: string) => {
    await secure.set(TOKEN_KEY, next);
    setRequestContext({ token: next });
    setToken(next);
  }, []);

  const signOut = useCallback(async () => {
    try {
      await api('/auth/logout', { method: 'POST' });
    } catch {
      /* offline or already revoked: still sign out on this device */
    }
    await clear();
  }, [clear]);

  const value = useMemo<AuthState>(
    () => ({ token, ready, session: token ? (session.data ?? (session.isPending ? undefined : null)) : null, signedIn, signOut }),
    [token, ready, session.data, session.isPending, signedIn, signOut],
  );
  return <Ctx.Provider value={value}>{children}</Ctx.Provider>;
}

export function useAuth(): AuthState {
  const ctx = useContext(Ctx);
  if (!ctx) throw new Error('useAuth must be used inside AuthProvider');
  return ctx;
}
