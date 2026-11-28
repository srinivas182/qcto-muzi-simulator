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

describe('lesson player', () => {
  const lesson = {
    id: 'a1', code: 'TS-L02', title: 'Working safely inside a PC', minutes: 5, content_version: 2, locale: 'en', assets: {}, scenarios: {},
    blocks: [
      { id: 'b1', type: 'narration', script: 'Two habits keep you safe.', audio_asset_id: null },
      { id: 'b2', type: 'check', prompt: 'What do you do first?', options: ['Open the case', 'Switch off and unplug'], answer: 1, explain: 'Power first.', outcome: null },
    ],
  };

  it('opens the next lesson from the Continue card, saves the answer and syncs the result', async () => {
    await seed({ signedIn: true });
    const calls = mockApi({
      '/session': { data: session() },
      '/learn/home': { data: { ...home, enrolments: [{ ...home.enrolments[0], next: { id: 'a1', kind: 'lesson', title: 'Working safely inside a PC', minutes: 5, started: false } }] } },
      '/learn/enrolments/e1/lessons/a1?locale=en': { data: lesson },
      '/learn/attempts/sync': ((b: { attempts: { client_id: string }[] }) => [200, { data: { accepted: b.attempts.map((a) => a.client_id), rejected: [], progress: { e1: 40 } } }]) as never,
    });
    await renderRouter('./app', { initialUrl: '/home' });
    await act(async () => fireEvent.press(await screen.findByText('Working safely inside a PC')));
    await settle();
    expect(await screen.findByText('Two habits keep you safe.')).toBeOnTheScreen();
    expect(screen.getByRole('button', { name: 'Finish lesson' })).toBeDisabled();
    await act(async () => fireEvent.press(screen.getByText('Switch off and unplug')));
    await settle();
    expect(screen.getByText(/Power first./)).toBeOnTheScreen();
    await act(async () => fireEvent.press(screen.getByRole('button', { name: 'Finish lesson' })));
    await settle();
    expect(await screen.findByText('Lesson done. Well done!')).toBeOnTheScreen();
    expect(screen.getByText('Saved to your record.')).toBeOnTheScreen();
    const sent = JSON.parse(String(calls.find((c) => c.path === '/learn/attempts/sync')!.init.body)).attempts[0];
    expect(sent).toMatchObject({ enrolment_id: 'e1', activity_id: 'a1', mode: 'lesson', completed: true, score: 100, device: 'mobile' });
  });
});
