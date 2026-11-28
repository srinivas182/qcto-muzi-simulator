import Constants from 'expo-constants';
import { Platform } from 'react-native';

/**
 * API client for the mobile app. One app serves every academy: the academy is sent as
 * X-Tenant to the platform API host, and the person's token as a bearer token.
 */
const configured = (process.env.EXPO_PUBLIC_API_URL as string | undefined) ?? (Constants.expoConfig?.extra?.apiUrl as string | undefined);
export const API_URL = (configured ?? 'https://api.simuwork.co.za').replace(/\/$/, '') + '/api/v1';

export class ApiError extends Error {
  readonly status: number;
  readonly errors: Record<string, string[]>;

  constructor(status: number, message: string, errors: Record<string, string[]> = {}) {
    super(message);
    this.status = status;
    this.errors = errors;
  }

  field(name: string): string | undefined {
    return this.errors[name]?.[0];
  }

  /** The first message to show a person. */
  get firstMessage(): string {
    return Object.values(this.errors)[0]?.[0] ?? this.message;
  }
}

export interface RequestContext {
  tenant?: string | null;
  token?: string | null;
}

let context: RequestContext = {};
/** Tests start each case from a clean slate. */
export const resetRequestContext = () => {
  context = {};
};
/** Set by the academy and auth providers; every request uses the latest values. */
export const setRequestContext = (next: RequestContext) => {
  context = { ...context, ...next };
};

export async function api<T>(path: string, options: { method?: string; body?: unknown; tenant?: string | null; signal?: AbortSignal; headers?: Record<string, string> } = {}): Promise<T> {
  const tenant = options.tenant !== undefined ? options.tenant : context.tenant;
  const headers: Record<string, string> = { Accept: 'application/json', 'X-Client': 'mobile' };
  if (tenant) headers['X-Tenant'] = tenant;
  if (context.token) headers.Authorization = `Bearer ${context.token}`;
  const form = typeof FormData !== 'undefined' && options.body instanceof FormData; // uploads: fetch sets the boundary
  if (options.body !== undefined && !form) headers['Content-Type'] = 'application/json';
  Object.assign(headers, options.headers);

  let res: Response;
  try {
    res = await fetch(API_URL + path, {
      method: options.method ?? 'GET',
      headers,
      body: options.body === undefined ? undefined : form ? (options.body as FormData) : JSON.stringify(options.body),
      signal: options.signal,
    });
  } catch {
    throw new ApiError(0, 'offline');
  }

  const text = await res.text();
  let data: unknown = null;
  try {
    data = text ? JSON.parse(text) : null;
  } catch {
    if (res.ok) throw new ApiError(502, 'Unexpected response');
  }
  if (!res.ok) {
    const p = (data ?? {}) as { message?: string; errors?: Record<string, string[]> };
    throw new ApiError(res.status, p.message ?? `Request failed (${res.status})`, p.errors ?? {});
  }
  return data as T;
}

export const deviceName = () => `SimuWork ${Platform.OS === 'ios' ? 'iOS' : Platform.OS === 'android' ? 'Android' : 'Web'} app`;
