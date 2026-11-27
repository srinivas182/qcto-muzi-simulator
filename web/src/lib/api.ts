/** Thin fetch wrapper for the versioned API. All calls go through /api/v1. */
export const API_BASE = (import.meta.env.VITE_API_URL ?? '') + '/api/v1';

export interface HealthResponse {
  status: 'ok' | 'degraded';
  app: string;
  version: string;
  checks: Record<string, 'ok' | 'fail'>;
}

export async function getJson<T>(path: string, init?: RequestInit): Promise<T> {
  const res = await fetch(API_BASE + path, {
    ...init,
    headers: { Accept: 'application/json', ...(init?.headers ?? {}) },
  });
  if (!res.ok) throw new Error(`API ${res.status}`);
  return (await res.json()) as T;
}
