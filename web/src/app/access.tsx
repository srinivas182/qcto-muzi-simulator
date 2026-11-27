import type { ReactNode } from 'react';
import { Outlet, useLocation } from 'react-router';
import { activeItem } from '../components/shell/nav';
import { useI18n } from '../lib/i18n';
import { useSession } from '../lib/session';
import type { Session } from '../lib/types';
import en from '../locales/en';
import { ComingSoon, NoAccess, NotFound } from './states';

/** Every menu path the platform knows, derived from nav.<id> (id "admin.roles" → "/admin/roles"). */
export const KNOWN_PATHS = new Set(
  Object.keys(en)
    .filter((k) => k.startsWith('nav.'))
    .map((k) => '/' + k.slice(4).replace(/\./g, '/')),
);

/** Shows a page only if it is in the person's menu (the API enforces the same rule). */
export function AccessGuard({ session, children }: { session: Session; children?: ReactNode }) {
  const { pathname } = useLocation();
  if (pathname === '/') return null;
  const allowed = session.navigation.some((i) => i.path === pathname);
  if (KNOWN_PATHS.has(pathname) && !allowed) return <NoAccess />;
  return <>{children ?? <Outlet />}</>;
}

/** Menu items whose screens are built in later sprints. */
export function Fallback() {
  const session = useSignedIn();
  const { pathname } = useLocation();
  const { tx } = useI18n();
  const item = activeItem(session.navigation, pathname);
  if (item && item.path === pathname) return <ComingSoon title={tx('nav.' + item.id, item.label)} />;
  return <NotFound />;
}

/** The signed-in session inside the shell (always present there). */
export function useSignedIn(): Session {
  const { data } = useSession();
  if (!data) throw new Error('useSignedIn used outside the signed-in shell');
  return data;
}
