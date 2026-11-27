import { Navigate, useLocation } from 'react-router';
import { AppShell } from '../components/shell/AppShell';
import { useSession } from '../lib/session';
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
  return <AppShell session={session.data} />;
}

