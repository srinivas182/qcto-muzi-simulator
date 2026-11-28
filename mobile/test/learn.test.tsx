import AsyncStorage from '@react-native-async-storage/async-storage';
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

describe('simulator', () => {
  const base = { hints: [], why: '', outcome: null, lesson: null, critical: false, only: [], sets: {}, wrong: [] };
  const scenario = {
    id: 's1', code: 'TS-S01', title: 'A PC won’t boot', summary: null, minutes: 12, content_version: 1, locale: 'en', lessons: { 'TS-L02': 'l2' }, results: { practice: null, job: null },
    content: { scene: 'pc-bench', pass_mark: 70, variants: [], job: { minutes: 12, customer: 'Thandi' }, steps: [
      { ...base, id: 'unplug', type: 'tap', target: 'wall-switch', prompt: 'Make the PC safe.', critical: true, why: 'Live PCs shock.', lesson: { code: 'TS-L02', block: null },
        sets: { 'wall-switch': 'off' }, wrong: [{ on: 'case-panel', effect: 'spark', message: 'You opened a live PC.' }] },
      { ...base, id: 'fitram', type: 'place', target: 'ram-new', slot: 'slot-a1', prompt: 'Fit the new RAM.', sets: { 'ram-new': 'in' } },
    ] },
  };

  it('plays a practice run step by step, shows consequences and debriefs with the server-matching score', async () => {
    await seed({ signedIn: true });
    const calls = mockApi({
      '/session': { data: session() },
      '/learn/enrolments/e1/scenarios/s1?locale=en': { data: scenario },
      '/learn/attempts/sync': ((b: { attempts: { client_id: string }[] }) => [200, { data: { accepted: b.attempts.map((a) => a.client_id), rejected: [], progress: { e1: 60 } } }]) as never,
    });
    await renderRouter('./app', { initialUrl: '/learn/e1/simulator/s1' });
    await act(async () => fireEvent.press(await screen.findByText('Start: Practice')));
    expect(await screen.findByText('Make the PC safe.')).toBeOnTheScreen();
    await act(async () => fireEvent.press(screen.getByText('Side panel of the case')));
    expect(screen.getByText(/You opened a live PC\./)).toBeOnTheScreen();
    // The coach (offline here, so the author's note) explains why.
    await act(async () => fireEvent.press(screen.getByText('Why?')));
    await settle();
    expect(await screen.findByText('Live PCs shock.')).toBeOnTheScreen();
    await act(async () => fireEvent.press(screen.getByText('Wall socket switch')));
    await act(async () => fireEvent.press(screen.getByText('New RAM stick')));
    expect(screen.getByText('Holding the new ram stick. Now tap where it goes.')).toBeOnTheScreen();
    await act(async () => fireEvent.press(screen.getByText('Memory slot A1')));
    await settle();
    expect(await screen.findByText('Score 88%')).toBeOnTheScreen(); // (0.75 + 1) / 2; asking "why" is free
    expect(screen.getByText('Review the lesson')).toBeOnTheScreen();
    const sent = JSON.parse(String(calls.find((c) => c.path === '/learn/attempts/sync')!.init.body)).attempts[0];
    expect(sent).toMatchObject({ activity_id: 's1', mode: 'practice', completed: true, score: 88, device: 'mobile' });
  });
});

describe('job-ready checklist', () => {
  it('shows each skill with its status, from the programme screen', async () => {
    await seed({ signedIn: true });
    mockApi({
      '/session': { data: session() },
      '/learn/enrolments/e1/activities': { data: { progress: 40, activities: [] } },
      '/learn/enrolments/e1/checklist': { data: { areas: [{ number: 2, title: 'Diagnose faults', outcomes: [
        { code: 'TS-2.2', statement: 'Diagnose memory faults', plain_words: 'Find why a PC will not start', status: 'shown' },
        { code: 'TS-2.3', statement: 'Diagnose network faults', plain_words: null, status: 'not_yet' },
      ] }] } },
    });
    await renderRouter('./app', { initialUrl: '/learn/e1' });
    await act(async () => fireEvent.press(await screen.findByText('Job-ready checklist')));
    expect(await screen.findByText('1 of 2 skills shown')).toBeOnTheScreen();
    expect(screen.getByLabelText('Find why a PC will not start: Shown on a real job')).toBeOnTheScreen();
  });

  it('shares a read-only link through the phone’s share sheet', async () => {
    const { Share } = jest.requireActual<typeof import('react-native')>('react-native');
    const spy = jest.spyOn(Share, 'share').mockResolvedValue({ action: 'sharedAction' } as never);
    await seed({ signedIn: true });
    mockApi({
      '/session': { data: session() },
      '/learn/enrolments/e1/checklist': { data: { areas: [] } },
      '/learn/enrolments/e1/shares': { data: { id: 'sh1', label: null, created_at: '', expires_at: '', views: 0, live: true, url: 'https://ubuntu.simuwork.co.za/c/TOKEN' } },
    });
    await renderRouter('./app', { initialUrl: '/learn/e1/checklist' });
    await act(async () => fireEvent.press(await screen.findByText('Share with a mentor or employer')));
    await settle();
    expect(spy).toHaveBeenCalledWith({ message: 'Job-ready checklist: https://ubuntu.simuwork.co.za/c/TOKEN' });
  });
});

