/**
 * Fetch wrapper for the versioned API (/api/v1).
 * Web sign-in uses an HttpOnly cookie set by the API, so no token is ever readable from JavaScript.
 * Every request carries X-Requested-With (the API's CSRF check for cookie-authenticated writes).
 */
export const API_BASE = ((import.meta.env.VITE_API_URL as string | undefined) ?? '') + '/api/v1';

export class ApiError extends Error {
  readonly status: number;
  readonly errors: Record<string, string[]>;
  readonly code?: string;

  constructor(status: number, message: string, errors: Record<string, string[]> = {}, code?: string) {
    super(message);
    this.status = status;
    this.errors = errors;
    this.code = code;
  }

  /** First validation message for a field (or the general message). */
  field(name: string): string | undefined {
    return this.errors[name]?.[0];
  }
}

interface Options {
  method?: 'GET' | 'POST' | 'PATCH' | 'PUT' | 'DELETE';
  body?: unknown;
  signal?: AbortSignal;
}

export async function api<T>(path: string, { method = 'GET', body, signal }: Options = {}): Promise<T> {
  const headers: Record<string, string> = {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
    'X-Client': 'web',
  };
  const devTenant = import.meta.env.VITE_DEV_TENANT as string | undefined;
  if (devTenant) headers['X-Tenant'] = devTenant;
  if (body !== undefined) headers['Content-Type'] = 'application/json';

  const res = await fetch(API_BASE + path, {
    method,
    headers,
    credentials: 'same-origin',
    body: body === undefined ? undefined : JSON.stringify(body),
    signal,
  });

  const text = await res.text();
  let data: unknown = null;
  try {
    data = text ? JSON.parse(text) : null;
  } catch {
    // Not JSON (e.g. a proxy error page): treat as a server error below.
    if (res.ok) throw new ApiError(502, 'Unexpected response from the server');
  }

  if (!res.ok) {
    const payload = (data ?? {}) as { message?: string; errors?: Record<string, string[]>; code?: string };
    throw new ApiError(res.status, payload.message ?? `Request failed (${res.status})`, payload.errors ?? {}, payload.code);
  }

  return data as T;
}

/** Download a file (e.g. an invoice PDF) from the API with the signed-in cookie, and save it in the browser. */
export async function downloadFile(path: string, filename: string): Promise<void> {
  const headers: Record<string, string> = { 'X-Requested-With': 'XMLHttpRequest', 'X-Client': 'web' };
  const devTenant = import.meta.env.VITE_DEV_TENANT as string | undefined;
  if (devTenant) headers['X-Tenant'] = devTenant;
  const res = await fetch(API_BASE + path, { headers, credentials: 'same-origin' });
  if (!res.ok) throw new ApiError(res.status, `Download failed (${res.status})`);
  const url = URL.createObjectURL(await res.blob());
  const a = document.createElement('a');
  a.href = url;
  a.download = filename;
  a.click();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}
