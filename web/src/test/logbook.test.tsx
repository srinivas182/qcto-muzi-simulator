import { fireEvent, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it } from 'vitest';
import type { LogbookView } from '@simuwork/core';
import { mockApi, nav, renderAt, session } from './harness';

beforeEach(() => localStorage.clear());

const learner = () => session({ permissions: ['learning.use'], navigation: nav('learn') });
const view: LogbookView = {
  workplace: { id: 'w1', employer: 'Khanya IT Repairs', site: 'Soweto', mentor_name: 'Thabo Mokoena', confirm_by: 'link', starts_on: '2027-03-01', ends_on: null },
  outcomes: [{ code: 'TS-1.1', statement: 'Set up a workstation safely' }, { code: 'TS-1.2', statement: 'Help a customer with a fault' }],
  summary: { days: 3, confirmed_days: 2, hours: 22, confirmed_hours: 15, min_days: 20, outcomes: {} },
  entries: [{ id: 'x1', client_id: 'c1', day: '2027-03-09', hours: 7, outcomes: ['TS-1.1'], note: null, photo: false, status: 'queried', mentor_note: 'You left at 12.', confirmed_at: null, confirmed_name: null }],
  late_days: 14,
};

describe('Workplace logbook', () => {
  it('checks in a day against the outcomes, and shows the mentor’s question', async () => {
    const calls = mockApi({ '/learn/enrolments/e1/logbook': (init?: RequestInit) => (init?.method === 'POST' ? { data: { ...view.entries[0], id: 'x2', status: 'submitted' } } : { data: view }) }, learner());
    renderAt('/learn/enrolments/e1/logbook');
    expect(await screen.findByText('Khanya IT Repairs · Soweto')).toBeInTheDocument();
    expect(screen.getByRole('progressbar', { name: 'days confirmed' })).toHaveAttribute('aria-valuenow', '2');
    expect(screen.getByText('Your mentor asks: You left at 12. Fix it and send the day again.')).toBeInTheDocument();
    const send = screen.getByRole('button', { name: 'Send check-in' });
    expect(send).toBeDisabled(); // pick at least one outcome
    fireEvent.click(screen.getByLabelText('Help a customer with a fault'));
    fireEvent.change(screen.getByLabelText('What you did (optional)'), { target: { value: 'Fixed a printer.' } });
    fireEvent.click(send);
    await waitFor(() => expect(calls.mock.calls.some(([, i]) => (i as RequestInit | undefined)?.method === 'POST')).toBe(true));
    const post = calls.mock.calls.find(([, i]) => (i as RequestInit | undefined)?.method === 'POST')!;
    expect(JSON.parse(String((post[1] as RequestInit).body))).toMatchObject({ hours: 8, outcomes: ['TS-1.2'], note: 'Fixed a printer.' });
  });

  it('with no signal the check-in waits on the phone and is sent later with the same id', async () => {
    let online = false;
    const calls = mockApi({ '/learn/enrolments/e1/logbook': (init?: RequestInit) => {
      if (init?.method !== 'POST') return { data: view };
      if (!online) throw new TypeError('Failed to fetch');
      return { data: { ...view.entries[0], id: 'x3' } };
    } }, learner());
    renderAt('/learn/enrolments/e1/logbook');
    fireEvent.click(await screen.findByLabelText('Set up a workstation safely'));
    fireEvent.click(screen.getByRole('button', { name: 'Send check-in' }));
    expect(await screen.findByText('1 check-ins are waiting on this device and will be sent when you are online.')).toBeInTheDocument();
    const id = (JSON.parse(localStorage.getItem('sw.logbook.e1')!) as { client_id: string }[])[0]!.client_id;
    online = true;
    window.dispatchEvent(new Event('online'));
    await waitFor(() => expect(localStorage.getItem('sw.logbook.e1')).toBe('[]'));
    const ids = calls.mock.calls.filter(([, i]) => (i as RequestInit | undefined)?.method === 'POST').map(([, i]) => JSON.parse(String((i as RequestInit).body)).client_id);
    expect(new Set(ids)).toEqual(new Set([id]));
  });
});
