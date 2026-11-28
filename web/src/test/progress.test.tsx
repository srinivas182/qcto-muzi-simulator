import { fireEvent, screen, waitFor, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import type { ChecklistArea, ClassBoard, CohortBoard } from '@simuwork/core';
import { mockApi, nav, renderAt, session } from './harness';

const areas: ChecklistArea[] = [{ number: 2, title: 'Diagnose faults', outcomes: [
  { code: 'TS-2.2', statement: 'Diagnose memory faults', plain_words: 'Find why a PC will not start', status: 'shown' },
  { code: 'TS-2.3', statement: 'Diagnose network faults', plain_words: null, status: 'practised' },
  { code: 'TS-2.4', statement: 'Diagnose printer faults', plain_words: null, status: 'not_yet' },
] }];
const board: CohortBoard = {
  activities: [{ id: 'a1', kind: 'lesson', code: 'TS-L01', title: 'Safety', required: true }, { id: 's1', kind: 'scenario', code: 'TS-S01', title: 'A PC won’t boot', required: true }],
  learners: [
    { id: 'e1', name: 'Sipho Ndlovu', status: 'active', progress: 40, last_activity_at: '2020-01-01T00:00:00Z', at_risk: true, job_ready: { shown: 1, of: 3 }, cells: { a1: { status: 'done', best: 90, tries: 1, job_passed: false }, s1: null } },
    { id: 'e2', name: 'Lerato Mokoena', status: 'active', progress: 100, last_activity_at: new Date().toISOString(), at_risk: false, job_ready: { shown: 3, of: 3 }, cells: { a1: { status: 'done', best: 100, tries: 1, job_passed: false }, s1: { status: 'done', best: 95, tries: 2, job_passed: true } } },
  ],
  summary: { learners: 2, average: 70, at_risk: 1, job_ready: 1 },
};

describe('Progress', () => {
  it('the facilitator board shows quiet learners first and opens a learner’s checklist', async () => {
    mockApi({ '/cohorts/c1/progress': { data: board }, '/enrolments/e1/checklist': { data: { learner: 'Sipho Ndlovu', areas } } },
      session({ permissions: ['classes.view'], navigation: nav('admin.cohorts') }));
    renderAt('/admin/cohorts/c1/progress');
    const rows = await screen.findAllByRole('row');
    expect(within(rows[1]!).getByText('Sipho Ndlovu')).toBeInTheDocument();
    expect(within(rows[1]!).getByText('Quiet 7+ days')).toBeInTheDocument();
    expect(within(rows[2]!).getByTitle('Done · best 95% · 2 tries')).toBeInTheDocument();
    fireEvent.click(within(rows[1]!).getByRole('button', { name: '1/3' }));
    expect(await screen.findByText('1 of 3 skills shown')).toBeInTheDocument();
    expect(screen.getByLabelText('Shown on a real job')).toBeInTheDocument();
  });

  it('the learner sees their checklist in plain words', async () => {
    mockApi({ '/learn/enrolments/e1/checklist': { data: { areas } } }, session({ permissions: ['learning.use'], navigation: nav('learn') }));
    renderAt('/learn/enrolments/e1/checklist');
    expect(await screen.findByText('Find why a PC will not start')).toBeInTheDocument();
    expect(screen.getByText('2. Diagnose faults')).toBeInTheDocument();
    expect(screen.getByLabelText('Not yet')).toBeInTheDocument();
  });

  it('a shared checklist opens without signing in, shows no personal details and keeps the locked footer', async () => {
    mockApi({ '/checklists/abc': { data: { learner: 'Sipho N.', academy: 'Ubuntu Skills Academy', programme: 'Technical Support', as_of: '2027-02-10', expires_at: '2027-03-12T00:00:00Z', areas } } });
    renderAt('/c/abc');
    expect(await screen.findByRole('heading', { name: 'Job-ready checklist: Sipho N.' })).toBeInTheDocument();
    expect(screen.getByText('Find why a PC will not start')).toBeInTheDocument();
    const footer = screen.getByRole('contentinfo');
    expect(within(footer).getByRole('link', { name: 'Mayura Consultancy Services' })).toHaveAttribute('href', 'https://www.mayuraconsultancy.com');
    expect(document.head.querySelector('meta[name="robots"]')?.getAttribute('content')).toBe('noindex');
  });

  it('the learner makes a link, sees it opened, and stops it', async () => {
    let live = true;
    const calls = mockApi({
      '/learn/enrolments/e1/checklist': { data: { areas } },
      '/learn/enrolments/e1/shares': (init?: RequestInit) => (init?.method === 'POST'
        ? { data: { id: 'sh1', label: 'My mentor', created_at: '2027-02-10', expires_at: '2027-03-12', views: 0, live: true, url: 'https://ubuntu.simuwork.co.za/c/TOKEN' } }
        : { data: live ? [{ id: 'sh1', label: 'My mentor', created_at: '2027-02-10', expires_at: '2027-03-12', views: 2, live: true }] : [] }),
      '/learn/shares/sh1': () => { live = false; return null; },
    }, session({ permissions: ['learning.use'], navigation: nav('learn') }));
    renderAt('/learn/enrolments/e1/checklist');
    fireEvent.change(await screen.findByLabelText('Who is it for?'), { target: { value: 'My mentor' } });
    fireEvent.click(screen.getByRole('button', { name: 'Make a link' }));
    expect(await screen.findByText('https://ubuntu.simuwork.co.za/c/TOKEN')).toBeInTheDocument();
    expect(await screen.findByText(/Opened 2 times/)).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Stop' }));
    await waitFor(() => expect(calls.mock.calls.some(([u, i]) => String(u).endsWith('/learn/shares/sh1') && (i as RequestInit).method === 'DELETE')).toBe(true));
    await waitFor(() => expect(screen.queryByText(/Opened 2 times/)).not.toBeInTheDocument());
  });

  it('class mode: the facilitator starts a simulator and sees who is stuck', async () => {
    const live: ClassBoard = {
      session: { id: 'cs1', cohort_id: 'c1', activity_id: 's1', title: 'A PC won’t boot', code: 'TS-S01', mode: 'practice', started_at: '', live: true },
      learners: [
        { id: 'e1', name: 'Sipho Ndlovu', joined: true, step: 3, steps: 10, finished: false, score: null, passed: null, mistakes: 4, help: 1, last_move_at: '', stuck: true },
        { id: 'e2', name: 'Lerato Mokoena', joined: false },
      ],
      summary: { learners: 2, joined: 1, finished: 0, stuck: 1 },
    };
    let started = false;
    const calls = mockApi({
      '/cohorts/c1/class': (init?: RequestInit) => { if (init?.method === 'POST') started = true; return { data: started ? live : null }; },
      '/cohorts/c1/progress': { data: board },
    }, session({ permissions: ['classes.view', 'live_classroom.run'], navigation: nav('admin.cohorts') }));
    renderAt('/admin/cohorts/c1/class');
    fireEvent.click(await screen.findByRole('button', { name: 'Start with the class' }));
    expect(await screen.findByText('Sipho Ndlovu')).toBeInTheDocument();
    expect(screen.getByText('Step 3 of 10')).toBeInTheDocument();
    expect(screen.getAllByText('Stuck').length).toBeGreaterThan(0);
    expect(screen.getByText('Not joined yet')).toBeInTheDocument();
    const body = JSON.parse(String((calls.mock.calls.find(([u, i]) => String(u).endsWith('/cohorts/c1/class') && (i as RequestInit).method === 'POST')![1] as RequestInit).body));
    expect(body).toEqual({ activity_id: 's1', mode: 'practice' });
  });
});
