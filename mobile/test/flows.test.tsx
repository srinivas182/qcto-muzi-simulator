import * as SecureStore from 'expo-secure-store';
import { act, cleanup, fireEvent, renderRouter, screen, waitFor } from 'expo-router/testing-library';
import { mockApi, seed, session, branding } from './helpers';

/** Type into a field by calling its change handler (works for fields that appear after a state change). */
const type = (label: string, text: string) => act(async () => { (await screen.findByLabelText(label)).props.onChangeText(text); });

/** Let state updates, effects and screen transitions finish before the next touch (renderRouter uses fake timers). */
const settle = () => act(async () => { jest.advanceTimersByTime(1000); });

// Finish animations and pending work from one test before the next one renders.
afterEach(async () => {
  await settle();
  cleanup();
});

describe('first start', () => {
  const tenantApi = () => mockApi({ '/tenant': (_b: unknown, init: RequestInit) => [(init.headers as Record<string, string>)['X-Tenant'] === 'ubuntu' ? 200 : 404, { data: { id: 't1', slug: 'ubuntu', name: 'Ubuntu Skills Academy', status: 'active', branding, locales: {} } }] });

  it('lets people pick their language before anything else', async () => {
    tenantApi();
    await renderRouter('./app', { initialUrl: '/' });
    const zulu = await screen.findByRole('radio', { name: 'isiZulu' });
    await act(async () => fireEvent.press(zulu));
    expect(await screen.findByText('Qala')).toBeOnTheScreen();
    expect(screen.getByText('Zilolongele umsebenzi wangempela, noma kuphi.')).toBeOnTheScreen();
  });

  it('explains when an academy code is unknown', async () => {
    tenantApi();
    await renderRouter('./app', { initialUrl: '/academy' });
    await type('Academy code', 'nope');
    await settle();
    await act(async () => fireEvent.press(screen.getByRole('button', { name: 'Continue' })));
    expect(await screen.findByText("We couldn't find that academy. Check the code and try again.")).toBeOnTheScreen();
  });

  it('accepts a typed academy address and moves on to sign-in', async () => {
    const calls = tenantApi();
    await renderRouter('./app', { initialUrl: '/academy' });
    await type('Academy code', ' UBUNTU.simuwork.co.za ');
    await settle();
    await act(async () => fireEvent.press(screen.getByRole('button', { name: 'Continue' })));
    await settle();
    expect(await screen.findByText('Sign in to Ubuntu Skills Academy')).toBeOnTheScreen();
    expect(calls.at(-1)?.init.headers).toMatchObject({ 'X-Tenant': 'ubuntu' });
  });
});

describe('sign-in', () => {
  it('signs in by phone code, keeps the token in the keystore, and opens the learner home', async () => {
    await seed();
    let verifyBody: Record<string, unknown> | undefined;
    mockApi({
      '/auth/otp/request': { sent_to: '+27 82 *** 4567' },
      '/auth/otp/verify': ((b: Record<string, unknown>) => { verifyBody = b; return [200, { token: 'tok-9', token_type: 'Bearer' }]; }) as never,
      '/session': { data: session() },
    });
    await renderRouter('./app', { initialUrl: '/sign-in' });

    await type('Mobile number', '082 123 4567');
    await settle();
    await act(async () => fireEvent.press(screen.getByRole('button', { name: 'Send code' })));
    await type('6-digit code', '123456');
    await settle();
    await act(async () => fireEvent.press(screen.getByRole('button', { name: 'Sign in' })));

    await waitFor(() => expect(SecureStore.setItemAsync).toHaveBeenCalledWith('sw_token', 'tok-9', expect.anything()));
    await settle();
    expect(verifyBody).toMatchObject({ phone: '082 123 4567', code: '123456', client: 'mobile' });
    expect(await screen.findByText(/Good (morning|afternoon|evening), Thandiwe/)).toBeOnTheScreen();
    expect(screen.getByRole('tab', { name: 'Simulate' })).toBeOnTheScreen();
  });

  it('gives staff a pointer to the web portal and no learning tabs', async () => {
    await seed({ signedIn: true });
    mockApi({ '/session': { data: session({ permissions: ['classes.view', 'attendance.mark'] }) } });
    await renderRouter('./app', { initialUrl: '/home' });
    expect(await screen.findByText('Your tools are on the web for now')).toBeOnTheScreen();
    expect(screen.queryByRole('tab', { name: 'Simulate' })).toBeNull();
    expect(screen.getByRole('tab', { name: 'Me' })).toBeOnTheScreen();
  });

  it('sends a signed-out person to sign-in', async () => {
    await seed();
    mockApi({});
    await renderRouter('./app', { initialUrl: '/home' });
    expect(await screen.findByText('Welcome back')).toBeOnTheScreen();
  });
});
