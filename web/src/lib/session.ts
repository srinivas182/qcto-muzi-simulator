import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, ApiError } from './api';
import { AREA, areaPrefix } from './area';
import type { PublicTenant, Session } from '@simuwork/core';

export const sessionKey = ['session', AREA] as const;

/** The provider behind this address (public; loads branding before sign-in). */
export function usePublicTenant() {
  return useQuery({
    queryKey: ['tenant'],
    queryFn: async () => (await api<{ data: PublicTenant }>('/tenant')).data,
    enabled: AREA === 'tenant',
    staleTime: 5 * 60_000,
    retry: 1,
  });
}

/** Signed-in session, or null when signed out. */
export function useSession() {
  return useQuery({
    queryKey: sessionKey,
    queryFn: async (): Promise<Session | null> => {
      try {
        return (await api<{ data: Session }>(areaPrefix() + '/session')).data;
      } catch (e) {
        if (e instanceof ApiError && (e.status === 401 || e.status === 403 || e.status === 404)) return null;
        throw e;
      }
    },
    staleTime: 60_000,
    retry: (count, e) => !(e instanceof ApiError) && count < 2,
  });
}

export interface SignInResult {
  two_factor_required?: boolean;
  challenge_token?: string;
}

export function useSignOut() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: () => api(areaPrefix() + '/auth/logout', { method: 'POST' }),
    onSettled: () => {
      qc.setQueryData(sessionKey, null);
      qc.removeQueries({ predicate: (q) => q.queryKey[0] !== 'tenant' && q.queryKey[0] !== 'session' });
    },
  });
}

export function useRefreshSession() {
  const qc = useQueryClient();
  return () => qc.invalidateQueries({ queryKey: sessionKey });
}

/** Screens that exist today; others in the menu show "on the way" until their sprint. */
const BUILT = ['/hq', '/admin', '/learn', '/admin/roles', '/admin/audit', '/admin/branding', '/hq/roles', '/hq/audit', '/hq/programmes', '/hq/providers', '/admin/programmes', '/admin/guide', '/admin/academy', '/admin/licences', '/hq/packages', '/admin/billing', '/hq/billing', '/admin/learners', '/admin/cohorts'];

/** Where a person lands: their first menu item that is built, else their first menu item. */
export function homePath(session: Session): string {
  const paths = session.navigation.map((i) => i.path);
  return paths.find((p) => BUILT.includes(p)) ?? paths[0] ?? (session.area === 'platform' ? '/hq' : '/learn');
}

export const can = (session: Session | null | undefined, permission: string) =>
  !!session?.permissions.includes(permission);
