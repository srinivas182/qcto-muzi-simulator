import { fireEvent, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { ProviderDetails, ProviderForHq } from '@simuwork/core';
import { mockApi, nav, renderAt, session } from './harness';

afterEach(() => vi.unstubAllGlobals());

const details: ProviderDetails = {
  id: 'p-1', name: 'Ubuntu Skills Academy', slug: 'ubuntu', status: 'trial', programme_additions: false,
  accreditation: { state: 'expiring', days_left: 30 }, created_at: '2026-11-01T08:00:00Z',
  legal_name: 'Ubuntu Skills (Pty) Ltd', registration_number: '2019/123456/07', vat_number: '4123456789',
  qcto_sdp_code: null, accreditation_number: 'SDP0712/24-00321', accreditation_expires_at: '2026-12-28',
  contact_email: 'hello@ubuntu.test', contact_phone: null, billing_email: null, address: { city: 'Durban' },
  payment_terms: { mode: 'pay_first', days: 0 }, status_reason: null, status_changed_at: null,
};
const forHq: ProviderForHq = { ...details, centres: 1, people: [{ id: 'u1', name: 'Nomsa Dlamini', email: 'nomsa@ubuntu.test', phone: null, status: 'active', roles: ['TP Admin'], last_login_at: null }], domains: [{ host: 'ubuntu.simuwork.co.za', type: 'subdomain', primary: true, verified: true }] };
const hq = (permissions: string[]) => session({ area: 'platform', tenant: null, permissions, navigation: nav('hq', 'hq.providers') });
const body = (calls: ReturnType<typeof mockApi>, suffix: string) => {
  const call = calls.mock.calls.find(([u, i]) => String(u).split('?')[0]!.endsWith(suffix) && (i as RequestInit | undefined)?.method !== undefined && (i as RequestInit).method !== 'GET');
  return JSON.parse(String((call?.[1] as RequestInit | undefined)?.body ?? 'null')) as Record<string, unknown> | null;
};

describe('HQ providers', () => {
  it('onboards a provider and shows the invitation link to share', async () => {
    const calls = mockApi({
      '/platform/providers': (init?: RequestInit) => (init?.method === 'POST' ? { data: { ...forHq, name: 'Khanya Skills Institute' }, invite_url: 'https://khanya.simuwork.co.za/invite/abc' } : { data: [] }),
    }, hq(['providers.view', 'providers.manage']));
    renderAt('/hq/providers');

    fireEvent.click(await screen.findByRole('button', { name: 'New provider' }));
    fireEvent.change(screen.getByLabelText('Academy name'), { target: { value: 'Khanya Skills Institute' } });
    expect(screen.getByLabelText('Web address')).toHaveValue('khanya-skills-institute');
    fireEvent.change(screen.getByLabelText('Full name'), { target: { value: 'Zanele Khumalo' } });
    fireEvent.change(screen.getByLabelText('Email address'), { target: { value: 'zanele@khanya.test' } });
    fireEvent.click(screen.getByRole('button', { name: 'Create provider' }));

    expect(await screen.findByDisplayValue('https://khanya.simuwork.co.za/invite/abc')).toBeInTheDocument();
    expect(body(calls, '/platform/providers')).toMatchObject({ name: 'Khanya Skills Institute', slug: 'khanya-skills-institute', admin_email: 'zanele@khanya.test' });
  });

  it('suspends a provider only with a reason, and explains the effect', async () => {
    const calls = mockApi({
      '/platform/providers/p-1': { data: forHq },
      '/platform/providers/p-1/status': { data: { ...forHq, status: 'suspended', status_reason: 'Invoice 30 days overdue' } },
    }, hq(['providers.view', 'providers.manage']));
    renderAt('/hq/providers/p-1');

    fireEvent.click(await screen.findByRole('button', { name: 'Suspend' }));
    expect(screen.getByText(/Learners keep learning/)).toBeInTheDocument();
    const confirm = screen.getAllByRole('button', { name: 'Suspend' }).at(-1)!;
    expect(confirm).toBeDisabled();
    fireEvent.change(screen.getByLabelText('Reason (kept in the audit log)'), { target: { value: 'Invoice 30 days overdue' } });
    fireEvent.click(confirm);

    await waitFor(() => expect(body(calls, '/status')).toEqual({ status: 'suspended', reason: 'Invoice 30 days overdue' }));
    expect(await screen.findByText('Suspended')).toBeInTheDocument();
  });

  it('shows details read-only to people who can only view providers', async () => {
    mockApi({ '/platform/providers/p-1': { data: forHq } }, hq(['providers.view']));
    renderAt('/hq/providers/p-1');
    expect(await screen.findByLabelText('VAT number')).toHaveAttribute('readonly');
    expect(screen.queryByRole('button', { name: 'Suspend' })).not.toBeInTheDocument();
  });
});

describe('Academy details (provider)', () => {
  it('warns about accreditation expiry and saves only contact and billing changes', async () => {
    const calls = mockApi({ '/academy': (init?: RequestInit) => ({ data: init?.method === 'PATCH' ? { ...details, billing_email: 'accounts@ubuntu.test' } : details }) },
      session({ permissions: ['academy.manage'], navigation: nav('admin', 'admin.academy') }));
    renderAt('/admin/academy');

    expect(await screen.findByText(/expires in 30 days/)).toBeInTheDocument();
    expect(screen.getByLabelText('SDP accreditation number')).toHaveAttribute('readonly');
    fireEvent.change(screen.getByLabelText('Billing email (invoices go here)'), { target: { value: 'accounts@ubuntu.test' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));

    await waitFor(() => expect(body(calls, '/academy')).toEqual({ billing_email: 'accounts@ubuntu.test' }));
  });

  it('puts staff of a suspended provider on hold, with the locked footer still shown', async () => {
    mockApi({}, session({ tenant: { id: 't1', name: 'Ubuntu Skills Academy', slug: 'ubuntu', status: 'suspended', on_hold: true, branding: session().tenant!.branding } }));
    renderAt('/admin');
    expect(await screen.findByRole('heading', { name: 'Your academy’s account is on hold' })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /Mayura Consultancy Services/ })).toHaveAttribute('href', 'https://www.mayuraconsultancy.com');
  });
});
