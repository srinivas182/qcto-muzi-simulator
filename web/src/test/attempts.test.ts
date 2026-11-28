import { describe, expect, it, vi } from 'vitest';
import { AttemptQueue, type AttemptStore, type QueuedAttempt, type SyncResponse } from '@simuwork/core';

const memory = (): AttemptStore & { data: Map<string, string> } => {
  const data = new Map<string, string>();
  return { data, get: async (k) => data.get(k) ?? null, set: async (k, v) => void data.set(k, v) };
};
const base = { enrolment_id: 'e1', activity_id: 'a1', mode: 'lesson' as const, content_version: 1 };

describe('attempt queue (offline first)', () => {
  it('keeps attempts on the device until the server has them, and survives a restart', async () => {
    const store = memory();
    const offline = new AttemptQueue(store, async () => { throw new Error('offline'); });
    const id = await offline.start(base);
    await offline.update(id, { result: { block: 3 } });
    await offline.finish(id, { completed: true, score: 90, duration_seconds: 300 });
    await expect(offline.flush()).rejects.toThrow('offline');

    // App restarted, now online: the same attempt is still there and is sent once.
    const send = vi.fn(async (a: QueuedAttempt[]): Promise<SyncResponse> => ({ accepted: a.map((x) => x.client_id), rejected: [], progress: { e1: 25 } }));
    const online = new AttemptQueue(store, send);
    expect((await online.pending())[0]).toMatchObject({ client_id: id, completed: true, score: 90, result: { block: 3 } });
    expect(await online.flush()).toMatchObject({ sent: 1, progress: { e1: 25 } });
    expect(await online.pending()).toEqual([]);
  });

  it('keeps unfinished attempts after syncing them, drops ones the server rejects for good, and batches by 50', async () => {
    const store = memory();
    const send = vi.fn(async (a: QueuedAttempt[]): Promise<SyncResponse> => ({
      accepted: a.filter((x) => x.activity_id !== 'bad').map((x) => x.client_id),
      rejected: a.filter((x) => x.activity_id === 'bad').map((x) => ({ client_id: x.client_id, reason: 'activity_not_in_your_programme' })),
      progress: {},
    }));
    const q = new AttemptQueue(store, send);
    const open = await q.start(base);
    await q.start({ ...base, activity_id: 'bad' });
    for (let i = 0; i < 60; i++) await q.finish(await q.start(base), { completed: true, duration_seconds: 1 });
    const r = await q.flush();
    expect(send).toHaveBeenCalledTimes(2);
    expect(r.rejected).toHaveLength(1);
    expect((await q.pending()).map((p) => p.client_id)).toEqual([open]);
  });

  it('never loses a change when two happen at once', async () => {
    const q = new AttemptQueue(memory(), async () => ({ accepted: [], rejected: [], progress: {} }));
    const ids = await Promise.all([q.start(base), q.start(base), q.start(base)]);
    expect(new Set(ids).size).toBe(3);
    expect(await q.pending()).toHaveLength(3);
  });
});