describe('offline packs', () => {
  const lesson = { id: 'l1', code: 'TS-L01', title: 'Working safely', minutes: 4, content_version: 1, locale: 'en', scenarios: {},
    assets: { a1: { id: 'a1', kind: 'image', url: 'https://cdn.test/a1.webp', mime: 'image/webp', bytes: 2000, name: 'strap' } },
    blocks: [{ id: 'b1', type: 'narration', script: 'Offline narration works.', audio_asset_id: null }] };
  const scenario = { id: 's1', code: 'TS-S01', title: 'A PC won’t boot', summary: null, minutes: 12, content_version: 1, locale: 'en', lessons: {},
    content: { scene: 'pc-bench', pass_mark: 70, job: null, variants: [], steps: [{ id: 'unplug', type: 'tap', target: 'wall-switch', prompt: 'Make it safe.', hints: [], why: '', outcome: null, lesson: null, critical: false, only: [], sets: {}, wrong: [] }] } };
  const pack = { format: 1, enrolment_id: 'e1', locale: 'en', version: 'v1', built_at: '', bytes: 3_200_000, lessons: { l1: lesson }, scenarios: { s1: scenario },
    assets: [lesson.assets.a1], runtime: { url: 'https://ubuntu.simuwork.test/embed/sim-3d.html', scenes: ['pc-bench'] } };

  it('downloads a programme, then lessons and the 3D simulator work with no connection', async () => {
    await seed({ signedIn: true });
    const calls = mockApi({ '/session': { data: session() }, '/learn/enrolments/e1/activities': { data: { progress: 0, activities: [] } }, '/learn/enrolments/e1/pack?locale=en': { data: pack } });
    await renderRouter('./app', { initialUrl: '/learn/e1' });
    const { Alert } = jest.requireActual<typeof import('react-native')>('react-native');
    const alert = jest.spyOn(Alert, 'alert').mockImplementation(() => undefined);
    await act(async () => fireEvent.press(await screen.findByText('Download')));
    // On mobile data the learner is told the size first.
    expect(alert.mock.calls[0]![1]).toBe('This download uses about a few MB of data. Wi-Fi is better if you can.');
    await act(async () => (alert.mock.calls[0]![2] as { onPress?: () => void }[])[1]!.onPress!());
    await settle();
    expect(alert).toHaveBeenCalledWith('Saved on this phone. You can now train with no data.');
    expect(await screen.findByText('Ready to use offline')).toBeOnTheScreen();
    expect(screen.getByText(/3.1 MB on this phone/)).toBeOnTheScreen();
    const { File } = jest.requireMock<{ File: { downloadFileAsync: jest.Mock } }>('expo-file-system');
    expect(File.downloadFileAsync.mock.calls.map((c) => c[0])).toEqual(['https://cdn.test/a1.webp', 'https://ubuntu.simuwork.test/embed/sim-3d.html']);

    // "Check for updates" sends the version and gets 304: nothing downloaded again.
    mockApi({ '/session': { data: session() }, '/learn/enrolments/e1/pack?locale=en': () => [304, null] });
    await act(async () => fireEvent.press(screen.getByText('Check for updates')));
    await settle();

    // No connection: the app uses the pack.
    global.fetch = jest.fn(async () => { throw new TypeError('Network request failed'); }) as typeof fetch;
    expect(calls.length).toBeGreaterThan(0);
    await renderRouter('./app', { initialUrl: '/learn/e1/lesson/l1' });
    expect(await screen.findByText('Offline narration works.')).toBeOnTheScreen();
    await renderRouter('./app', { initialUrl: '/learn/e1/simulator/s1' });
    await act(async () => fireEvent.press(await screen.findByText('Start: Practice')));
    expect(await screen.findByTestId('webview')).toBeOnTheScreen();
    expect(screen.getByText('3D view')).toBeOnTheScreen();
  });
});

