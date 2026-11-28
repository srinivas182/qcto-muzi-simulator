import { fireEvent, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { LearnHomeData, QctoDetails } from '@simuwork/core';
import { mockApi, nav, renderAt, session } from './harness';

afterEach(() => vi.unstubAllGlobals());

const learner = () => session({ permissions: ['learning.use'], navigation: nav('learn') });
const home: LearnHomeData = {
  learner: { name: 'Sipho Ndlovu', qcto_missing: 3 },
  enrolments: [{
    id: 'e1', status: 'enrolled', progress: 0, started: false, programme: { code: 'technical-support', title: 'Technical Support', prefix: 'TS' }, version: 1,
    cohort: { name: 'TS February 2027', centre: 'Durban campus', facilitators: ['Ayesha Patel'] }, starts_on: '2020-02-03', ends_on: '2099-12-03', days_left: 120, seat_returns_on: '2099-02-17',
  }],
};
const sent = (calls: ReturnType<typeof mockApi>, end: string, method: string) => calls.mock.calls.find(([u, i]) => String(u).endsWith(end) && (i as RequestInit | undefined)?.method === method);

describe('Learner home', () => {
  it('shows my programme, cohort, days left and asks me to complete my details', async () => {
    mockApi({ '/learn/home': { data: home } }, learner());
    renderAt('/learn');
    expect(await screen.findByRole('heading', { name: 'Technical Support' })).toBeInTheDocument();
    expect(screen.getByText('TS February 2027')).toBeInTheDocument();
    expect(screen.getByText('120 days left')).toBeInTheDocument();
    expect(screen.getByText('Ayesha Patel')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /Complete your details/ })).toHaveAttribute('href', '/learn/details');
  });

  it('starts the programme when I tap Start learning', async () => {
    const calls = mockApi({ '/learn/home': { data: home }, '/learn/enrolments/e1/start': { data: { status: 'active' } } }, learner());
    renderAt('/learn');
    fireEvent.click(await screen.findByRole('button', { name: 'Start learning' }));
    await waitFor(() => expect(sent(calls, '/learn/enrolments/e1/start', 'POST')).toBeDefined());
  });

  it('lets a learner with no class type a join code', async () => {
    mockApi({ '/learn/home': { data: { learner: null, enrolments: [] } } }, learner());
    renderAt('/learn');
    expect(await screen.findByText(/not in a class yet/)).toBeInTheDocument();
    fireEvent.change(screen.getByLabelText('Class code'), { target: { value: 'abcd2345' } });
    fireEvent.click(screen.getByRole('button', { name: 'Join' }));
    expect(await screen.findByText('ABCD2345')).toBeInTheDocument(); // the join page
  });

  it('saves my own QCTO details', async () => {
    const details: QctoDetails = { title: null, middle_name: null, alt_id_type: null, nationality_code: 'SA', citizen_status: 'SA', immigrant_status: '03', socioeconomic_code: null,
      home_address: null, postal_address: null, province_code: null, stats_area_code: null, flc_code: null, flc_sor_number: null, popi_agreed_at: '2027-01-01', equity: null,
      disability_status: null, disability_rating: null, missing: ['title'] };
    const calls = mockApi({ '/learn/me/qcto': { data: details }, '/learn/home': { data: home } }, learner());
    renderAt('/learn/details');
    fireEvent.change(await screen.findByLabelText('Title', {}, { timeout: 4000 }), { target: { value: 'Mr' } });
    expect(screen.getByText(/Prefer not to say/)).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Save details' }));
    await waitFor(() => expect(sent(calls, '/learn/me/qcto', 'PATCH')).toBeDefined());
    expect(JSON.parse(String((sent(calls, '/learn/me/qcto', 'PATCH')![1] as RequestInit).body))).toMatchObject({ title: 'Mr' });
  });
});
