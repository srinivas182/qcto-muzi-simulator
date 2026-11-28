/**
 * Saves every attempt on the device first, then syncs to POST /learn/attempts/sync when there is a connection.
 * Nothing is lost if the signal drops or the battery dies; sending twice is harmless because the server matches
 * attempts on client_id and never changes a finished one. Shared by the web app and the mobile app; each passes
 * its own storage (localStorage / AsyncStorage) and request function.
 */
export type AttemptMode = 'lesson' | 'practice' | 'job';

export interface QueuedAttempt {
  client_id: string;
  enrolment_id: string;
  activity_id: string;
  mode: AttemptMode;
  content_version: number;
  started_at: string;
  finished_at: string | null;
  completed: boolean;
  score: number | null;
  duration_seconds: number;
  result: Record<string, unknown>;
  device?: string;
}

export interface SyncResponse {
  accepted: string[];
  rejected: { client_id: string; reason: string }[];
  progress: Record<string, number>;
}

export interface AttemptStore {
  get(key: string): Promise<string | null>;
  set(key: string, value: string): Promise<void>;
}

const KEY = 'sw.attempts';
const BATCH = 50;

export function newClientId(): string {
  const c = (globalThis as { crypto?: { randomUUID?: () => string } }).crypto;
  if (c?.randomUUID) return c.randomUUID();
  // Fallback (old Android WebViews): RFC 4122 v4 from Math.random.
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (ch) => {
    const r = (Math.random() * 16) | 0;
    return (ch === 'x' ? r : (r & 0x3) | 0x8).toString(16);
  });
}

export class AttemptQueue {
  private chain: Promise<unknown> = Promise.resolve();
  private readonly store: AttemptStore;
  private readonly send: (attempts: QueuedAttempt[]) => Promise<SyncResponse>;
  private readonly now: () => Date;

  constructor(store: AttemptStore, send: (attempts: QueuedAttempt[]) => Promise<SyncResponse>, now: () => Date = () => new Date()) {
    this.store = store;
    this.send = send;
    this.now = now;
  }

  /** Start an attempt; returns its client_id. Saved straight away. */
  start(a: { enrolment_id: string; activity_id: string; mode: AttemptMode; content_version: number; device?: string }): Promise<string> {
    const attempt: QueuedAttempt = { ...a, client_id: newClientId(), started_at: this.now().toISOString(), finished_at: null, completed: false, score: null, duration_seconds: 0, result: {} };
    return this.locked(async () => {
      await this.write([...(await this.read()), attempt]);
      return attempt.client_id;
    });
  }

  /** Save progress inside an attempt (each answer or step), so a crash loses nothing. */
  update(clientId: string, change: Partial<Pick<QueuedAttempt, 'result' | 'duration_seconds' | 'score'>>): Promise<void> {
    return this.locked(async () => {
      await this.write((await this.read()).map((q) => (q.client_id === clientId && q.finished_at === null ? { ...q, ...change } : q)));
    });
  }

  finish(clientId: string, outcome: { completed: boolean; score?: number | null; duration_seconds: number; result?: Record<string, unknown> }): Promise<void> {
    return this.locked(async () => {
      const at = this.now().toISOString();
      await this.write((await this.read()).map((q) => (q.client_id === clientId && q.finished_at === null
        ? { ...q, finished_at: at, completed: outcome.completed, score: outcome.score ?? q.score, duration_seconds: outcome.duration_seconds, result: outcome.result ?? q.result }
        : q)));
    });
  }

  pending(): Promise<QueuedAttempt[]> {
    return this.read();
  }

  /**
   * Send what is waiting, in batches. Finished attempts the server accepted (or rejected for good) leave the
   * queue; unfinished ones stay so later changes are sent too. Throws only on network failure; try again later.
   */
  flush(): Promise<{ sent: number; rejected: SyncResponse['rejected']; progress: Record<string, number> }> {
    return this.locked(async () => {
      const queue = await this.read();
      let sent = 0;
      const rejected: SyncResponse['rejected'] = [];
      const progress: Record<string, number> = {};
      const done = new Set<string>();
      for (let i = 0; i < queue.length; i += BATCH) {
        const batch = queue.slice(i, i + BATCH);
        const res = await this.send(batch);
        sent += res.accepted.length;
        rejected.push(...res.rejected);
        Object.assign(progress, res.progress);
        const finished = new Set(batch.filter((q) => q.finished_at !== null).map((q) => q.client_id));
        for (const id of res.accepted) if (finished.has(id)) done.add(id);
        for (const r of res.rejected) done.add(r.client_id); // the server will never accept it; do not retry forever
      }
      await this.write((await this.read()).filter((q) => !done.has(q.client_id)));
      return { sent, rejected, progress };
    });
  }

  private async read(): Promise<QueuedAttempt[]> {
    try {
      const raw = await this.store.get(KEY);
      return raw ? (JSON.parse(raw) as QueuedAttempt[]) : [];
    } catch {
      return [];
    }
  }

  private write(list: QueuedAttempt[]): Promise<void> {
    return this.store.set(KEY, JSON.stringify(list));
  }

  /** One change at a time, so two quick taps never overwrite each other. */
  private locked<T>(fn: () => Promise<T>): Promise<T> {
    const run = this.chain.then(fn, fn);
    this.chain = run.catch(() => undefined);
    return run;
  }
}

/** Learner home and activity list (GET /learn/enrolments/{id}/activities). */
export interface LearnActivity {
  id: string;
  kind: 'lesson' | 'scenario';
  code: string;
  title: string;
  summary: string | null;
  minutes: number;
  required: boolean;
  content_version: number;
  outcomes: string[];
  status: 'new' | 'started' | 'done';
  best_score: number | null;
  attempts: number;
}
