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
          { path: 'admin/academy', lazy: page(() => import('../pages/admin/Academy')) },
          { path: 'admin/learners', lazy: page(() => import('../pages/admin/Learners')) },
          { path: 'admin/cohorts', lazy: page(() => import('../pages/admin/Cohorts')) },
          { path: 'admin/cohorts/:id', lazy: page(() => import('../pages/admin/CohortDetail')) },
          { path: 'admin/cohorts/:id/progress', lazy: page(() => import('../pages/admin/CohortProgress')) },
          { path: 'admin/licences', lazy: page(() => import('../pages/admin/Licences')) },
          { path: 'admin/billing', lazy: page(() => import('../pages/admin/Billing')) },
          { path: 'admin/programmes', lazy: page(() => import('../pages/provider/Programmes')) },
          { path: 'admin/programmes/:code', lazy: page(() => import('../pages/provider/ProgrammeView')) },
          { path: 'admin/guide', lazy: page(() => import('../pages/provider/Guide')) },
          { path: 'admin/guide/:slug', lazy: page(() => import('../pages/provider/Guide')) },
          { path: 'hq', lazy: page(() => import('../pages/shared/Dashboard')) },
          { path: 'hq/roles', lazy: page(() => import('../pages/shared/Roles')) },
          { path: 'hq/audit', lazy: page(() => import('../pages/shared/Audit')) },
          { path: 'hq/programmes', lazy: page(() => import('../pages/hq/Programmes')) },
          { path: 'hq/programmes/:code', lazy: page(() => import('../pages/hq/ProgrammeDetail')) },
          { path: 'hq/programmes/:code/lessons/:id', lazy: page(() => import('../pages/hq/LessonStudio')) },
          { path: 'hq/programmes/:code/scenarios/:id', lazy: page(() => import('../pages/hq/ScenarioStudio')) },
          { path: 'hq/studio/scenarios', lazy: async () => { const S = (await import('../pages/hq/StudioHome')).default; return { Component: () => <S kind="scenario" /> }; } },
          { path: 'hq/studio/courses', lazy: async () => { const S = (await import('../pages/hq/StudioHome')).default; return { Component: () => <S kind="lesson" /> }; } },
          { path: 'hq/providers', lazy: page(() => import('../pages/hq/Providers')) },
          { path: 'hq/packages', lazy: page(() => import('../pages/hq/Packages')) },
          { path: 'hq/billing', lazy: page(() => import('../pages/hq/Billing')) },
          { path: 'hq/providers/:id', lazy: page(() => import('../pages/hq/ProviderDetail')) },
          { path: 'learn', lazy: page(() => import('../pages/learn/LearnHome')) },
          { path: 'learn/join/:code', lazy: page(() => import('../pages/learn/JoinCohort')) },
          { path: 'learn/details', lazy: page(() => import('../pages/learn/MyDetails')) },
          { path: 'learn/enrolments/:eid', lazy: page(() => import('../pages/learn/Programme')) },
          { path: 'learn/enrolments/:eid/checklist', lazy: page(() => import('../pages/learn/Checklist')) },
          { path: 'learn/enrolments/:eid/lessons/:aid', lazy: page(() => import('../pages/learn/Lesson')) },
          { path: 'learn/enrolments/:eid/simulators/:aid', lazy: page(() => import('../pages/learn/Simulator')) },
          { path: '*', element: <Fallback /> },
        ],
      },
    ],
  },
];
