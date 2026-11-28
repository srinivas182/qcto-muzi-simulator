import { fireEvent, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { quotePackage, vatCents, type PackageItem } from '@simuwork/core';
import { mockApi, nav, renderAt, session } from './harness';

afterEach(() => vi.unstubAllGlobals());

const pkg: PackageItem = {
  id: 'k1', code: 'ts-12', name: 'Technical Support — 12 months', description: 'Simulators and courses for a full cohort year.',
  programme: { code: 'technical-support', title: 'Technical Support', prefix: 'TS' }, includes_simulators: true, includes_courses: true,
  billing: 'term', term_months: 12, min_seats: 10, status: 'published', published_at: '2026-11-28T08:00:00Z',
  prices: [{ from_seats: 10, unit_price_cents: 250000 }, { from_seats: 50, unit_price_cents: 210000 }, { from_seats: 200, unit_price_cents: 180000 }],
};

describe('pricing (same rules as the API)', () => {
  it('uses the highest band at or below the seats and rounds VAT half up once', () => {
    expect(quotePackage(pkg, 60, 15)).toMatchObject({ unit_price_cents: 210000, band_from: 50, subtotal_cents: 12600000, vat_cents: 1890000, total_cents: 14490000, per_seat_per_month_cents: 17500 });
    expect(quotePackage(pkg, 9, 15)).toBeNull();
    expect([vatCents(100, 15), vatCents(10, 15), vatCents(9, 15)]).toEqual([15, 2, 1]);
  });
});

describe('Packages', () => {
  it('lets a provider price seats and shows how to reach the next band', async () => {
    mockApi({
      '/shop': { data: [pkg], vat_rate: 15 },
      '/licences': { data: [], past: [], account: { blocked: false, open_invoices: 0, owed_cents: 0, overdue_cents: 0, payment_terms: { mode: 'pay_first', days: 0 } } },
    }, session({ permissions: ['licences.manage'], navigation: nav('admin', 'admin.licences') }));
    renderAt('/admin/licences');

    fireEvent.click(await screen.findByRole('tab', { name: 'Shop' }));
    fireEvent.click(await screen.findByRole('button', { name: 'Get a price' }));
    fireEvent.change(screen.getByLabelText('How many learners?'), { target: { value: '45' } });
    expect(screen.getByText('Add 5 more to reach the 50-seat price.')).toBeInTheDocument();
    fireEvent.change(screen.getByLabelText('How many learners?'), { target: { value: '60' } });
    expect(screen.getByText('60 seats × R 2 100,00')).toBeInTheDocument();
    expect(screen.getByText('R 144 900,00')).toBeInTheDocument();
  });

  it('lets HQ draft, price and publish a package in one go', async () => {
    const created = { ...pkg, status: 'draft' as const, prices: [] };
    const calls = mockApi({
      '/platform/packages': (init?: RequestInit) => (init?.method === 'POST' ? { data: created } : { data: [] }),
      '/platform/programmes': { data: [{ code: 'technical-support', prefix: 'TS', title: 'Technical Support', family: 'IT', status: 'active' }] },
      '/platform/packages/ts-12/prices': { data: { ...created, prices: [{ from_seats: 10, unit_price_cents: 250000 }] } },
      '/platform/packages/ts-12/publish': { data: { ...pkg, prices: [{ from_seats: 10, unit_price_cents: 250000 }] } },
    }, session({ area: 'platform', tenant: null, permissions: ['packages.manage'], navigation: nav('hq', 'hq.packages') }));
    renderAt('/hq/packages');

    fireEvent.click(await screen.findByRole('button', { name: 'New package' }));
    fireEvent.change(screen.getByLabelText('Package name'), { target: { value: 'Technical Support — 12 months' } });
    await screen.findByRole('option', { name: 'TS · Technical Support' });
    fireEvent.change(screen.getByLabelText('Programme'), { target: { value: 'technical-support' } });
    fireEvent.change(screen.getByLabelText('Band 1: price per seat'), { target: { value: '2500' } });
    fireEvent.click(screen.getByRole('button', { name: 'Publish to shop' }));

    await waitFor(() => expect(calls.mock.calls.some(([u]) => String(u).endsWith('/ts-12/publish'))).toBe(true));
    const prices = calls.mock.calls.find(([u]) => String(u).endsWith('/ts-12/prices'));
    expect(JSON.parse(String((prices?.[1] as RequestInit | undefined)?.body))).toEqual({ prices: [{ from_seats: 10, unit_price_cents: 250000 }] });
  });
});
