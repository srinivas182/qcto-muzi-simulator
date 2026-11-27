import { render } from '@testing-library/react';
import { createMemoryRouter, RouterProvider } from 'react-router';
import { vi } from 'vitest';
import { createQueryClient, Providers } from '../app/Providers';
import { routes } from '../app/routes';
import type { NavItem, Session } from '../lib/types';

export const branding = {
  display_name: 'Ubuntu Skills Academy', logo_url: null, primary: '#0F766E', accent: '#C9A24B', nav: '#0D1230', default_locale: 'en',
};

export function nav(...ids: string[]): NavItem[] {
  return ids.map((id) => ({ id, section: id.startsWith('learn') ? 'Learn' : 'Account', label: id, icon: 'house', path: '/' + id.replace(/\./g, '/') }));
}

export function session(overrides: Partial<Session> = {}): Session {
  return {
    area: 'tenant',
    user: { id: 'u1', name: 'Nomsa Dlamini', email: 'nomsa@ubuntuskills.co.za', phone: null, locale: 'en', tenant_id: 't1', is_platform_staff: false, two_factor_enabled: false, consent_required: [] },
    permissions: ['tenant.dashboard.view', 'roles.manage_tenant', 'audit.view_tenant', 'branding.manage'],
    navigation: nav('admin', 'admin.roles', 'admin.audit', 'admin.branding', 'admin.billing'),
    tenant: { id: 't1', name: 'Ubuntu Skills Academy', slug: 'ubuntu', branding },
    support: null,
    locales: { en: 'English' },
    ...overrides,
  };
}

/** Fake API: answers by path; anything unknown is a 404. */
export function mockApi(responses: Record<string, unknown | ((init?: RequestInit) => unknown)>, signedIn: Session | null = null) {
  const fetchMock = vi.fn(async (input: string, init?: RequestInit) => {
    const path = input.replace(/^.*\/api\/v1/, '').split('?')[0]!;
    if (path === '/session' || path === '/platform/session') {
      return signedIn ? json({ data: signedIn }) : json({ message: 'Unauthenticated.' }, 401);
    }
    if (path === '/tenant') return json({ data: { id: 't1', name: 'Ubuntu Skills Academy', slug: 'ubuntu', status: 'active', branding, locales: {} } });
    const r = responses[path];
    if (r === undefined) return json({ message: 'Not found' }, 404);
    return json(typeof r === 'function' ? (r as (i?: RequestInit) => unknown)(init) : r);
  });
  vi.stubGlobal('fetch', fetchMock);
  return fetchMock;
}

function json(body: unknown, status = 200) {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

export function renderAt(path: string) {
  const router = createMemoryRouter(routes, { initialEntries: [path] });
  return render(
    <Providers client={createQueryClient()}>
      <RouterProvider router={router} />
    </Providers>,
  );
}
