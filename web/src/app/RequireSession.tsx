import { Navigate, useLocation } from 'react-router';
import { AppShell } from '../components/shell/AppShell';
import { useSession } from '../lib/session';
import { OnHold } from './OnHold';
import { RouteError, ShellSkeleton } from './states';

export function RequireSession() {
  const session = useSession();
  const location = useLocation();

  if (session.isPending) return <ShellSkeleton />;
  if (session.isError) return <RouteError />;
  if (!session.data) {
    const next = location.pathname === '/' ? '' : `?next=${encodeURIComponent(location.pathname + location.search)}`;
    return <Navigate to={`/sign-in${next}`} replace />;
  }
  if (session.data.tenant?.on_hold) return <OnHold academy={session.data.tenant.name} />;
  return <AppShell session={session.data} />;
}

