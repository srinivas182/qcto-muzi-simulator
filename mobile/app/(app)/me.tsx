import { useState } from 'react';
import { Pressable, StyleSheet, Switch, View } from 'react-native';
import { router } from 'expo-router';
import * as Application from 'expo-application';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { BellRing, Check, Languages, LogOut, Moon, WifiOff } from '../../src/ui/icons';
import { LOCALES, type LearnHomeData, type Locale } from '@simuwork/core';
import { api } from '../../src/lib/api';
import { useAcademy } from '../../src/lib/academy';
import { useAuth } from '../../src/lib/auth';
import { useI18n } from '../../src/lib/i18n';
import { useSettings, type Appearance } from '../../src/lib/settings';
import { AcademyMark, Button, Card, haptics, Row, Screen, SectionLabel, Sheet, space, T, useTheme } from '../../src/ui';

export default function Me() {
  const { session, signOut } = useAuth();
  const { academy, forget } = useAcademy();
  const { t, locale, setLocale } = useI18n();
  const { appearance, setAppearance, dataSaver, setDataSaver } = useSettings();
  const { c } = useTheme();
  const [sheet, setSheet] = useState<'language' | 'appearance' | 'signout' | null>(null);
  const user = session?.user;
  const appearanceLabel = { system: t('common.system'), light: t('common.light'), dark: t('common.dark') };
  const icon = (I: typeof Languages) => <I size={18} color={c.brand} />;

  const leave = async (alsoForgetAcademy: boolean) => {
    setSheet(null);
    await signOut();
    if (alsoForgetAcademy) await forget();
    router.replace(alsoForgetAcademy ? '/academy' : '/sign-in');
  };

  return (
    <Screen title={t('m.tabs.me')}>
      <Card style={styles.profile}>
        <View style={[styles.avatar, { backgroundColor: c.brand }]}>
          <T style={styles.initials}>{(user?.name ?? '?').split(' ').map((p) => p[0]).slice(0, 2).join('')}</T>
        </View>
        <View style={{ flex: 1 }}>
          <T variant="headline" numberOfLines={1}>{user?.name}</T>
          <T variant="caption" tone="muted" numberOfLines={1}>{user?.phone ?? user?.email}</T>
        </View>
      </Card>

      <SectionLabel>{t('m.me.academy')}</SectionLabel>
      <Card style={{ paddingVertical: 0 }}>
        <Row icon={<AcademyMark name={academy?.branding.display_name ?? ''} logo={academy?.branding.logo_url} size={36} />} label={academy?.branding.display_name ?? ''} detail={t('m.academy.change')} onPress={() => void leave(true)} last />
      </Card>

      <SectionLabel>{t('m.me.appearance')}</SectionLabel>
      <Card style={{ paddingVertical: 0 }}>
        <Row icon={icon(Languages)} label={t('common.language')} detail={LOCALES[locale]} onPress={() => setSheet('language')} />
        <Row icon={icon(Moon)} label={t('common.theme')} detail={appearanceLabel[appearance]} onPress={() => setSheet('appearance')} />
        <Row
          icon={icon(WifiOff)}
          label={t('m.me.dataSaver')}
          detail={t('m.me.dataSaverBody')}
          right={<Switch value={dataSaver} onValueChange={(v) => { haptics.select(); setDataSaver(v); }} trackColor={{ true: c.brand, false: c.line }} thumbColor="#FFFFFF" ios_backgroundColor={c.line} accessibilityLabel={t('m.me.dataSaver')} />}
          last
        />
      </Card>
      <Reminders />

      <View style={{ marginTop: space.xxl }}>
        <Button variant="secondary" title={t('common.signOut')} icon={<LogOut size={18} color={c.danger} />} onPress={() => setSheet('signout')} />
      </View>
      <T variant="caption" tone="muted" style={{ textAlign: 'center', marginTop: space.lg }}>
        {t('m.me.version', { version: Application.nativeApplicationVersion ?? '1.0.0' })}
      </T>

      <Sheet open={sheet === 'language'} onClose={() => setSheet(null)} title={t('common.language')}>
        {(Object.keys(LOCALES) as Locale[]).map((l) => (
          <Choice key={l} label={LOCALES[l]} selected={l === locale} onPress={() => { setLocale(l); setSheet(null); }} />
        ))}
      </Sheet>
      <Sheet open={sheet === 'appearance'} onClose={() => setSheet(null)} title={t('common.theme')}>
        {(['system', 'light', 'dark'] as Appearance[]).map((a) => (
          <Choice key={a} label={appearanceLabel[a]} selected={a === appearance} onPress={() => { setAppearance(a); setSheet(null); }} />
        ))}
      </Sheet>
      <Sheet open={sheet === 'signout'} onClose={() => setSheet(null)} title={t('m.me.signOutConfirm', { academy: academy?.branding.display_name ?? '' })}>
        <View style={{ gap: space.sm }}>
          <Button variant="danger" title={t('common.signOut')} onPress={() => void leave(false)} />
          <Button variant="ghost" title={t('common.cancel')} onPress={() => setSheet(null)} />
        </View>
      </Sheet>
    </Screen>
  );
}

function Choice({ label, selected, onPress }: { label: string; selected: boolean; onPress: () => void }) {
  const { c } = useTheme();
  return (
    <Pressable
      accessibilityRole="radio"
      accessibilityState={{ checked: selected }}
      onPress={() => { haptics.select(); onPress(); }}
      style={[styles.choice, { borderBottomColor: c.line }]}
    >
      <T variant="label" style={{ flex: 1, color: selected ? c.brand : c.text }}>{label}</T>
      {selected ? <Check size={20} color={c.brand} /> : null}
    </Pressable>
  );
}

const styles = StyleSheet.create({
  profile: { flexDirection: 'row', alignItems: 'center', gap: space.lg },
  avatar: { width: 56, height: 56, borderRadius: 28, alignItems: 'center', justifyContent: 'center' },
  initials: { color: '#fff', fontWeight: '800', fontSize: 20 },
  choice: { flexDirection: 'row', alignItems: 'center', minHeight: 56, borderBottomWidth: StyleSheet.hairlineWidth },
});

/** Gentle reminders after a quiet week (learners only): on by default, can be turned off. */
function Reminders() {
  const { t } = useI18n();
  const { c } = useTheme();
  const qc = useQueryClient();
  const home = useQuery({ queryKey: ['learn-home'], queryFn: () => api<{ data: LearnHomeData }>('/learn/home'), retry: false });
  const set = useMutation({ mutationFn: (on: boolean) => api('/learn/me/reminders', { method: 'PATCH', body: { on } }), onSuccess: () => void qc.invalidateQueries({ queryKey: ['learn-home'] }) });
  const learner = home.data?.data.learner;
  if (!learner) return null;
  const on = set.isPending ? !!set.variables : learner.reminders ?? true;
  return (
    <Card style={{ paddingVertical: 0, marginTop: space.md }}>
      <Row icon={<BellRing size={20} color={c.brand} />} label={t('jr.reminders')} detail={t('jr.remindersLead')}
        right={<Switch value={on} onValueChange={(v) => { haptics.select(); set.mutate(v); }} trackColor={{ true: c.brand, false: c.line }} thumbColor="#FFFFFF" ios_backgroundColor={c.line} accessibilityLabel={t('jr.reminders')} />} last />
    </Card>
  );
}
