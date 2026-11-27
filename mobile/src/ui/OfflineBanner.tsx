import { useEffect, useRef, useState } from 'react';
import { Animated, StyleSheet } from 'react-native';
import NetInfo from '@react-native-community/netinfo';
import { WifiOff } from './icons';
import { useI18n } from '../lib/i18n';
import { T } from './Text';

/** Slides in when the phone loses its connection. The app keeps working from saved data. */
export function OfflineBanner() {
  const { t } = useI18n();
  const [offline, setOffline] = useState(false);
  const h = useRef(new Animated.Value(0)).current;

  useEffect(() => NetInfo.addEventListener((s) => setOffline(s.isConnected === false)), []);
  useEffect(() => {
    Animated.timing(h, { toValue: offline ? 1 : 0, duration: 220, useNativeDriver: false }).start();
  }, [offline, h]);

  return (
    <Animated.View
      accessibilityLiveRegion="polite"
      style={[styles.bar, { maxHeight: h.interpolate({ inputRange: [0, 1], outputRange: [0, 48] }), opacity: h }]}
    >
      <WifiOff size={16} color="#2B1D00" />
      <T variant="caption" style={{ color: '#2B1D00', fontWeight: '700' }}>{t('m.offline')}</T>
    </Animated.View>
  );
}

const styles = StyleSheet.create({
  bar: { flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 8, backgroundColor: '#E8A21B', overflow: 'hidden', paddingHorizontal: 16, height: 40 },
});
