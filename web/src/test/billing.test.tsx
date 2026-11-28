import { fireEvent, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { BillingSettingsData, InvoiceDetail, LicencePoolItem, PackageItem } from '@simuwork/core';
import { mockApi, nav, renderAt, session } from './harness';

afterEach(() => vi.unstubAllGlobals());

const pkg: PackageItem = {
  id: 'k1', code: 'ts-12', name: 'Technical Support — 12 months', description: null, programme: { code: 'technical-support', title: 'Technical Support', prefix: 'TS' },
  includes_simulators: true, includes_courses: true, billing: 'term', term_months: 12, min_seats: 10, status: 'published', published_at: null,
  prices: [{ from_seats: 10, unit_price_cents: 250000 }, { from_seats: 50, unit_price_cents: 210000 }],
};
const account = { blocked: false, open_invoices: 0, owed_cents: 0, overdue_cents: 0, payment_terms: { mode: 'pay_first' as const, days: 0 } };
const pool = (over: Partial<LicencePoolItem>): LicencePoolItem => ({
  id: 'p1', package: { code: 'ts-12', name: 'Technical Support — 12 months', min_seats: 10, includes_simulators: true, includes_courses: true },
  programme: { code: 'technical-support', title: 'Technical Support', prefix: 'TS' }, billing: 'term', term_months: 12, unit_price_cents: 210000,
  seats: 60, seats_used: 12, seats_free: 48, seats_not_started: 2, next_return_on: '2027-02-17', seats_returned: 1, seats_next_month: null, starts_on: '2027-01-01', ends_on: '2027-12-31', days_left: 300, cancel_at_period_end: false, status: 'active', ...over,
});
const invoice: InvoiceDetail = {
  id: 'i1', number: 'INV-000001', kind: 'order', issue_date: '2027-01-01', due_date: '2027-01-08', period_start: null, period_end: null,
  subtotal_cents: 12600000, vat_rate: 15, vat_cents: 1890000, total_cents: 14490000, paid_cents: 0, balance_cents: 14490000, status: 'issued', overdue_days: 0,
  buyer_name: 'Ubuntu Skills Academy', tenant_id: 't1', description: 'TS-12: 60 seats for 12 months',
  lines: [{ description: 'TS-12: 60 seats for 12 months', quantity: 60, unit_cents: 210000, amount_cents: 12600000 }],
  seller: { name: 'Muzi Skills Tech (Pty) Ltd', vat_number: '4987654321', registration_number: null, email: null, phone: null, bank: { bank: 'FNB', account_number: '62812345678', branch_code: '250655' } },
  buyer: { name: 'Ubuntu Skills Academy (Pty) Ltd', trading_name: 'Ubuntu Skills Academy', vat_number: null, email: null }, payments: [], credit_note: null,
};
const bodyOf = (calls: ReturnType<typeof mockApi>, suffix: string) => {
  const c = calls.mock.calls.find(([u, i]) => String(u).split('?')[0]!.endsWith(suffix) && (i as RequestInit | undefined)?.method !== undefined && (i as RequestInit).method !== 'GET');
  return JSON.parse(String((c?.[1] as RequestInit | undefined)?.body ?? 'null')) as unknown;
};

describe('Provider licences and invoices', () => {
  it('buys seats from the shop at the shown price', async () => {
    const calls = mockApi({
      '/shop': { data: [pkg], vat_rate: 15 },
      '/licences': { data: [], past: [], account },
      '/orders': { invoice: { ...invoice }, pool: pool({ status: 'pending', seats: 0, seats_next_month: null }) },
    }, session({ permissions: ['licences.manage'], navigation: nav('admin', 'admin.licences') }));
    renderAt('/admin/licences');

    fireEvent.click(await screen.findByRole('tab', { name: 'Shop' }));
    fireEvent.click(await screen.findByRole('button', { name: 'Get a price' }));
    fireEvent.change(screen.getByLabelText('How many learners?'), { target: { value: '60' } });
    fireEvent.click(screen.getByRole('button', { name: /^Buy for R\s144\s900,00$/ }));

    await waitFor(() => expect(bodyOf(calls, '/orders')).toEqual({ package: 'ts-12', seats: 60 }));
    expect(await screen.findByText(/^Invoice INV-000001 for R\s144\s900,00 created$/)).toBeInTheDocument();
  });

  it('shows seat use and end date, and stops buying when the account is blocked', async () => {
    mockApi({ '/licences': { data: [pool({})], past: [], account: { ...account, blocked: true } } }, session({ permissions: ['licences.manage'], navigation: nav('admin', 'admin.licences') }));
    renderAt('/admin/licences');

    expect(await screen.findByText('12 of 60 seats in use')).toBeInTheDocument();
    expect(screen.getByText(/Ends .*\(300 days left\)/)).toBeInTheDocument();
    expect(screen.getByText(/2 not started — seats come back from/)).toBeInTheDocument();
    expect(screen.getByText('1 seats returned to the pool')).toBeInTheDocument();
    expect(screen.getByRole('alert')).toHaveTextContent('more than 30 days overdue');
    expect(screen.getByRole('button', { name: 'Add seats' })).toBeDisabled();
  });

  it('opens an invoice with EFT details and the reference to use', async () => {
    mockApi({ '/invoices': { data: [invoice] }, '/invoices/i1': { data: invoice } }, session({ permissions: ['billing.tenant'], navigation: nav('admin', 'admin.billing') }));
    renderAt('/admin/billing');

    fireEvent.click(await screen.findByRole('button', { name: 'INV-000001' }));
    expect(await screen.findByText('Tax invoice INV-000001')).toBeInTheDocument();
    expect(screen.getByText('62812345678')).toBeInTheDocument();
    expect(screen.getByText('Use INV-000001 as the payment reference so we can match it.')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Download PDF' })).toBeInTheDocument();
  });
});

describe('HQ billing', () => {
  const hq = (permissions: string[]) => session({ area: 'platform', tenant: null, permissions, navigation: nav('hq', 'hq.billing') });

  it('records an EFT payment against an invoice', async () => {
    const calls = mockApi({
      '/platform/billing/summary': { data: { paid_this_month_cents: 0, invoiced_this_month_cents: 0, outstanding_cents: 0, overdue_cents: 0, monthly_recurring_cents: 0, active_seats: 0, seats_by_programme: [], blocked_providers: [] } },
      '/platform/billing/invoices': { data: [invoice] },
      '/platform/billing/invoices/i1': { data: invoice },
      '/platform/billing/invoices/i1/payments': { data: { ...invoice, status: 'paid', paid_cents: 14490000, balance_cents: 0 } },
    }, hq(['billing.view', 'billing.manage']));
    renderAt('/hq/billing');

    fireEvent.click(await screen.findByRole('tab', { name: 'Invoices' }));
    fireEvent.click(await screen.findByRole('button', { name: 'INV-000001' }));
    fireEvent.click(await screen.findByRole('button', { name: 'Record EFT payment' }));
    fireEvent.change(screen.getByLabelText('Bank reference'), { target: { value: 'FNB 0042' } });
    fireEvent.click(screen.getAllByRole('button', { name: 'Record EFT payment' }).at(-1)!);

    await waitFor(() => expect(bodyOf(calls, '/payments')).toMatchObject({ amount_cents: 14490000, reference: 'FNB 0042' }));
    expect(await screen.findByText('Payment recorded')).toBeInTheDocument();
  });

  it('lets only Super Admin see billing settings, and warns while they are incomplete', async () => {
    const settings: BillingSettingsData = { seller_name: null, registration_number: null, vat_number: null, address: {}, email: null, phone: null, bank: {}, vat_rate: 15, invoice_prefix: 'INV-', credit_note_prefix: 'CN-', due_days: 7, complete: false };
    const calls = mockApi({
      '/platform/billing/summary': { data: { paid_this_month_cents: 0, invoiced_this_month_cents: 0, outstanding_cents: 0, overdue_cents: 0, monthly_recurring_cents: 0, active_seats: 0, seats_by_programme: [], blocked_providers: [] } },
      '/platform/billing/settings': (init?: RequestInit) => ({ data: init?.method === 'PATCH' ? { ...settings, seller_name: 'Muzi Skills Tech (Pty) Ltd' } : settings }),
    }, hq(['billing.view', 'billing.settings']));
    renderAt('/hq/billing');

    fireEvent.click(await screen.findByRole('tab', { name: 'Billing settings' }));
    expect(await screen.findByText(/Invoices print what is here/)).toBeInTheDocument();
    fireEvent.change(screen.getByLabelText('Registered company name'), { target: { value: 'Muzi Skills Tech (Pty) Ltd' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
    await waitFor(() => expect(bodyOf(calls, '/settings')).toMatchObject({ seller_name: 'Muzi Skills Tech (Pty) Ltd', vat_rate: 15 }));
  });

  it('hides billing settings from finance staff', async () => {
    mockApi({ '/platform/billing/summary': { data: { paid_this_month_cents: 0, invoiced_this_month_cents: 0, outstanding_cents: 0, overdue_cents: 0, monthly_recurring_cents: 0, active_seats: 0, seats_by_programme: [], blocked_providers: [] } } }, hq(['billing.view']));
    renderAt('/hq/billing');
    expect(await screen.findByRole('tab', { name: 'Invoices' })).toBeInTheDocument();
    expect(screen.queryByRole('tab', { name: 'Billing settings' })).not.toBeInTheDocument();
  });
});
