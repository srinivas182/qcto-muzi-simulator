import { fireEvent, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { mockApi, nav, renderAt, session } from './harness';

afterEach(() => vi.unstubAllGlobals());

describe('app shell', () => {
  it('sends signed-out people to sign in, remembering where they were going', async () => {
    mockApi({});
    renderAt('/admin/roles');
    expect(await screen.findByRole('heading', { name: 'Welcome back' })).toBeInTheDocument();
  });

  it('shows only the menu the role allows', async () => {
    mockApi({}, session({ navigation: nav('teach', 'teach.attendance') }));
    renderAt('/teach');
    expect(await screen.findAllByRole('link', { name: 'Attendance' })).not.toHaveLength(0);
    expect(screen.queryByRole('link', { name: 'Billing' })).not.toBeInTheDocument();
  });

  it('blocks pages outside the menu', async () => {
    mockApi({}, session({ navigation: nav('teach') }));
    renderAt('/admin/billing');
    expect(await screen.findByText("You don't have access to this page")).toBeInTheDocument();
  });

  it('shows menu items that are not built yet as "on the way"', async () => {
    mockApi({}, session({ navigation: nav('admin', 'admin.reports') }));
    renderAt('/admin/reports');
    expect(await screen.findByText('On the way')).toBeInTheDocument();
  });

  it('opens quick search with Ctrl+K and finds pages', async () => {
    mockApi({}, session());
    renderAt('/admin');
    await screen.findByText(/Good (morning|afternoon|evening), Nomsa/);
    fireEvent.keyDown(document, { key: 'k', ctrlKey: true });
    const box = await screen.findByRole('combobox');
    fireEvent.change(box, { target: { value: 'audit' } });
    expect(screen.getByRole('option', { name: /Audit log/ })).toBeInTheDocument();
  });

  it('shows the HQ support banner to the provider', async () => {
    mockApi({}, session({ support: { id: 's1', staff_name: 'Thabo', reason: 'Checking the register export', expires_at: new Date(Date.now() + 1800e3).toISOString() } }));
    renderAt('/admin');
    expect(await screen.findByText('HQ support view by Thabo · read-only')).toBeInTheDocument();
  });

  it('asks for POPIA consent before anything else', async () => {
    const calls = mockApi({ '/auth/consents': { data: {} } }, session({ user: { ...session().user, consent_required: ['terms', 'popia_processing'] } }));
    renderAt('/admin');
    const dialog = await screen.findByRole('dialog', { name: 'Before you continue' });
    const button = screen.getByRole('button', { name: 'Agree and continue' });
    expect(button).toBeDisabled();
    screen.getAllByRole('checkbox').forEach((c) => fireEvent.click(c));
    fireEvent.click(button);
    await waitFor(() => expect(calls.mock.calls.filter(([u]) => String(u).endsWith('/auth/consents'))).toHaveLength(2));
    expect(dialog).toBeInTheDocument();
  });

  it('gives learners a bottom tab bar instead of the admin sidebar', async () => {
    mockApi({}, session({ navigation: nav('learn', 'learn.simulate', 'learn.courses', 'learn.progress') }));
    renderAt('/learn');
    await screen.findByText(/Ready to practise/);
    await waitFor(() => expect(document.documentElement.dataset.tabbar).toBe(''));
    expect(screen.queryByRole('button', { name: 'Open menu' })).not.toBeInTheDocument();
  });

  it('signs in with email and password', async () => {
    let signedIn = false;
    const s = session();
    const fetchMock = vi.fn(async (input: string, init?: RequestInit) => {
      const path = String(input).replace(/^.*\/api\/v1/, '');
      if (path === '/tenant') return new Response(JSON.stringify({ data: { branding: s.tenant!.branding } }), { status: 200 });
      if (path === '/session') return signedIn ? new Response(JSON.stringify({ data: s })) : new Response('{}', { status: 401 });
      if (path === '/auth/login') {
        expect(JSON.parse(String(init?.body))).toEqual({ email: 'nomsa@ubuntuskills.co.za', password: 'correct-horse' });
        expect(((init?.headers ?? {}) as Record<string, string>)['X-Requested-With']).toBe('XMLHttpRequest');
        signedIn = true;
        return new Response(JSON.stringify({ token_type: 'cookie', user: s.user }));
      }
      return new Response('{}', { status: 404 });
    });
    vi.stubGlobal('fetch', fetchMock);
    renderAt('/sign-in');
    fireEvent.click(await screen.findByRole('tab', { name: 'Email' }));
    fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'nomsa@ubuntuskills.co.za' } });
    fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'correct-horse' } });
    fireEvent.click(screen.getByRole('button', { name: 'Sign in' }));
    expect(await screen.findByText(/Good (morning|afternoon|evening), Nomsa/)).toBeInTheDocument();
  });
});
