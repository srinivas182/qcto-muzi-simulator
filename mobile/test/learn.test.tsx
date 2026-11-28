import { act, cleanup, fireEvent, renderRouter, screen, waitFor } from 'expo-router/testing-library';
import { mockApi, seed, session } from './helpers';

const settle = () => act(async () => { jest.advanceTimersByTime(1000); });
afterEach(async () => { await settle(); cleanup(); });

const home = {
  learner: { name: 'Thandiwe Zulu', qcto_missing: 2 },
  enrolments: [{
    id: 'e1', status: 'enrolled', progress: 0, started: false, programme: { code: 'technical-support', title: 'Technical Support', prefix: 'TS' }, version: 1,
    cohort: { name: 'TS February 2027', centre: 'Durban campus', facilitators: ['Ayesha Patel'] }, starts_on: '2020-02-03', ends_on: '2099-12-03', days_left: 120, seat_returns_on: '2099-02-17',
  }],
};

describe('learner home', () => {
  it('shows my programme and starts it when I tap Start learning', async () => {
    await seed({ signedIn: true });
    const calls = mockApi({ '/session': { data: session() }, '/learn/home': { data: home }, '/learn/enrolments/e1/start': { data: { status: 'active' } } });
    await renderRouter('./app', { initialUrl: '/home' });
    expect(await screen.findByText('Technical Support')).toBeOnTheScreen();
    expect(screen.getByText('120 days left')).toBeOnTheScreen();
    expect(screen.getByText('Durban campus')).toBeOnTheScreen();
    expect(screen.getByText('Complete your details.')).toBeOnTheScreen();
    await act(async () => fireEvent.press(screen.getByRole('button', { name: 'Start learning' })));
    await waitFor(() => expect(calls.some((c) => c.path === '/learn/enrolments/e1/start' && c.init.method === 'POST')).toBe(true));
  });

  it('joins a class with the code from the poster', async () => {
    await seed({ signedIn: true });
    const calls = mockApi({ '/session': { data: session() }, '/learn/home': { data: { learner: null, enrolments: [] } }, '/cohorts/join': { data: { enrolment_id: 'e9', cohort: 'TS February 2027' } } });
    await renderRouter('./app', { initialUrl: '/home' });
    await act(async () => (await screen.findByLabelText('Class code')).props.onChangeText('abcd2345'));
    await settle();
    await act(async () => fireEvent.press(screen.getByRole('button', { name: 'Join' })));
    expect(await screen.findByText('You are in TS February 2027')).toBeOnTheScreen();
    expect(JSON.parse(String(calls.find((c) => c.path === '/cohorts/join')!.init.body))).toEqual({ code: 'ABCD2345' });
  });
});
