import { api, ApiError } from './api';

/**
 * Decisions signed off with no signal (Sprint 5.2b), for observations on a workplace floor. Each keeps its
 * client id, so sending it again never records it twice. Sent when the browser is back online.
 */
export interface Pending { url: string; body: Record<string, unknown>; learner: string; task: string }

const KEY = 'sw.decision.outbox';

export function pending(): Pending[] {
  try { return JSON.parse(localStorage.getItem(KEY) ?? '[]') as Pending[]; } catch { return []; }
}

function keep(list: Pending[]) {
  try { localStorage.setItem(KEY, JSON.stringify(list)); } catch { /* storage full */ }
}

export function enqueue(p: Pending) {
  keep([...pending().filter((x) => x.body.client_id !== p.body.client_id), p]);
}

/** A failed request that never reached the server (no signal), as opposed to a refusal. */
export const isOffline = (e: unknown) => !(e instanceof ApiError) || e.status === 0;

let running: Promise<{ sent: number; refused: Pending[] }> | null = null;

/** Send what is waiting. Refused ones (e.g. the learner was already judged) are dropped and returned. */
export function flush(): Promise<{ sent: number; refused: Pending[] }> {
  running ??= (async () => {
    let sent = 0;
    const refused: Pending[] = [];
    for (const p of pending()) {
      try {
        await api(p.url, { method: 'POST', body: p.body });
        sent++;
      } catch (e) {
        if (isOffline(e)) break; // still offline: try again later
        refused.push(p);
      }
      keep(pending().filter((x) => x.body.client_id !== p.body.client_id));
    }
    return { sent, refused };
  })().finally(() => { running = null; });
  return running;
}

if (typeof window !== 'undefined') window.addEventListener('online', () => void flush());
