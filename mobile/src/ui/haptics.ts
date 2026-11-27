import * as Haptics from 'expo-haptics';
import { Platform } from 'react-native';

/** Small vibrations that confirm a touch. Silent where unsupported. */
export const tap = () => Platform.OS !== 'web' && void Haptics.impactAsync(Haptics.ImpactFeedbackStyle.Light).catch(() => {});
export const select = () => Platform.OS !== 'web' && void Haptics.selectionAsync().catch(() => {});
export const success = () => Platform.OS !== 'web' && void Haptics.notificationAsync(Haptics.NotificationFeedbackType.Success).catch(() => {});
export const warn = () => Platform.OS !== 'web' && void Haptics.notificationAsync(Haptics.NotificationFeedbackType.Error).catch(() => {});
