import AsyncStorage from '@react-native-async-storage/async-storage';
import * as SecureStore from 'expo-secure-store';
import { Platform } from 'react-native';

/**
 * Secrets (the sign-in token) live in the device keystore (Android Keystore / iOS Keychain).
 * Preferences (academy, language, theme, data saver) live in AsyncStorage.
 * The web preview has no keystore, so it keeps the token in memory only.
 */
const memory = new Map<string, string>();

export const secure = {
  async get(key: string): Promise<string | null> {
    if (Platform.OS === 'web') return memory.get(key) ?? null;
    return SecureStore.getItemAsync(key);
  },
  async set(key: string, value: string): Promise<void> {
    if (Platform.OS === 'web') return void memory.set(key, value);
    await SecureStore.setItemAsync(key, value, { keychainAccessible: SecureStore.AFTER_FIRST_UNLOCK_THIS_DEVICE_ONLY });
  },
  async remove(key: string): Promise<void> {
    if (Platform.OS === 'web') return void memory.delete(key);
    await SecureStore.deleteItemAsync(key);
  },
};

export const prefs = {
  async get<T>(key: string): Promise<T | null> {
    try {
      const raw = await AsyncStorage.getItem('sw.' + key);
      return raw ? (JSON.parse(raw) as T) : null;
    } catch {
      return null;
    }
  },
  async set(key: string, value: unknown): Promise<void> {
    await AsyncStorage.setItem('sw.' + key, JSON.stringify(value));
  },
  async remove(key: string): Promise<void> {
    await AsyncStorage.removeItem('sw.' + key);
  },
};

export const TOKEN_KEY = 'sw_token';
