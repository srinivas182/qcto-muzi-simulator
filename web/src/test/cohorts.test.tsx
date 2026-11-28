import { fireEvent, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { CohortDetail, CohortItem, LearnerItem, QctoCheck, QctoDetails } from '@simuwork/core';
import { mockApi, nav, renderAt, session } from './harness';

afterEach(() => vi.unstubAllGlobals());

const base: CohortItem = {
  id: 'c1', name: 'TS February 2027', centre: { id: 'ce1', name: 'Main campus' }, programme: { id: 'p1', code: 'technical-support', title: 'Technical Support' },
  version: 1, starts_on: '2027-02-03', ends_on: null, qcto_type: 'qualification', qcto_submitted_at: null, qcto_due_on: '2027-03-04', qcto_days_left: 3, qcto_qualification_id: '118707', readiness_type: '4', join_open: true, archived: false, learners: 1, counts: { enrolled: 1 }, facilitators: [],
};
const detail: CohortDetail = {
  ...base, join_code: 'ABCD2345', free_seats: 1,
  enrolments: [{ id: 'e1', learner: { id: 'l1', name: 'Sipho Ndlovu', id_masked: '••••9087', status: 'active' }, status: 'enrolled', holds_seat: true, progress: 0, seat_taken_at: '2027-02-01T09:00:00Z', started_at: null, ended_at: null, reason: null, seat_returns_on: '2027-02-17' }],
};
const check: QctoCheck = { cohort: ['sdp_code'], learners: [{ learner_id: 'l1', name: 'Sipho Ndlovu', missing: ['equity', 'home_address'] }], complete: 0, total: 1 };
const learner = (id: string, name: string): LearnerItem => ({ id, first_names: name, surname: '', name, phone: null, email: null, id_type: 'sa_id', id_masked: '•••••••••1234', date_of_birth: null, gender: null, citizenship: null, home_language: null, qcto_learner_number: null, status: 'invited', joined_at: null, created_at: '' });
const admin = (perms = ['classes.view', 'classes.manage', 'learners.view', 'learners.manage']) => session({ permissions: perms, navigation: nav('admin', 'admin.cohorts') });
const bodyOf = (calls: ReturnType<typeof mockApi>, end: string) => {
  const c = calls.mock.calls.find(([u, i]) => String(u).endsWith(end) && (i as RequestInit | undefined)?.method !== 'GET');
  return c ? JSON.parse(String((c[1] as RequestInit).body ?? 'null')) : undefined;
};

describe('Cohorts', () => {
  it('lists cohorts as cards that open the cohort', async () => {
    mockApi({ '/cohorts': { data: [base] } }, admin());
    renderAt('/admin/cohorts');
    const card = await screen.findByRole('link', { name: /TS February 2027/ });
    expect(card).toHaveAttribute('href', '/admin/cohorts/c1');
    expect(screen.getByText('1 learners')).toBeInTheDocument();
  });

  it('shows the join code, when an unstarted seat goes back, and enrols only up to the free seats', async () => {
    const calls = mockApi({
      '/cohorts/c1': { data: detail },
      '/cohorts/c1/qcto-check': { data: check },
      '/learners': { data: [learner('l1', 'Sipho Ndlovu'), learner('l2', 'Thandi Mokoena'), learner('l3', 'Ayanda Zulu')], counts: {} },
      '/cohorts/c1/enrolments': { data: { enrolled: 1, skipped: [] } },
    }, admin());
    renderAt('/admin/cohorts/c1');
    expect(await screen.findByText('ABCD2345')).toBeInTheDocument();
    expect(screen.getByText(/seat goes back on/)).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Add learners' }));
    expect(await screen.findByText('Thandi Mokoena')).toBeInTheDocument();
    expect(screen.queryByRole('checkbox', { name: /Sipho Ndlovu/ })).not.toBeInTheDocument(); // already in the cohort: not offered again
    fireEvent.click(screen.getByRole('checkbox', { name: /Thandi Mokoena/ }));
    fireEvent.click(screen.getByRole('checkbox', { name: /Ayanda Zulu/ }));
    expect(screen.getByRole('button', { name: 'Enrol 2' })).toBeDisabled(); // only 1 seat free
    fireEvent.click(screen.getByRole('checkbox', { name: /Ayanda Zulu/ }));
    fireEvent.click(screen.getByRole('button', { name: 'Enrol 1' }));
    await waitFor(() => expect(bodyOf(calls, '/cohorts/c1/enrolments')).toEqual({ learner_ids: ['l2'] }));
  });

  it('facilitators see the cohort but cannot enrol, move or withdraw', async () => {
    mockApi({ '/cohorts/c1': { data: detail }, '/cohorts/c1/qcto-check': { data: check } }, admin(['classes.view', 'classes.manage', 'learners.view']));
    renderAt('/admin/cohorts/c1');
    expect(await screen.findByText('Sipho Ndlovu')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Add learners' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Withdraw' })).not.toBeInTheDocument();
  });

  it('lets a learner join their class from the QR link', async () => {
    const calls = mockApi({ '/cohorts/join': { data: { enrolment_id: 'e9', cohort: 'TS February 2027' } } }, session({ permissions: ['learning.use'], navigation: nav('learn') }));
    renderAt('/learn/join/abcd2345');
    fireEvent.click(await screen.findByRole('button', { name: 'Join' }));
    expect(await screen.findByText('You are in TS February 2027')).toBeInTheDocument();
    expect(bodyOf(calls, '/cohorts/join')).toEqual({ code: 'abcd2345' });
  });
});

