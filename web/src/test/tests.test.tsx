import { fireEvent, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { LearnAssessmentItem, TestPaper } from '@simuwork/core';
import { mockApi, nav, renderAt, session } from './harness';

beforeEach(() => { localStorage.clear(); vi.stubGlobal('confirm', () => true); });
afterEach(() => vi.unstubAllGlobals());

const learner = () => session({ permissions: ['learning.use'], navigation: nav('learn') });
const paper: TestPaper = {
  id: 's1', task: { id: 't1', code: 'TS-A01', title: 'Knowledge test', pass_mark: 70 }, attempt: 1, locale: 'en', status: 'open',
  started_at: '', deadline_at: '', seconds_left: 1200, answers: {},
  questions: [
    { id: 'q1', type: 'single', prompt: 'What do you do first?', marks: 1, options: ['Call the user', 'Switch off and unplug'] },
    { id: 'q2', type: 'multi', prompt: 'Which protect parts from static?', marks: 2, options: ['Antistatic mat', 'Carpet', 'Wrist strap'] },
    { id: 'q3', type: 'short', prompt: 'Why test before closing the ticket?', marks: 3, options: [] },
  ],
};

describe('Knowledge tests', () => {
  it('lists my assessments by component with what is left', async () => {
    const items: LearnAssessmentItem[] = [
      { id: 't1', code: 'TS-A01', title: 'Knowledge test', component: 'knowledge', kind: 'test', outcomes: [], minutes: 20, questions: 10, pass_mark: 70, attempts_allowed: 2, attempts_used: 1, open_sitting: null, waiting_for_marking: false, result: 'not_yet', best_score: 55 },
      { id: 't2', code: 'TS-A02', title: 'Workplace logbook', component: 'workplace', kind: 'logbook', outcomes: [], minutes: null, questions: null, pass_mark: null, attempts_allowed: null, attempts_used: 0, open_sitting: null, waiting_for_marking: false, result: null, best_score: null },
    ];
    mockApi({ '/learn/enrolments/e1/assessments': { data: items } }, learner());
    renderAt('/learn/enrolments/e1/assessments');
    expect(await screen.findByRole('link', { name: 'Knowledge test: start' })).toHaveAttribute('href', '/learn/enrolments/e1/tests/t1');
    expect(screen.getByText('10 questions · 20 min · pass 70% · 1 tries left')).toBeInTheDocument();
    expect(screen.getByText('Done with your assessor')).toBeInTheDocument();
  });

  it('answers are kept on the device, sent to the server, and handed in', async () => {
    const calls = mockApi({
      '/learn/enrolments/e1/tests/t1/start': { data: paper },
      '/learn/sittings/s1': (init?: RequestInit) => {
        const body = JSON.parse(String(init?.body));
        return body.submit ? { data: { id: 's1', status: 'submitted', attempt: 1, score: null, result: null, late: false, submitted_at: '', revise: [] } } : { data: paper };
      },
    }, learner());
    renderAt('/learn/enrolments/e1/tests/t1');
    fireEvent.click(await screen.findByRole('button', { name: 'Start the test' }));
    expect(await screen.findByText(/1. What do you do first?/)).toBeInTheDocument();
    fireEvent.click(screen.getByLabelText('Switch off and unplug'));
    fireEvent.click(screen.getByLabelText('Antistatic mat'));
    fireEvent.click(screen.getByLabelText('Wrist strap'));
    fireEvent.change(screen.getByLabelText('Why test before closing the ticket?'), { target: { value: 'So it works for the user.' } });
    expect(screen.getByText('3 of 3 answered')).toBeInTheDocument();
    expect(JSON.parse(localStorage.getItem('sw.test.s1')!)).toEqual({ q1: 1, q2: [0, 2], q3: 'So it works for the user.' });
    fireEvent.click(screen.getByRole('button', { name: 'Hand in' }));
    expect(await screen.findByText('Handed in')).toBeInTheDocument();
    const put = calls.mock.calls.filter(([u, i]) => String(u).endsWith('/learn/sittings/s1') && (i as RequestInit).method === 'PUT').pop()!;
    expect(JSON.parse(String((put[1] as RequestInit).body))).toEqual({ answers: { q1: 1, q2: [0, 2], q3: 'So it works for the user.' }, submit: true });
    await waitFor(() => expect(localStorage.getItem('sw.test.s1')).toBeNull());
  });

  it('a camera-checked test needs my agreement first, and says how to opt out', async () => {
    const item: LearnAssessmentItem = { id: 't1', code: 'TS-A01', title: 'Knowledge test', component: 'knowledge', kind: 'test', outcomes: [], minutes: 20, questions: 3, pass_mark: 70, attempts_allowed: 2, attempts_used: 0, open_sitting: null, waiting_for_marking: false, result: null, best_score: null, camera: true };
    const calls = mockApi({
      '/learn/enrolments/e1/assessments': { data: [item] },
      '/learn/enrolments/e1/tests/t1/start': { data: { ...paper, camera: true, photos_max: 6 } },
    }, learner());
    renderAt('/learn/enrolments/e1/tests/t1');
    expect(await screen.findByText('This test uses a camera check')).toBeInTheDocument();
    expect(screen.getByText(/ask your provider for a supervised sitting/i)).toBeInTheDocument();
    const begin = screen.getByRole('button', { name: 'Start the test' });
    expect(begin).toBeDisabled();
    fireEvent.click(screen.getByLabelText('I agree to the camera check for this test.'));
    fireEvent.click(begin);
    expect(await screen.findByText('Camera check on')).toBeInTheDocument();
    const start = calls.mock.calls.find(([u]) => String(u).endsWith('/start'))!;
    expect(JSON.parse(String((start[1] as RequestInit).body))).toMatchObject({ camera_consent: true });
  });
});
