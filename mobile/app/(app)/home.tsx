import { useState, type ReactNode } from 'react';
import { Linking, Pressable, StyleSheet, View } from 'react-native';
import { LinearGradient } from 'expo-linear-gradient';
import Constants from 'expo-constants';
import { router } from 'expo-router';
import { Box, CalendarClock, ClipboardList, Download, MapPin, Monitor, PlayCircle, QrCode, Radio, Sparkles, Users, Wifi } from '../../src/ui/icons';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { LearnEnrolment, LearnHomeData } from '@simuwork/core';
import { api, ApiError } from '../../src/lib/api';
import { useAcademy } from '../../src/lib/academy';
import { useAuth } from '../../src/lib/auth';
import { useI18n } from '../../src/lib/i18n';
import { isLearner } from '../../src/lib/roles';
import { greetingKey } from '../../src/lib/time';
import { Button, Card, Field, haptics, radius, Screen, space, T, useTheme } from '../../src/ui';

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
    await Promise.all([qc.invalidateQueries({ queryKey: ['session'] }), qc.invalidateQueries({ queryKey: ['learn-home'] })]);
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
  const home = useQuery({ queryKey: ['learn-home'], queryFn: () => api<{ data: LearnHomeData }>('/learn/home'), retry: false });
  const data = home.data?.data;
  return (
    <>
      {data?.learner && data.learner.qcto_missing > 0 && <DetailsBanner n={data.learner.qcto_missing} />}
      {data && data.enrolments.length > 0 ? data.enrolments.map((e) => <ProgrammeCard key={e.id} e={e} />) : <Welcome />}
      <JoinByCode />
    </>
  );
}

function ProgrammeCard({ e }: { e: LearnEnrolment }) {
  const { t, locale } = useI18n();
  const { c } = useTheme();
  const qc = useQueryClient();
  const start = useMutation({
    mutationFn: () => api(`/learn/enrolments/${e.id}/start`, { method: 'POST' }),
    onSuccess: () => { haptics.success(); void qc.invalidateQueries({ queryKey: ['learn-home'] }); },
  });
  const date = (d: string) => new Date(d).toLocaleDateString(locale, { day: 'numeric', month: 'long' });
  const notYet = new Date(e.starts_on) > new Date();
  return (
    <Card style={{ padding: 0, overflow: 'hidden', marginBottom: space.lg }}>
      <LinearGradient colors={[c.nav, c.brand]} start={{ x: 0, y: 0 }} end={{ x: 1.3, y: 1 }} style={{ padding: space.lg }}>
        <T variant="overline" style={{ color: c.accent }}>{`${e.programme.prefix} · ${t('lh.version', { n: e.version })}`}</T>
        <T variant="title" tone="inverse" style={{ marginTop: 4 }} accessibilityRole="header">{e.programme.title}</T>
        <T tone="inverse" style={{ opacity: 0.8, marginTop: 2 }}>{e.cohort.name}</T>
      </LinearGradient>
      <View style={{ padding: space.lg, gap: space.sm }}>
        <Line icon={<MapPin size={16} color={c.muted} />} text={e.cohort.centre} />
        {e.cohort.facilitators.length > 0 && <Line icon={<Users size={16} color={c.muted} />} text={e.cohort.facilitators.join(', ')} />}
        <Line icon={<CalendarClock size={16} color={c.muted} />} text={notYet ? t('lh.startsOn', { date: date(e.starts_on) }) : e.days_left !== null ? t('lh.daysLeft', { n: e.days_left }) : t('lh.noEnd')} />
        <View style={{ marginTop: space.sm }}>
          <View style={{ flexDirection: 'row', justifyContent: 'space-between' }}><T variant="caption" style={{ fontWeight: '700' }}>{t('lh.progress')}</T><T variant="caption" tone="muted">{`${e.progress}%`}</T></View>
          <View style={[styles.bar, { backgroundColor: c.line }]} accessibilityRole="progressbar" accessibilityValue={{ min: 0, max: 100, now: e.progress }}>
            <View style={{ width: `${e.progress}%`, height: '100%', backgroundColor: c.brand, borderRadius: 4 }} />
          </View>
        </View>
        {e.class ? (
          <Pressable accessibilityRole="button" onPress={() => router.push(`/learn/${e.id}/simulator/${e.class!.activity_id}?class=${e.class!.id}&mode=${e.class!.mode}`)} style={[styles.continue, { backgroundColor: '#ECFDF3', borderWidth: 2, borderColor: '#16A34A' }]}>
            <Radio size={26} color="#14532D" />
            <View style={{ flex: 1 }}>
              <T variant="caption" style={{ color: '#14532D', fontWeight: '700' }}>{t('sim.classNow')}</T>
              <T style={{ fontWeight: '700', fontSize: 17, color: '#14532D' }} numberOfLines={1}>{e.class.title}</T>
            </View>
            <T style={{ fontWeight: '700', color: '#14532D' }}>{t('sim.joinClass')}</T>
          </Pressable>
        ) : null}
        {e.next ? (
          <Pressable accessibilityRole="button" onPress={() => router.push(`/learn/${e.id}/${e.next!.kind === 'lesson' ? 'lesson' : 'simulator'}/${e.next!.id}`)} style={[styles.continue, { backgroundColor: c.brand }]}>
            <PlayCircle size={26} color="#FFFFFF" />
            <View style={{ flex: 1 }}>
              <T variant="caption" tone="inverse" style={{ opacity: 0.8 }}>{t(e.next.started || e.progress > 0 ? 'lh.continue' : 'lh.startHere')}</T>
              <T tone="inverse" style={{ fontWeight: '700', fontSize: 17 }} numberOfLines={1}>{e.next.title}</T>
              <T variant="caption" tone="inverse" style={{ opacity: 0.8 }}>{`${t(e.next.kind === 'lesson' ? 'lp.lesson' : 'lp.simulator')} · ${t('lp.minutes', { n: e.next.minutes })}`}</T>
            </View>
          </Pressable>
        ) : e.started ? (
          <View style={[styles.soon, { backgroundColor: c.brandSoft }]}><Sparkles size={16} color={c.brand} /><T variant="caption" style={{ flex: 1 }}>{t('lh.comingSoon')}</T></View>
        ) : (
          <View style={{ marginTop: space.sm }}>
            <Button title={t('lh.start')} icon={<PlayCircle size={18} color="#FFFFFF" />} loading={start.isPending} onPress={() => start.mutate()} />
            {e.seat_returns_on && <T variant="caption" tone="muted" style={{ marginTop: 6 }}>{t('lh.startBy', { date: date(e.seat_returns_on) })}</T>}
          </View>
        )}
        <Pressable accessibilityRole="link" onPress={() => router.push(`/learn/${e.id}`)} style={{ minHeight: 44, justifyContent: 'center' }}><T tone="brand" style={{ fontWeight: '700' }}>{t('lh.seeAll')}</T></Pressable>
      </View>
    </Card>
  );
}

