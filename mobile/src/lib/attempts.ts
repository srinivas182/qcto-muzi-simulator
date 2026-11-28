import AsyncStorage from '@react-native-async-storage/async-storage';
import NetInfo from '@react-native-community/netinfo';
import { AttemptQueue, type QueuedAttempt, type SyncResponse } from '@simuwork/core';
import { api } from './api';

/**
 * The learner's attempts, saved on the phone first (AsyncStorage) and synced when there is a connection.
 * Same queue as the web app (core AttemptQueue): safe to send twice, nothing lost if the app is closed.
 */
export const attempts = new AttemptQueue(
  { get: (k) => AsyncStorage.getItem(k), set: (k, v) => AsyncStorage.setItem(k, v) },
  async (list: QueuedAttempt[]) => (await api<{ data: SyncResponse }>('/learn/attempts/sync', { method: 'POST', body: { attempts: list } })).data,
);

let flushing: Promise<{ progress: Record<string, number> }> | null = null;

export async function syncAttempts(): Promise<Record<string, number>> {
  const net = await NetInfo.fetch().catch(() => null);
  if (net && net.isConnected === false) return {};
  flushing ??= attempts.flush().finally(() => { flushing = null; });
  return flushing.then((r) => r.progress, () => ({}));
}

// Sync as soon as the phone is back online.
NetInfo.addEventListener((s) => { if (s.isConnected) void syncAttempts(); });
