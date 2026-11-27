import AsyncStorage from '@react-native-async-storage/async-storage';
import * as SecureStore from 'expo-secure-store';
import type { Session } from '@simuwork/core';

export const branding = { display_name: 'Ubuntu Skills Academy', logo_url: null, primary: '#0F766E', accent: '#C9A24B', nav: '#0D1230', default_locale: 'en' };

export function session(overrides: Partial<Session> = {}): Session {
  return {
    area: 'tenant',
    user: { id: 'u1', name: 'Thandiwe Khumalo', email: null, phone: '+27821234567', locale: 'en', tenant_id: 't1', is_platform_staff: false, two_factor_enabled: false, consent_required: [] },
    permissions: ['learning.use'],
    navigation: [],
    tenant: { id: 't1', name: 'Ubuntu Skills Academy', slug: 'ubuntu', branding },
    support: null,
    locales: {},
    ...overrides,
  };
}

/** Saved academy (and optionally a signed-in token), as after a previous app start. */
export async function seed({ signedIn = false } = {}) {
  await AsyncStorage.setItem('sw.academy', JSON.stringify({ slug: 'ubuntu', name: 'Ubuntu Skills Academy', branding }));
  if (signedIn) await SecureStore.setItemAsync('sw_token', 'token-123');
}

type Handler = (body: Record<string, unknown> | undefined, init: RequestInit) => [number, unknown];

/** Fake API keyed by path ("/tenant", "/session", ...). Unknown paths answer 404. */
export function mockApi(handlers: Record<string, Handler | unknown>) {
  const calls: { path: string; init: RequestInit }[] = [];
  global.fetch = jest.fn(async (input: RequestInfo | URL, init: RequestInit = {}) => {
    const path = String(input).replace(/^.*\/api\/v1/, '');
    calls.push({ path, init });
    const h = handlers[path];
    const [status, body] = h === undefined ? [404, { message: 'Not found' }] : typeof h === 'function' ? (h as Handler)(init.body ? JSON.parse(String(init.body)) : undefined, init) : [200, h];
    // Minimal Response stand-in (the React Native test environment has no WHATWG Response).
    return { ok: status >= 200 && status < 300, status, text: async () => JSON.stringify(body) } as unknown as Response;
  }) as typeof fetch;
  return calls;
}
