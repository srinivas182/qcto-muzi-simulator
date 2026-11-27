import { act, cleanup, fireEvent, renderRouter, screen, waitFor } from 'expo-router/testing-library';
import { mockApi, seed, session } from './helpers';

/** Let state updates, effects and screen transitions finish before the next touch (renderRouter uses fake timers). */
const settle = () => act(async () => { jest.advanceTimersByTime(1000); });

// Finish animations and pending work from one test before the next one renders.
afterEach(async () => {
  await settle();
  cleanup();
});

describe('POPIA', () => {
  it('asks for POPIA consent before use', async () => {
    await seed({ signedIn: true });
    let consented = false;
    const calls = mockApi({
      '/session': () => [200, { data: session({ user: { ...session().user, consent_required: consented ? [] : ['terms'] } }) }],
      '/auth/consents': () => { consented = true; return [201, { data: {} }]; },
    });
    await renderRouter('./app', { initialUrl: '/home' });
    const agree = await screen.findByRole('button', { name: 'Agree and continue' });
    expect(agree).toBeDisabled();
    fireEvent.press(screen.getByRole('checkbox'));
    await settle();
    await act(async () => fireEvent.press(screen.getByRole('button', { name: 'Agree and continue' })));
    await waitFor(() => expect(screen.queryByRole('button', { name: 'Agree and continue' })).toBeNull());
    expect(calls.filter((c) => c.path === '/auth/consents')).toHaveLength(1);
  });

});
