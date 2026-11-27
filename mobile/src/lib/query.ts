import AsyncStorage from '@react-native-async-storage/async-storage';
import { QueryClient } from '@tanstack/react-query';
import { createAsyncStoragePersister } from '@tanstack/query-async-storage-persister';

/** Server data is cached on the device so the app opens instantly, and offline, with the last known state. */
export function createQueryClient() {
  return new QueryClient({ defaultOptions: { queries: { staleTime: 60_000, gcTime: 7 * 24 * 3600_000, networkMode: 'offlineFirst' } } });
}

export const persister = createAsyncStoragePersister({ storage: AsyncStorage, key: 'sw.cache', throttleTime: 1000 });
export const PERSIST_MAX_AGE = 7 * 24 * 3600_000;
