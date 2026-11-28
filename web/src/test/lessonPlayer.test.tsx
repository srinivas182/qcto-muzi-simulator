import { fireEvent, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { LearnHomeData, LearnLesson } from '@simuwork/core';
import { mockApi, nav, renderAt, session } from './harness';

beforeEach(() => localStorage.clear());
afterEach(() => vi.unstubAllGlobals());

const learner = () => session({ permissions: ['learning.use'], navigation: nav('learn') });
const lesson: LearnLesson = {
  id: 'a1', code: 'TS-L02', title: 'Working safely inside a PC', minutes: 5, content_version: 3, locale: 'en', assets: {},
  scenarios: { 'TS-S01': { id: 's1', code: 'TS-S01', title: 'A PC won’t boot' } },
  blocks: [
    { id: 'b1', type: 'keypoints', items: ['Switch off and unplug'] },
    { id: 'b2', type: 'check', prompt: 'What do you do first?', options: ['Open the case', 'Switch off and unplug'], answer: 1, explain: 'Power first.', outcome: null },
    { id: 'b3', type: 'check', prompt: 'Where does the strap go?', options: ['Painted metal', 'Bare metal'], answer: 1, explain: 'Paint does not conduct.', outcome: null },
    { id: 'b4', type: 'try', activity_code: 'TS-S01' },
  ],
};
const home: LearnHomeData = { learner: { name: 'Sipho', qcto_missing: 0 }, enrolments: [{
  id: 'e1', status: 'active', progress: 25, started: true, programme: { code: 'technical-support', title: 'Technical Support', prefix: 'TS' }, version: 1,
  cohort: { name: 'TS Feb', centre: 'Durban', facilitators: [] }, starts_on: '2020-01-01', ends_on: null, days_left: null, seat_returns_on: null,
  next: { id: 'a1', kind: 'lesson', title: 'Working safely inside a PC', minutes: 5, started: false },
}] };
const syncBody = (calls: ReturnType<typeof mockApi>) => {
  const c = calls.mock.calls.find(([u]) => String(u).endsWith('/learn/attempts/sync'));
  return c ? JSON.parse(String((c[1] as RequestInit).body)) : undefined;
};

describe('Course player', () => {
  it('the Continue card opens the next activity', async () => {
    mockApi({ '/learn/home': { data: home } }, learner());
    renderAt('/learn');
    const card = await screen.findByRole('link', { name: /Continue.*Working safely inside a PC/ });
    expect(card).toHaveAttribute('href', '/learn/enrolments/e1/lessons/a1');
  });

  it('counts the first answer to each check, finishes only when all are answered, and syncs the result', async () => {
    const calls = mockApi({
      '/learn/enrolments/e1/lessons/a1': { data: lesson },
      '/learn/attempts/sync': (init?: RequestInit) => {
        const body = JSON.parse(String(init?.body));
        return { data: { accepted: body.attempts.map((a: { client_id: string }) => a.client_id), rejected: [], progress: { e1: 50 } } };
      },
    }, learner());
    renderAt('/learn/enrolments/e1/lessons/a1');
    expect(await screen.findByRole('heading', { name: 'Working safely inside a PC' })).toBeInTheDocument();
    const finish = screen.getByRole('button', { name: 'Finish lesson' });
    expect(finish).toBeDisabled();
    expect(screen.getByText('0 of 2 quick checks done')).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: /Open the case/ })); // wrong first
    fireEvent.click(screen.getByRole('button', { name: /Bare metal/ }));
    expect(screen.getByText('2 of 2 quick checks done')).toBeInTheDocument();
    fireEvent.click(finish);

    expect(await screen.findByText('Lesson done. Well done!')).toBeInTheDocument();
    expect(screen.getByText('You got 1 of 2 quick checks right the first time.')).toBeInTheDocument();
    expect(screen.getByText('Saved to your record.')).toBeInTheDocument();
    const sent = syncBody(calls).attempts[0];
    expect(sent).toMatchObject({ enrolment_id: 'e1', activity_id: 'a1', mode: 'lesson', content_version: 3, completed: true, score: 50, result: { answers: { b2: false, b3: true } } });
    expect(JSON.parse(localStorage.getItem('sw.attempts') ?? '[]')).toEqual([]); // nothing left waiting
  });

  it('keeps the result on the device when offline', async () => {
    mockApi({ '/learn/enrolments/e1/lessons/a1': { data: { ...lesson, blocks: [lesson.blocks[0]!] } } }, learner());
    vi.spyOn(navigator, 'onLine', 'get').mockReturnValue(false);
    renderAt('/learn/enrolments/e1/lessons/a1');
    fireEvent.click(await screen.findByRole('button', { name: 'Finish lesson' }));
    expect(await screen.findByText(/Saved on this phone/)).toBeInTheDocument();
    await waitFor(() => expect(JSON.parse(localStorage.getItem('sw.attempts') ?? '[]')).toHaveLength(1));
    expect(JSON.parse(localStorage.getItem('sw.attempts') ?? '[]')[0]).toMatchObject({ completed: true, activity_id: 'a1' });
  });

  it('shows data saver on by default and lists the programme with statuses', async () => {
    mockApi({
      '/learn/enrolments/e1/lessons/a1': { data: lesson },
      '/learn/enrolments/e1/activities': { data: { progress: 25, activities: [
        { id: 'a1', kind: 'lesson', code: 'TS-L01', title: 'Logging a request', summary: null, minutes: 4, required: true, content_version: 1, outcomes: [], status: 'done', best_score: 100, attempts: 1 },
        { id: 's1', kind: 'scenario', code: 'TS-S01', title: 'A PC won’t boot', summary: null, minutes: 12, required: true, content_version: 1, outcomes: [], status: 'new', best_score: null, attempts: 0 },
      ] } },
    }, learner());
    renderAt('/learn/enrolments/e1/lessons/a1');
    expect(await screen.findByRole('switch', { name: 'Data saver' })).toBeChecked();
    fireEvent.click(screen.getByRole('link', { name: 'All lessons' }));
    expect(await screen.findByText('1. Logging a request')).toBeInTheDocument();
    expect(screen.getByLabelText('Done')).toBeInTheDocument();
    expect(screen.getByText(/best 100%/)).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /A PC won’t boot/ })).toHaveAttribute('href', '/learn/enrolments/e1/simulators/s1');
  });

  it('"Fix it": a wrong first answer gets the hint and one more try; only the first answer counts', async () => {
    const fix = { ...lesson, blocks: [{ id: 'b2', type: 'check' as const, prompt: 'What do you do first?', options: ['Open the case', 'Switch off and unplug', 'Call the user'], answer: 1, explain: 'Power first.', outcome: null, hint: 'Think about electricity.' }] };
    mockApi({ '/learn/enrolments/e1/lessons/a1': { data: fix } }, learner());
    renderAt('/learn/enrolments/e1/lessons/a1');
    fireEvent.click(await screen.findByRole('button', { name: /Open the case/ }));
    expect(screen.getByRole('status')).toHaveTextContent('Not quite. Think about electricity.');
    expect(screen.getByRole('button', { name: /Open the case/ })).toBeDisabled();
    expect(screen.getByText('1 of 1 quick checks done')).toBeInTheDocument(); // the first answer is recorded
    fireEvent.click(screen.getByRole('button', { name: /Switch off and unplug/ }));
    expect(screen.getByRole('status')).toHaveTextContent('Fixed it! Power first.');
    expect(screen.getByRole('button', { name: /Call the user/ })).toBeDisabled();
  });
});
