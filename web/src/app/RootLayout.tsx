import { Outlet } from 'react-router';
import { PlatformFooter } from '@simuwork/ui';

/**
 * Root of every route. PLATFORM RULE: the MCS footer is rendered here, outside anything a
 * training provider controls, so no page can exist without it. Tested in footer.test.tsx.
 */
export function RootLayout() {
  return (
    <div className="flex min-h-dvh flex-col">
      <div className="flex flex-1 flex-col">
        <Outlet />
      </div>
      <PlatformFooter />
    </div>
  );
}
