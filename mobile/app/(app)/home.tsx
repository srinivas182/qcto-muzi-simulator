import { useState } from 'react';
import { Linking, StyleSheet, View } from 'react-native';
import { LinearGradient } from 'expo-linear-gradient';
import Constants from 'expo-constants';
import { Box, Download, Monitor, PlayCircle, Sparkles, Wifi } from '../../src/ui/icons';
import { useQueryClient } from '@tanstack/react-query';
import { useAcademy } from '../../src/lib/academy';
import { useAuth } from '../../src/lib/auth';
import { useI18n } from '../../src/lib/i18n';
import { isLearner } from '../../src/lib/roles';
import { greetingKey } from '../../src/lib/time';
import { Button, Card, radius, Screen, space, T, useTheme } from '../../src/ui';

export default function Home() {
  const { session } = useAuth();
  const { academy } = useAcademy();
  const { t } = useI18n();
  const { c } = useTheme();
  const qc = useQueryClient();
  const [refreshing, setRefreshing] = useState(false);
  const [hour] = useState(() => new Date().getHours());
  const first = session?.user.name.split(' ')[0] ?? '';
  const name = academy?.branding.display_name ?? '';

  const refresh = async () => {
    setRefreshing(true);
    await qc.invalidateQueries({ queryKey: ['session'] });
    setRefreshing(false);
  };

  return (
    <Screen
      refreshing={refreshing}
      onRefresh={() => void refresh()}
      header={
        <LinearGradient colors={[c.nav, c.brand]} start={{ x: 0, y: 0 }} end={{ x: 1.2, y: 1 }} style={styles.hero}>
          <View style={styles.heroInner}>
            <T variant="overline" style={{ color: c.accent }}>{name.toUpperCase()}</T>
            <T variant="display" tone="inverse" style={{ marginTop: 6 }} accessibilityRole="header">{t(greetingKey(hour), { name: first })}</T>
            <T tone="inverse" style={{ opacity: 0.8, marginTop: 4 }}>{session && isLearner(session) ? t('home.learnLead') : t('home.lead')}</T>
          </View>
        </LinearGradient>
      }
    >
      {!session || isLearner(session) ? <LearnerHome /> : <StaffHome />}
    </Screen>
  );
}

function LearnerHome() {
  const { t } = useI18n();
  const { c } = useTheme();
  return (
    <>
      <Card style={{ padding: 0, overflow: 'hidden' }}>
        <View style={[styles.art, { backgroundColor: c.brandSoft }]} accessibilityElementsHidden importantForAccessibility="no-hide-descendants">
          {[Box, PlayCircle, Sparkles].map((Icon, i) => (
            <View key={i} style={[styles.tile, { backgroundColor: c.surface, transform: [{ translateY: i === 1 ? -8 : 6 }, { rotate: `${(i - 1) * 6}deg` }] }]}>
              <Icon size={26} color={c.brand} />
            </View>
          ))}
        </View>
        <View style={{ padding: space.lg }}>
          <T variant="headline">{t('nav.learn.simulate')}</T>
          <T tone="muted" style={{ marginTop: 4 }}>{t('home.learnEmpty')}</T>
        </View>
      </Card>
      <View style={[styles.tip, { borderColor: c.line }]}>
        <Download size={18} color={c.muted} />
        <T variant="caption" tone="muted" style={{ flex: 1 }}>{t('home.learnTip')}</T>
      </View>
    </>
  );
}

function StaffHome() {
  const { t } = useI18n();
  const { c } = useTheme();
  const { academy } = useAcademy();
  const domain = (Constants.expoConfig?.extra?.webPortalDomain as string | undefined) ?? 'simuwork.co.za';
  const host = `${academy?.slug ?? ''}.${domain}`;
  return (
    <Card>
      <View style={[styles.staffIcon, { backgroundColor: c.brandSoft }]}><Monitor size={26} color={c.brand} /></View>
      <T variant="headline">{t('m.staff.title')}</T>
      <T tone="muted" style={{ marginTop: 6, marginBottom: space.lg }}>{t('m.staff.body', { host })}</T>
      <Button variant="secondary" title={t('m.staff.open')} icon={<Wifi size={18} color={c.text} />} onPress={() => void Linking.openURL(`https://${host}`)} />
    </Card>
  );
}

const styles = StyleSheet.create({
  heroInner: { width: '100%', maxWidth: 600, alignSelf: 'center' },
  hero: { paddingHorizontal: space.xl, paddingTop: space.xxxl + 16, paddingBottom: space.xxxl, borderBottomLeftRadius: radius.xl, borderBottomRightRadius: radius.xl, marginBottom: space.sm },
  art: { height: 140, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 16 },
  tile: { width: 60, height: 60, borderRadius: 18, alignItems: 'center', justifyContent: 'center' },
  tip: { flexDirection: 'row', alignItems: 'center', gap: space.sm, marginTop: space.lg, padding: space.md, borderRadius: radius.md, borderWidth: 1, borderStyle: 'dashed' },
  staffIcon: { width: 52, height: 52, borderRadius: 16, alignItems: 'center', justifyContent: 'center', marginBottom: space.md },
});