describe('class mode', () => {
  it('the learner joins the class from home and moves are sent live, tagged with the class', async () => {
    await seed({ signedIn: true });
    const scenario = { id: 's1', code: 'TS-S01', title: 'A PC won’t boot', summary: null, minutes: 12, content_version: 1, locale: 'en', lessons: {}, results: { practice: null, job: null },
      content: { scene: 'pc-bench', pass_mark: 70, job: null, variants: [], steps: [
        { id: 'unplug', type: 'tap', target: 'wall-switch', prompt: 'Make it safe.', hints: [], why: '', outcome: null, lesson: null, critical: false, only: [], sets: {}, wrong: [] },
        { id: 'strap', type: 'tap', target: 'strap', prompt: 'Strap on.', hints: [], why: '', outcome: null, lesson: null, critical: false, only: [], sets: {}, wrong: [] },
      ] } };
    const calls = mockApi({
      '/session': { data: session() },
      '/learn/home': { data: { ...home, enrolments: [{ ...home.enrolments[0], next: null, class: { id: 'cs1', cohort_id: 'c1', activity_id: 's1', title: 'A PC won’t boot', code: 'TS-S01', mode: 'practice', started_at: '', live: true } }] } },
      '/learn/enrolments/e1/scenarios/s1?locale=en': { data: scenario },
      '/learn/attempts/sync': ((b: { attempts: { client_id: string }[] }) => [200, { data: { accepted: b.attempts.map((a) => a.client_id), rejected: [], progress: {} } }]) as never,
    });
    await renderRouter('./app', { initialUrl: '/home' });
    await act(async () => fireEvent.press(await screen.findByText('Your class is doing this now')));
    await act(async () => fireEvent.press(await screen.findByText('Join')));
    await act(async () => fireEvent.press(await screen.findByText('Wall socket switch')));
    await settle();
    const sync = calls.find((c) => c.path === '/learn/attempts/sync');
    expect(JSON.parse(String(sync!.init.body)).attempts[0]).toMatchObject({ mode: 'practice', result: { class_session: 'cs1' } });
  });
});

