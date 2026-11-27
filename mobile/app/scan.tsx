import { useRef, useState } from 'react';
import { Pressable, StyleSheet, View } from 'react-native';
import { router } from 'expo-router';
import { CameraView, useCameraPermissions } from 'expo-camera';
import { Camera, X } from '../src/ui/icons';
import { useI18n } from '../src/lib/i18n';
import { academyFromQr } from '../src/lib/academy';
import { Button, EmptyState, haptics, PlatformFooter, Screen, space, T, useTheme } from '../src/ui';

/** Camera frames are processed on the device and never stored. The same scanner will power attendance check-in. */
export default function Scan() {
  const { t } = useI18n();
  const { c } = useTheme();
  const [permission, request] = useCameraPermissions();
  const [error, setError] = useState<string>();
  const handled = useRef(false);

  if (!permission?.granted) {
    return (
      <Screen title={t('m.scan.title')}>
        <EmptyState icon={<Camera size={28} color={c.brand} />} title={t('m.scan.title')} body={t('m.scan.permission')} />
        <View style={{ marginTop: space.xl, gap: space.md }}>
          <Button title={t('m.scan.allow')} onPress={() => void request()} />
          <Button variant="ghost" title={t('common.cancel')} onPress={() => router.back()} />
        </View>
      </Screen>
    );
  }

  return (
    <View style={styles.root}>
      <CameraView
        style={StyleSheet.absoluteFill}
        facing="back"
        barcodeScannerSettings={{ barcodeTypes: ['qr'] }}
        onBarcodeScanned={({ data }) => {
          if (handled.current) return;
          const code = academyFromQr(data);
          if (!code) {
            haptics.warn();
            setError(t('m.scan.invalid'));
            return;
          }
          handled.current = true;
          haptics.success();
          router.replace({ pathname: '/academy', params: { code } });
        }}
      />
      <View style={styles.overlay} pointerEvents="box-none">
        <Pressable accessibilityRole="button" accessibilityLabel={t('common.close')} onPress={() => router.back()} style={styles.close}>
          <X size={24} color="#fff" />
        </Pressable>
        <View style={[styles.frame, { borderColor: error ? c.danger : '#fff' }]} />
        <View style={styles.caption}>
          <T variant="headline" tone="inverse" style={{ textAlign: 'center' }}>{error ?? t('m.scan.body')}</T>
        </View>
        <View style={styles.footer}><PlatformFooter /></View>
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  root: { flex: 1, backgroundColor: '#000' },
  overlay: { flex: 1, alignItems: 'center', justifyContent: 'center' },
  close: { position: 'absolute', top: 56, right: 20, width: 44, height: 44, borderRadius: 22, backgroundColor: 'rgba(0,0,0,0.45)', alignItems: 'center', justifyContent: 'center' },
  frame: { width: 250, height: 250, borderWidth: 3, borderRadius: 28 },
  caption: { marginTop: 28, paddingHorizontal: 32 },
  footer: { position: 'absolute', bottom: 24, left: 0, right: 0, backgroundColor: 'rgba(255,255,255,0.9)' },
});