function Line({ icon, text }: { icon: ReactNode; text: string }) {
  return <View style={{ flexDirection: 'row', alignItems: 'center', gap: 8 }}>{icon}<T tone="muted" style={{ flex: 1 }}>{text}</T></View>;
}

/** The details form is long; on a phone it opens in the browser on the academy's site. */
function DetailsBanner({ n }: { n: number }) {
  const { t } = useI18n();
  const { c } = useTheme();
  const { academy } = useAcademy();
  const domain = (Constants.expoConfig?.extra?.webPortalDomain as string | undefined) ?? 'simuwork.co.za';
  return (
    <Pressable accessibilityRole="link" onPress={() => void Linking.openURL(`https://${academy?.slug ?? ''}.${domain}/learn/details`)} style={[styles.banner, { borderColor: c.warning, backgroundColor: c.surface }]}>
      <ClipboardList size={20} color={c.text} />
      <T style={{ flex: 1 }}><T style={{ fontWeight: '700' }}>{t('lh.detailsTitle')}</T>{` ${t('lh.detailsBody', { n })}`}</T>
    </Pressable>
  );
}

function Welcome() {
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
          <T tone="muted" style={{ marginTop: 4 }}>{t('lh.none')}</T>
        </View>
      </Card>
      <View style={[styles.tip, { borderColor: c.line }]}>
        <Download size={18} color={c.muted} />
        <T variant="caption" tone="muted" style={{ flex: 1 }}>{t('home.learnTip')}</T>
      </View>
    </>
  );
}

/** Type the code from the class poster (the camera scanner reads the same code). */
function JoinByCode() {
  const { t } = useI18n();
  const { c } = useTheme();
  const qc = useQueryClient();
  const [code, setCode] = useState('');
  const join = useMutation({
    mutationFn: () => api<{ data: { cohort: string } }>('/cohorts/join', { method: 'POST', body: { code: code.trim() } }),
    onSuccess: () => { haptics.success(); setCode(''); void qc.invalidateQueries({ queryKey: ['learn-home'] }); },
  });
  const error = join.error instanceof ApiError ? Object.values(join.error.errors ?? {})[0]?.[0] ?? join.error.message : undefined;
  return (
    <Card style={{ marginTop: space.lg }}>
      <View style={{ flexDirection: 'row', alignItems: 'center', gap: space.sm, marginBottom: space.md }}>
        <QrCode size={20} color={c.muted} />
        <View style={{ flex: 1 }}><T style={{ fontWeight: '700' }}>{t('lh.joinTitle')}</T><T variant="caption" tone="muted">{t('lh.joinBody')}</T></View>
      </View>
      {join.data ? <T tone="success">{t('join.done', { cohort: join.data.data.cohort })}</T> : (
        <>
          <Field label={t('lh.joinCode')} value={code} onChangeText={(v) => setCode(v.toUpperCase())} autoCapitalize="characters" autoCorrect={false} maxLength={12} placeholder="ABCD2345" error={error} />
          <Button style={{ marginTop: space.md }} variant="secondary" title={t('join.button')} disabled={code.trim().length < 6} loading={join.isPending} onPress={() => join.mutate()} />
        </>
      )}
    </Card>
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
  continue: { flexDirection: 'row', alignItems: 'center', gap: space.md, padding: space.lg, borderRadius: radius.lg, marginTop: space.sm, minHeight: 72 },
  bar: { height: 8, borderRadius: 4, overflow: 'hidden', marginTop: 6 },
  soon: { flexDirection: 'row', alignItems: 'center', gap: space.sm, marginTop: space.sm, padding: space.md, borderRadius: radius.md },
  banner: { flexDirection: 'row', alignItems: 'center', gap: space.sm, padding: space.md, borderRadius: radius.md, borderWidth: 1, marginBottom: space.lg },
  staffIcon: { width: 52, height: 52, borderRadius: 16, alignItems: 'center', justifyContent: 'center', marginBottom: space.md },
});