describe('knowledge tests', () => {
  it('takes a test on the phone, keeps answers on the phone and hands in', async () => {
    await seed({ signedIn: true });
    const paper = { id: 's1', task: { id: 't1', code: 'TS-A01', title: 'Knowledge test', pass_mark: 70 }, attempt: 1, locale: 'en', status: 'open', started_at: '', deadline_at: '', seconds_left: 1200, answers: {},
      questions: [{ id: 'q1', type: 'single', prompt: 'What do you do first?', marks: 1, options: ['Call the user', 'Switch off and unplug'] }] };
    const calls = mockApi({
      '/session': { data: session() },
      '/learn/enrolments/e1/tests/t1/start': { data: paper },
      '/learn/sittings/s1': ((b: { submit?: boolean }) => [200, { data: b.submit ? { id: 's1', status: 'marked', attempt: 1, score: 100, result: 'competent', late: false, submitted_at: '', revise: [] } : paper }]) as never,
    });
    await renderRouter('./app', { initialUrl: '/learn/e1/test/t1' });
    await act(async () => fireEvent.press(await screen.findByText('Start the test')));
    await act(async () => fireEvent.press(await screen.findByLabelText('Switch off and unplug')));
    expect(JSON.parse((await AsyncStorage.getItem('sw.test.s1'))!)).toEqual({ q1: 1 });
    const { Alert } = jest.requireActual<typeof import('react-native')>('react-native');
    const alert = jest.spyOn(Alert, 'alert').mockImplementation(() => undefined);
    alert.mockClear(); // an earlier test may have spied on Alert already
    await act(async () => fireEvent.press(screen.getByText('Hand in')));
    await act(async () => (alert.mock.calls[0]![2] as { onPress?: () => void }[])[1]!.onPress!());
    await settle();
    expect(await screen.findByText('Competent. Well done!')).toBeOnTheScreen();
    const put = calls.filter((c) => c.path === '/learn/sittings/s1').pop()!;
    expect(JSON.parse(String(put.init.body))).toEqual({ answers: { q1: 1 }, submit: true });
  });

  it('a camera-checked test waits for my agreement and sends it with the start', async () => {
    await seed({ signedIn: true });
    const paper = { id: 's2', task: { id: 't1', code: 'TS-A01', title: 'Knowledge test', pass_mark: 70 }, attempt: 1, locale: 'en', status: 'open', started_at: '', deadline_at: '', seconds_left: 1200, answers: {}, camera: true, photos_max: 6,
      questions: [{ id: 'q1', type: 'single', prompt: 'What do you do first?', marks: 1, options: ['Call the user', 'Switch off and unplug'] }] };
    const calls = mockApi({
      '/session': { data: session() },
      '/learn/enrolments/e1/assessments': { data: [{ id: 't1', code: 'TS-A01', title: 'Knowledge test', component: 'knowledge', kind: 'test', outcomes: [], minutes: 20, questions: 1, pass_mark: 70, attempts_allowed: 2, attempts_used: 0, open_sitting: null, waiting_for_marking: false, result: null, best_score: null, camera: true }] },
      '/learn/enrolments/e1/tests/t1/start': { data: paper },
    });
    await renderRouter('./app', { initialUrl: '/learn/e1/test/t1' });
    expect(await screen.findByText('This test uses a camera check')).toBeOnTheScreen();
    await act(async () => fireEvent.press(screen.getByText('Start the test')));
    expect(calls.some((c) => c.path.endsWith('/start'))).toBe(false); // not until I agree
    await act(async () => fireEvent.press(screen.getByLabelText('I agree to the camera check for this test.')));
    await act(async () => fireEvent.press(screen.getByText('Start the test')));
    await settle();
    const start = calls.find((c) => c.path.endsWith('/start'))!;
    expect(JSON.parse(String(start.init.body))).toMatchObject({ camera_consent: true });
  });

  it('shows the assessor’s feedback with links, and reads questions aloud for a learner who needs it', async () => {
    await seed({ signedIn: true });
    const Speech = jest.requireMock<{ speak: jest.Mock }>('expo-speech');
    Speech.speak.mockClear();
    const item = { id: 't9', code: 'TS-P01', title: 'Fix the slow PC', component: 'practical', kind: 'simulator', outcomes: [], minutes: null, questions: null, pass_mark: null, attempts_allowed: 3, attempts_used: 1,
      open_sitting: null, waiting_for_marking: false, result: 'not_yet', best_score: null, simulator_id: 'a2',
      feedback: { text: 'Switch off at the wall first.', at: '', links: [{ type: 'step', ref: 'unplug', label: 'Make it safe.', activity_id: 'a2' }] } };
    const paper = { id: 's3', task: { id: 't1', code: 'TS-A01', title: 'Knowledge test', pass_mark: 70 }, attempt: 1, locale: 'zu', status: 'open', started_at: '', deadline_at: '', seconds_left: 1800, answers: {}, read_aloud: true, extra_time: 50,
      questions: [{ id: 'q1', type: 'single', prompt: 'What do you do first?', marks: 1, options: ['Call the user', 'Switch off'] }] };
    mockApi({ '/session': { data: session() }, '/learn/enrolments/e1/assessments': { data: [item] }, '/learn/enrolments/e1/tests/t1/start': { data: paper } });
    await renderRouter('./app', { initialUrl: '/learn/e1/assessments' });
    expect(await screen.findByText('Switch off at the wall first.')).toBeOnTheScreen();
    expect(screen.getByText('Practise this step: Make it safe.')).toBeOnTheScreen();
    expect(screen.getByText('2 more opportunities on this task.')).toBeOnTheScreen();

    await renderRouter('./app', { initialUrl: '/learn/e1/test/t1' });
    await act(async () => fireEvent.press(await screen.findByText('Start the test')));
    expect(await screen.findByText('You have 50% extra time on this test.')).toBeOnTheScreen();
    await act(async () => fireEvent.press(screen.getByLabelText('Read question 1 aloud')));
    expect(Speech.speak).toHaveBeenCalledWith('What do you do first?. 1. Call the user. 2. Switch off', expect.objectContaining({ language: 'zu-ZA' }));
  });

  it('checks in a workplace day on the phone and keeps it when there is no signal', async () => {
    await seed({ signedIn: true });
    await AsyncStorage.removeItem('sw.logbook.e1');
    const view = { workplace: { id: 'w1', employer: 'Khanya IT Repairs', site: null, mentor_name: 'Thabo Mokoena', confirm_by: 'link', starts_on: '2027-03-01', ends_on: null },
      outcomes: [{ code: 'TS-1.1', statement: 'Set up a workstation safely' }], summary: { days: 0, confirmed_days: 0, hours: 0, confirmed_hours: 0, min_days: 20, outcomes: {} }, entries: [], late_days: 14 };
    const calls = mockApi({ '/session': { data: session() }, '/learn/enrolments/e1/logbook': ((b: unknown) => (b === undefined ? [200, { data: view }] : [0, null])) as never });
    await renderRouter('./app', { initialUrl: '/learn/e1/logbook' });
    expect(await screen.findByText('Khanya IT Repairs')).toBeOnTheScreen();
    expect(screen.getByText('0/20')).toBeOnTheScreen();
    await act(async () => fireEvent.press(screen.getByLabelText('Set up a workstation safely')));
    await act(async () => fireEvent.press(screen.getByText('Send check-in')));
    await settle();
    const saved = JSON.parse((await AsyncStorage.getItem('sw.logbook.e1'))!) as { outcomes: string[]; hours: number }[];
    expect(saved).toHaveLength(1);
    expect(saved[0]).toMatchObject({ outcomes: ['TS-1.1'], hours: 8 });
    expect(calls.some((c) => c.path === '/learn/enrolments/e1/logbook' && c.init.method === 'POST')).toBe(true);
  });
});
