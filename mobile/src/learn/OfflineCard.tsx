import { useState } from 'react';
import { Alert, StyleSheet, View } from 'react-native';
import NetInfo from '@react-native-community/netinfo';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { downloadPack, packInfo, removePack } from '../lib/packs';
import { useI18n } from '../lib/i18n';
import { Download, WifiOff } from '../ui/icons';
import { Button, Card, haptics, radius, space, T, useTheme } from '../ui';

const mb = (bytes: number) => `${Math.max(0.1, bytes / 1_048_576).toFixed(1)} MB`;

/** Download a programme for offline use (best on Wi-Fi), check for updates, or remove it. */
export function OfflineCard({ eid }: { eid: string }) {
  const { t, locale } = useI18n();
  const { c } = useTheme();
  const qc = useQueryClient();
  const info = useQuery({ queryKey: ['pack-info', eid], queryFn: () => packInfo(eid) });
  const [progress, setProgress] = useState<number | null>(null);
  const p = info.data;

  const run = async () => {
    setProgress(0);
    try {
      const r = await downloadPack(eid, locale, setProgress);
      haptics.success();
      Alert.alert(t(r === 'saved' ? 'off.saved' : 'off.upToDate'));
    } catch {
      haptics.warn();
      Alert.alert(t('off.failed'));
    } finally {
      setProgress(null);
      void qc.invalidateQueries({ queryKey: ['pack-info', eid] });
    }
  };
  const start = async () => {
    const net = await NetInfo.fetch().catch(() => null);
    // On mobile data, say roughly how much it will use before starting.
    if (net?.type === 'cellular') {
      Alert.alert(t('off.dataTitle'), t('off.dataBody', { size: p ? mb(p.bytes) : t('off.sizeUnknown') }), [
        { text: t('common.cancel'), style: 'cancel' },
        { text: t('off.downloadAnyway'), onPress: () => void run() },
      ]);
    } else void run();
  };

  return (
    <Card style={{ gap: space.sm, marginTop: space.lg }}>
      <View style={styles.row}>
        <View style={[styles.icon, { backgroundColor: c.brandSoft }]}>{p ? <WifiOff size={20} color={c.brand} /> : <Download size={20} color={c.brand} />}</View>
        <View style={{ flex: 1 }}>
          <T style={{ fontWeight: '700' }}>{t(p ? 'off.ready' : 'off.title')}</T>
          <T variant="caption" tone="muted">{p ? t('off.readyBody', { size: mb(p.bytes), date: new Date(p.saved_at).toLocaleDateString(locale) }) : t('off.body')}</T>
        </View>
      </View>
      {progress !== null ? (
        <View accessibilityRole="progressbar" accessibilityValue={{ min: 0, max: 100, now: Math.round(progress * 100) }} style={[styles.bar, { backgroundColor: c.line }]}>
          <View style={{ width: `${Math.round(progress * 100)}%`, height: '100%', backgroundColor: c.brand }} />
        </View>
      ) : (
        <View style={{ flexDirection: 'row', gap: space.sm }}>
          <View style={{ flex: 1 }}><Button variant={p ? 'secondary' : 'primary'} title={t(p ? 'off.update' : 'off.download')} onPress={() => void start()} /></View>
          {p ? <View style={{ flex: 1 }}><Button variant="ghost" title={t('off.remove')} onPress={() => void removePack(eid).then(() => qc.invalidateQueries({ queryKey: ['pack-info', eid] }))} /></View> : null}
        </View>
      )}
    </Card>
  );
}

const styles = StyleSheet.create({
  row: { flexDirection: 'row', alignItems: 'center', gap: space.md },
  icon: { width: 40, height: 40, borderRadius: radius.md, alignItems: 'center', justifyContent: 'center' },
  bar: { height: 8, borderRadius: 4, overflow: 'hidden' },
});
