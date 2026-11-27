import type { RouteObject } from 'react-router';
import { Fallback } from './access';
import { RequireSession } from './RequireSession';
import { RootLayout } from './RootLayout';
import { RouteError, ShellSkeleton } from './states';

type Module = { default: React.ComponentType };
const page = (load: () => Promise<Module>) => async () => ({ Component: (await load()).default });

/**
 * Every route is a child of RootLayout, which renders the locked MCS footer.
 * Pages load on demand so the first paint only ships the shell.
 */
export const routes: RouteObject[] = [
  {
    element: <RootLayout />,
    errorElement: <RouteError />,
    hydrateFallbackElement: <ShellSkeleton />,
    children: [
      { path: 'sign-in', lazy: page(() => import('../pages/auth/SignIn')) },
      { path: 'forgot-password', lazy: page(() => import('../pages/auth/ForgotPassword')) },
      {
        element: <RequireSession />,
        children: [
          { index: true, element: null },
          { path: 'admin', lazy: page(() => import('../pages/shared/Dashboard')) },
          { path: 'admin/roles', lazy: page(() => import('../pages/shared/Roles')) },
          { path: 'admin/audit', lazy: page(() => import('../pages/shared/Audit')) },
          { path: 'admin/branding', lazy: page(() => import('../pages/admin/Branding')) },
          { path: 'admin/programmes', lazy: page(() => import('../pages/provider/Programmes')) },
          { path: 'admin/programmes/:code', lazy: page(() => import('../pages/provider/ProgrammeView')) },
          { path: 'admin/guide', lazy: page(() => import('../pages/provider/Guide')) },
          { path: 'admin/guide/:slug', lazy: page(() => import('../pages/provider/Guide')) },
          { path: 'hq', lazy: page(() => import('../pages/shared/Dashboard')) },
          { path: 'hq/roles', lazy: page(() => import('../pages/shared/Roles')) },
          { path: 'hq/audit', lazy: page(() => import('../pages/shared/Audit')) },
          { path: 'hq/programmes', lazy: page(() => import('../pages/hq/Programmes')) },
          { path: 'hq/programmes/:code', lazy: page(() => import('../pages/hq/ProgrammeDetail')) },
          { path: 'hq/providers', lazy: page(() => import('../pages/hq/Providers')) },
          { path: 'learn', lazy: page(() => import('../pages/learn/LearnHome')) },
          { path: '*', element: <Fallback /> },
        ],
      },
    ],
  },
];
