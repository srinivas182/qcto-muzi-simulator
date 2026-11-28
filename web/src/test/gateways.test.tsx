import { fireEvent, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { GatewaySetting, InvoiceDetail } from '@simuwork/core';
import { mockApi, nav, renderAt, session } from './harness';

afterEach(() => vi.unstubAllGlobals());

const summary = { paid_this_month_cents: 0, invoiced_this_month_cents: 0, outstanding_cents: 0, overdue_cents: 0, monthly_recurring_cents: 0, active_seats: 0, seats_by_programme: [], blocked_providers: [] };
const settings = { seller_name: 'X', registration_number: null, vat_number: '4000000000', address: {}, email: null, phone: null, bank: { account_number: '1' }, vat_rate: 15, invoice_prefix: 'INV-', credit_note_prefix: 'CN-', due_days: 7, complete: true };
const paystack: GatewaySetting = {
  driver: 'paystack', label: 'Paystack', enabled: false, mode: 'test', display_order: 0, complete: false, last_test: null, notify_url: 'https://api.simuwork.co.za/api/v1/payments/paystack/notify',
  fields: [{ key: 'public_key', label: 'Public key', secret: false, set: false, hint: null }, { key: 'secret_key', label: 'Secret key', secret: true, set: true, hint: '••••1234' }],
};
const invoice: InvoiceDetail = {
  id: 'i1', number: 'INV-000001', kind: 'order', issue_date: '2027-01-01', due_date: '2027-01-08', period_start: null, period_end: null,
  subtotal_cents: 1000000, vat_rate: 15, vat_cents: 150000, total_cents: 1150000, paid_cents: 0, balance_cents: 1150000, status: 'issued', overdue_days: 0,
  buyer_name: 'Ubuntu', tenant_id: 't1', description: 'TS', lines: [{ description: 'TS', quantity: 10, unit_cents: 100000, amount_cents: 1000000 }],
  seller: { name: 'Seller', vat_number: null, registration_number: null, email: null, phone: null, bank: {} }, buyer: { name: 'Ubuntu', vat_number: null, email: null }, payments: [], credit_note: null,
};

describe('Payment gateways', () => {
  it('saves only the keys typed and never shows saved secrets', async () => {
    const calls = mockApi({
      '/platform/billing/summary': { data: summary },
      '/platform/billing/settings': { data: settings },
      '/platform/billing/gateways': { data: [paystack] },
      '/platform/billing/gateways/paystack': { data: { ...paystack, complete: true, fields: paystack.fields.map((f) => ({ ...f, set: true, hint: f.secret ? '••••1234' : 'pk_test_1' })) } },
    }, session({ area: 'platform', tenant: null, permissions: ['billing.view', 'billing.settings'], navigation: nav('hq', 'hq.billing') }));
    renderAt('/hq/billing');

    fireEvent.click(await screen.findByRole('tab', { name: 'Billing settings' }));
    const secret = await screen.findByLabelText('Secret key');
    expect(secret).toHaveValue('');
    expect(secret).toHaveAttribute('placeholder', '••••1234');
    expect(screen.getByRole('switch', { name: 'Switch Paystack on' })).toBeDisabled(); // keys incomplete
    fireEvent.change(screen.getByLabelText('Public key'), { target: { value: 'pk_test_1' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save keys and mode' }));

    await waitFor(() => {
      const c = calls.mock.calls.find(([u, i]) => String(u).endsWith('/gateways/paystack') && (i as RequestInit | undefined)?.method === 'PATCH');
      expect(JSON.parse(String((c?.[1] as RequestInit | undefined)?.body))).toEqual({ credentials: { public_key: 'pk_test_1' }, mode: 'test' });
    });
  });

  it('sends the provider to the gateway and confirms the payment on return', async () => {
    const assign = vi.fn();
    vi.stubGlobal('location', { ...window.location, assign, origin: 'https://ubuntu.simuwork.co.za' });
    const calls = mockApi({
      '/invoices': { data: [invoice] },
      '/invoices/i1': { data: invoice },
      '/payment-options': { data: [{ driver: 'paystack', label: 'Paystack', test_mode: false }] },
      '/invoices/i1/pay': { attempt_id: 'a1', checkout: { type: 'redirect', url: 'https://checkout.paystack.com/abc' } },
    }, session({ permissions: ['billing.tenant'], navigation: nav('admin', 'admin.billing') }));
    renderAt('/admin/billing');

    fireEvent.click(await screen.findByRole('button', { name: 'INV-000001' }));
    fireEvent.click(await screen.findByRole('button', { name: 'Pay with Paystack' }));
    await waitFor(() => expect(assign).toHaveBeenCalledWith('https://checkout.paystack.com/abc'));
    const c = calls.mock.calls.find(([u]) => String(u).endsWith('/invoices/i1/pay'));
    expect(JSON.parse(String((c?.[1] as RequestInit | undefined)?.body))).toEqual({ gateway: 'paystack', return_url: 'https://ubuntu.simuwork.co.za/admin/billing' });
  });

  it('shows the result when the gateway sends the provider back', async () => {
    mockApi({ '/invoices': { data: [] }, '/payment-attempts/a1': { data: { status: 'paid' } } }, session({ permissions: ['billing.tenant'], navigation: nav('admin', 'admin.billing') }));
    renderAt('/admin/billing?attempt=a1');
    expect(await screen.findByText('Payment received. Thank you: your invoice is paid.')).toBeInTheDocument();
  });
});
