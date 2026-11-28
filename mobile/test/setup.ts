import mockAsyncStorage from '@react-native-async-storage/async-storage/jest/async-storage-mock';
import mockNetInfo from '@react-native-community/netinfo/jest/netinfo-mock.js';

jest.mock('@react-native-async-storage/async-storage', () => mockAsyncStorage);
jest.mock('@react-native-community/netinfo', () => mockNetInfo);

const mockSecureStore = new Map<string, string>();
jest.mock('expo-secure-store', () => ({
  AFTER_FIRST_UNLOCK_THIS_DEVICE_ONLY: 0,
  getItemAsync: jest.fn(async (k: string) => mockSecureStore.get(k) ?? null),
  setItemAsync: jest.fn(async (k: string, v: string) => void mockSecureStore.set(k, v)),
  deleteItemAsync: jest.fn(async (k: string) => void mockSecureStore.delete(k)),
}));
jest.mock('expo-localization', () => ({ getLocales: () => [{ languageTag: 'en-ZA' }] }));
jest.mock('expo-haptics', () => ({
  impactAsync: jest.fn(async () => {}), selectionAsync: jest.fn(async () => {}), notificationAsync: jest.fn(async () => {}),
  ImpactFeedbackStyle: { Light: 'light' }, NotificationFeedbackType: { Success: 'success', Error: 'error' },
}));
jest.mock('expo-camera', () => ({ CameraView: () => null, useCameraPermissions: () => [{ granted: false }, jest.fn()] }));
jest.mock('expo-audio', () => ({ useAudioPlayer: () => ({ play: jest.fn(), pause: jest.fn() }), useAudioPlayerStatus: () => ({ playing: false }) }));
jest.mock('expo-speech', () => ({ speak: jest.fn(), stop: jest.fn() }));
// expo-file-system: an in-memory disk, so offline packs can be tested.
jest.mock('expo-file-system', () => {
  const disk = new Map<string, string>();
  const join = (parent: { uri: string } | string, name?: string) => `${typeof parent === 'string' ? parent : parent.uri}${name ? `/${name}` : ''}`;
  class Directory { uri: string; constructor(p: { uri: string } | string, n?: string) { this.uri = join(p, n); } create() {} }
  class File {
    uri: string;
    constructor(p: { uri: string } | string, n?: string) { this.uri = join(p, n); }
    get exists() { return disk.has(this.uri); }
    async write(text: string) { disk.set(this.uri, text); }
    async text() { return disk.get(this.uri) ?? ''; }
    delete() { disk.delete(this.uri); }
    static downloadFileAsync = jest.fn(async (url: string, to: File) => { disk.set(to.uri, `downloaded:${url}`); return to; });
  }
  return { Directory, File, Paths: { document: { uri: 'file:///docs' }, cache: { uri: 'file:///cache' } }, __disk: disk };
});
jest.mock('react-native-webview', () => {
  const { createElement } = jest.requireActual<typeof import('react')>('react');
  const { View } = jest.requireActual<typeof import('react-native')>('react-native');
  return { WebView: (props: object) => createElement(View, { testID: 'webview', ...props }) };
});
jest.mock('expo-linear-gradient', () => {
  const { View } = jest.requireActual('react-native');
  return { LinearGradient: View };
});

beforeEach(() => {
  mockSecureStore.clear();
  // Empty the storage object directly: mock functions may be reset between tests.
  const store = (mockAsyncStorage as unknown as { __INTERNAL_MOCK_STORAGE__: Record<string, string> }).__INTERNAL_MOCK_STORAGE__;
  for (const key of Object.keys(store)) delete store[key];
});



beforeEach(() => {
  // eslint-disable-next-line @typescript-eslint/no-require-imports
  require('../src/lib/api').resetRequestContext();
});
