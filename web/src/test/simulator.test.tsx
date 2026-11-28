import { fireEvent, screen, waitFor, within } from '@testing-library/react';
import { beforeEach, describe, expect, it } from 'vitest';
import type { LearnScenario } from '@simuwork/core';
import { mockApi, nav, renderAt, session } from './harness';

beforeEach(() => localStorage.clear());

const learner = () => session({ permissions: ['learning.use'], navigation: nav('learn') });
const base = { hints: [], why: '', outcome: null, lesson: null, critical: false, sets: {}, wrong: [] };
const scenario: LearnScenario = {
  id: 's1', code: 'TS-S01', title: 'A PC won’t boot', summary: 'Diagnose the fault and replace the faulty RAM safely.', minutes: 12, content_version: 1, locale: 'en',
  lessons: { 'TS-L02': 'l2' }, results: { practice: null, job: null },
  content: {
    scene: 'pc-bench', pass_mark: 70, job: { minutes: 12, customer: 'Thandi needs her PC before payroll.' },
    steps: [
      { ...base, id: 'unplug', type: 'tap', target: 'wall-switch', prompt: 'Make the PC safe before you open it.', critical: true, hints: ['Switch off at the wall socket.'],
        why: 'Opening a live PC can shock you.', lesson: { code: 'TS-L02', block: null }, sets: { 'wall-switch': 'off' },
        wrong: [{ on: 'case-panel', effect: 'spark', message: 'You opened a live PC.' }] },
      { ...base, id: 'cause', type: 'choose', prompt: 'What is the most likely cause?', options: ['The power supply', 'A memory (RAM) problem'], answer: 1 },
      { ...base, id: 'fitram', type: 'place', target: 'ram-new', slot: 'slot-a1', prompt: 'Fit the new RAM stick.', sets: { 'ram-new': 'in' } },
    ],
  },
};
const syncer = (init?: RequestInit) => {
  const body = JSON.parse(String(init?.body));
  return { data: { accepted: body.attempts.map((a: { client_id: string }) => a.client_id), rejected: [], progress: { e1: 60 } } };
};

describe('Simulator', () => {
  it('runs a practice attempt in the simple view, with consequences, hints and a debrief that links to the lesson', async () => {
    const calls = mockApi({ '/learn/enrolments/e1/scenarios/s1': { data: scenario }, '/learn/attempts/sync': syncer }, learner());
    renderAt('/learn/enrolments/e1/simulators/s1');
    expect(await screen.findByRole('heading', { name: 'A PC won’t boot' })).toBeInTheDocument();
    fireEvent.click(screen.getAllByRole('button', { name: 'Start' })[0]!); // practice

    expect(await screen.findByText('Make the PC safe before you open it.')).toBeInTheDocument();
    expect(screen.getByText('Step 1 of 3')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: /Side panel of the case/ })); // wrong: live PC
    expect(screen.getByRole('alert')).toHaveTextContent('You opened a live PC.');
    fireEvent.click(screen.getByRole('button', { name: 'Hint' }));
    expect(screen.getByText('Switch off at the wall socket.')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Hint' })).not.toBeInTheDocument(); // that was the only hint
    fireEvent.click(screen.getByRole('button', { name: /Wall socket switch/ }));

    fireEvent.click(await screen.findByRole('button', { name: 'A memory (RAM) problem' }));
    fireEvent.click(await screen.findByRole('button', { name: /New RAM stick/ }));
    expect(screen.getByText('Holding the new ram stick. Now tap where it goes.')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: /Memory slot A1/ }));

    expect(await screen.findByText('Score 87%')).toBeInTheDocument(); // (1 − 0.25 − 0.15 + 1 + 1) / 3
    expect(screen.getByText('Passed in practice. Try it as a real job next.')).toBeInTheDocument();
    const first = screen.getAllByRole('listitem')[0]!;
    expect(within(first).getByText(/Mistakes: 1 · Hints: 1/)).toBeInTheDocument();
    expect(within(first).getByRole('link', { name: 'Review the lesson' })).toHaveAttribute('href', '/learn/enrolments/e1/lessons/l2');
    expect(screen.getByRole('button', { name: 'Try it as a real job' })).toBeInTheDocument();

    await waitFor(() => expect(calls.mock.calls.some(([u]) => String(u).endsWith('/learn/attempts/sync'))).toBe(true));
    const body = JSON.parse(String((calls.mock.calls.findLast(([u]) => String(u).endsWith('/learn/attempts/sync'))![1] as RequestInit).body));
    expect(body.attempts[0]).toMatchObject({ mode: 'practice', completed: true, score: 87, activity_id: 's1' });
    expect(body.attempts[0].result.events.map((e: { action: string }) => e.action)).toEqual(['tap', 'hint', 'tap', 'choose', 'place']);
  });

  it('real-job mode has no hints, shows the customer and a clock, and a safety mistake fails it', async () => {
    mockApi({ '/learn/enrolments/e1/scenarios/s1': { data: scenario }, '/learn/attempts/sync': syncer }, learner());
    renderAt('/learn/enrolments/e1/simulators/s1');
    fireEvent.click((await screen.findAllByRole('button', { name: 'Start' }))[1]!); // real job
    expect(await screen.findByText('Thandi needs her PC before payroll.')).toBeInTheDocument();
    expect(screen.getByText('12:00 left')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Hint' })).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: /Side panel of the case/ }));
    fireEvent.click(screen.getByRole('button', { name: /Wall socket switch/ }));
    fireEvent.click(await screen.findByRole('button', { name: 'A memory (RAM) problem' }));
    fireEvent.click(await screen.findByRole('button', { name: /New RAM stick/ }));
    fireEvent.click(screen.getByRole('button', { name: /Memory slot A1/ }));
    expect(await screen.findByText('A safety step went wrong. On a real job that must never happen.')).toBeInTheDocument();
  });
});