describe('QCTO', () => {
  it('shows the deadline, what is missing, and offers the file only to staff who may see ID numbers', async () => {
    mockApi({ '/cohorts/c1': { data: detail }, '/cohorts/c1/qcto-check': { data: check } }, admin([...['classes.view', 'classes.manage', 'learners.view', 'learners.manage'], 'learners.reveal_id']));
    renderAt('/admin/cohorts/c1');
    expect(await screen.findByText('3 working days left')).toBeInTheDocument();
    expect(await screen.findByText('0 of 1 learners ready')).toBeInTheDocument();
    expect(screen.getByText(/Add your QCTO SDP code/)).toBeInTheDocument();
    expect(screen.getByText(/population group, home address/)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Download for QCTO (.xlsx)' })).toBeInTheDocument();
  });

  it('hides the download from staff without the ID permission', async () => {
    mockApi({ '/cohorts/c1': { data: detail }, '/cohorts/c1/qcto-check': { data: check } }, admin());
    renderAt('/admin/cohorts/c1');
    expect(await screen.findByText('0 of 1 learners ready')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Download for QCTO (.xlsx)' })).not.toBeInTheDocument();
  });

  it('saves QCTO details, asking for a rating only when there is a disability', async () => {
    const details: QctoDetails = { title: null, middle_name: null, alt_id_type: null, nationality_code: 'SA', citizen_status: 'SA', immigrant_status: '03', socioeconomic_code: null,
      home_address: null, postal_address: null, province_code: null, stats_area_code: null, flc_code: null, flc_sor_number: null, popi_agreed_at: null, equity: null,
      disability_status: null, disability_rating: null, missing: ['title', 'equity'] };
    const calls = mockApi({
      '/learners': { data: [learner('l1', 'Sipho Ndlovu')], counts: {} },
      '/learners/l1/qcto': { data: details },
    }, session({ permissions: ['learners.view', 'learners.manage'], navigation: nav('admin', 'admin.learners') }));
    renderAt('/admin/learners');
    fireEvent.click(await screen.findByRole('button', { name: 'Sipho Ndlovu' }));
    expect(await screen.findByText('2 missing')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Edit QCTO details' }));
    fireEvent.change(await screen.findByLabelText('Title'), { target: { value: 'Mr' } });
    expect(screen.queryByLabelText('How much difficulty')).not.toBeInTheDocument();
    fireEvent.change(screen.getByLabelText('Disability'), { target: { value: '01' } });
    fireEvent.change(screen.getByLabelText('How much difficulty'), { target: { value: '02' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save details' }));
    await waitFor(() => expect(calls.mock.calls.some(([u, i]) => String(u).endsWith('/learners/l1/qcto') && (i as RequestInit).method === 'PATCH')).toBe(true));
    const body = JSON.parse(String((calls.mock.calls.find(([u, i]) => String(u).endsWith('/learners/l1/qcto') && (i as RequestInit).method === 'PATCH')![1] as RequestInit).body));
    expect(body).toMatchObject({ title: 'Mr', disability_status: '01', disability_rating: '02', home_address: null, postal_address: null });
    expect(body).not.toHaveProperty('alt_id_type'); // SA ID holders have none
  });
});
