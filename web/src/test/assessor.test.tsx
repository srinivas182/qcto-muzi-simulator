import { fireEvent, screen, waitFor, within } from '@testing-library/react';
import { beforeEach, describe, expect, it } from 'vitest';
import type { AssessQueue, LearnAssessmentItem, PracticalTask, RunReplay } from '@simuwork/core';
import { mockApi, nav, renderAt, session } from './harness';

beforeEach(() => localStorage.clear());

const assessor = () => session({ permissions: ['classes.view', 'assessments.review', 'assessments.signoff'], navigation: nav('assess') });
const task: PracticalTask = {
  id: 't1', code: 'TS-P01', title: 'Fix the slow PC', kind: 'simulator', items: [], rounds_allowed: 3, rounds_used: 0, competent: false, history: [],
  link_options: { steps: [{ id: 'unplug', prompt: 'Make it safe.' }], lessons: [{ code: 'TS-L1', title: 'Working safely' }] },
};
const run: RunReplay = {
  id: 'a1', enrolment_id: 'e1', learner: 'Sipho Ndlovu', simulator: { id: 's1', code: 'TS-S1', title: 'Slow PC', version_now: 1 }, version_played: 1, mode: 'job', device: 'mobile',
  started_at: '2027-02-10T08:52:00Z', finished_at: '2027-02-10T09:00:00Z', duration_seconds: 480, score: 63, pass_mark: 70, passed: false, critical: true, variant: null, hash: 'ab'.repeat(32),
  steps: [{ id: 'unplug', prompt: 'Make it safe.', critical: true, outcome: null, lesson: 'TS-L1', done: true, mistakes: 1, hints: 0,
    moves: [{ action: 'tap', value: 'case-lid', t: 4000, right: false }, { action: 'tap', value: 'wall-switch', t: 9000, right: true }] }],
  task, decision: null,
};

describe('Assessor workspace', () => {
  it('lists runs to judge, replays the run step by step and signs off a not-yet-competent decision with linked feedback', async () => {
    const queue: AssessQueue = { mark: [], observe: [], done: [], runs: [{ kind: 'run', id: 'a1', enrolment_id: 'e1', learner: 'Sipho Ndlovu', cohort: 'TS Feb', task: { id: 't1', code: 'TS-P01', title: 'Fix the slow PC' }, at: '2027-02-10T09:00:00Z', round: 1, rounds_allowed: 3, score: 63, passed: false, critical: true }] };
    const calls = mockApi({
      '/cohorts': { data: [] }, '/assess/queue': { data: queue }, '/assess/attempts/a1': { data: run },
      '/assess/enrolments/e1/tasks/t1/decisions': { data: { id: 'd1', round: 1, result: 'not_yet', feedback: 'Switch off first.', links: [], observation: null, attempt_id: 'a1', assessor: 'Lindiwe', at: '', hash: null } },
    }, assessor());
    renderAt('/assess');
    fireEvent.click(await screen.findByRole('link', { name: /Sipho Ndlovu/ }));
    expect(await screen.findByText('Critical safety step missed')).toBeInTheDocument();
    expect(screen.getByLabelText('Wrong')).toBeInTheDocument();
    expect(screen.getByText('case-lid')).toBeInTheDocument();

    const sign = screen.getByRole('button', { name: 'Sign off decision' });
    fireEvent.click(screen.getByRole('radio', { name: 'Not yet competent' }));
    expect(sign).toBeDisabled(); // feedback and the declaration are needed
    fireEvent.change(screen.getByLabelText('What must the learner do to be competent next time?'), { target: { value: 'Switch off first.' } });
    fireEvent.click(screen.getByRole('button', { name: /Step 1: Make it safe/ }));
    fireEvent.click(screen.getByRole('button', { name: /Lesson: Working safely/ }));
    fireEvent.click(screen.getByLabelText(/I declare that this evidence/));
    fireEvent.click(sign);
    await waitFor(() => expect(calls.mock.calls.some(([u, i]) => String(u).endsWith('/decisions') && (i as RequestInit).method === 'POST')).toBe(true));
    const post = calls.mock.calls.find(([u]) => String(u).endsWith('/decisions'))!;
    expect(JSON.parse(String((post[1] as RequestInit).body))).toMatchObject({ result: 'not_yet', attempt_id: 'a1', declaration: true, links: [{ type: 'step', ref: 'unplug' }, { type: 'lesson', ref: 'TS-L1' }] });
  });

  it('an observation needs every item ticked and keeps the draft on this device', async () => {
    const obs: PracticalTask & { learner: string; enrolment_id: string } = { ...task, id: 't2', kind: 'observation', title: 'Observed repair', items: ['Works safely', 'Explains the fix'], link_options: { steps: [], lessons: [] }, learner: 'Sipho Ndlovu', enrolment_id: 'e1' };
    mockApi({ '/assess/enrolments/e1/tasks/t2': { data: obs } }, assessor());
    renderAt('/assess/observe/e1/t2');
    const first = await screen.findByRole('radiogroup', { name: 'Works safely' });
    fireEvent.click(within(first).getByRole('radio', { name: 'Met' }));
    fireEvent.click(within(screen.getByRole('radiogroup', { name: 'Explains the fix' })).getByRole('radio', { name: 'Not met' }));
    fireEvent.click(screen.getByRole('radio', { name: 'Competent' }));
    expect(screen.getByRole('alert')).toHaveTextContent('Competent needs every item met.');
    expect(JSON.parse(localStorage.getItem('sw.decision.e1.t2.obs')!).rows).toEqual([{ met: true, note: '' }, { met: false, note: '' }]);
  });

  it('the learner sees the feedback with links to practise the step and redo the lesson', async () => {
    const item: LearnAssessmentItem = { id: 't1', code: 'TS-P01', title: 'Fix the slow PC', component: 'practical', kind: 'simulator', outcomes: [], minutes: null, questions: null, pass_mark: null,
      attempts_allowed: 3, attempts_used: 1, open_sitting: null, waiting_for_marking: false, result: 'not_yet', best_score: null, simulator_id: 's1',
      feedback: { text: 'Switch off at the wall first.', at: '', links: [{ type: 'step', ref: 'unplug', label: 'Make it safe.', activity_id: 's1' }, { type: 'lesson', ref: 'TS-L1', label: 'Working safely', activity_id: 'l1' }] } };
    mockApi({ '/learn/enrolments/e1/assessments': { data: [item] } }, session({ permissions: ['learning.use'], navigation: nav('learn') }));
    renderAt('/learn/enrolments/e1/assessments');
    expect(await screen.findByText('Switch off at the wall first.')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Practise this step: Make it safe.' })).toHaveAttribute('href', '/learn/enrolments/e1/simulators/s1');
    expect(screen.getByRole('link', { name: 'Redo the lesson: Working safely' })).toHaveAttribute('href', '/learn/enrolments/e1/lessons/l1');
    expect(screen.getByText('2 more opportunities on this task.')).toBeInTheDocument();
  });
});
