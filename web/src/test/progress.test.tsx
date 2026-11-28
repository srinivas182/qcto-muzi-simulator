import { fireEvent, screen, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import type { ChecklistArea, CohortBoard } from '@simuwork/core';
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
});
