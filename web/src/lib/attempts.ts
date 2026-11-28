import { AttemptQueue, type QueuedAttempt, type SyncResponse } from '@simuwork/core';
import { api } from './api';

/**
 * The learner's attempts, saved in this browser first and synced when online (see core AttemptQueue).
 * Syncs after each finished activity, when the connection comes back, and when the tab is hidden.
 */
const storage = {
  get: async (k: string) => { try { return localStorage.getItem(k); } catch { return null; } },
  set: async (k: string, v: string) => { try { localStorage.setItem(k, v); } catch { /* storage full or blocked: keep going */ } },
};

export const attempts = new AttemptQueue(storage, async (list: QueuedAttempt[]) => (await api<{ data: SyncResponse }>('/learn/attempts/sync', { method: 'POST', body: { attempts: list } })).data);

let flushing: Promise<unknown> | null = null;

/** Try to sync now; quietly keeps everything if offline. Resolves to the new progress per enrolment, if any. */
export function syncAttempts(): Promise<Record<string, number>> {
  if (typeof navigator !== 'undefined' && navigator.onLine === false) return Promise.resolve({});
  const run = (flushing ??= attempts.flush().finally(() => { flushing = null; })) as Promise<{ progress: Record<string, number> }>;
  return run.then((r) => r.progress, () => ({}));
}

if (typeof window !== 'undefined') {
  window.addEventListener('online', () => void syncAttempts());
  document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'hidden') void syncAttempts(); });
}

const SAVER = 'sw.dataSaver';
/** Data saver is on unless the learner turns it off: narration stays text and video becomes text. */
export const dataSaver = {
  get: (): boolean => { try { return localStorage.getItem(SAVER) !== 'off'; } catch { return true; } },
  set: (on: boolean) => { try { localStorage.setItem(SAVER, on ? 'on' : 'off'); } catch { /* ignore */ } },
};
