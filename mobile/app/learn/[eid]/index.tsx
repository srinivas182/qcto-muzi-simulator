import { Pressable, StyleSheet, View } from 'react-native';
import { router, useLocalSearchParams } from 'expo-router';
import { useQuery } from '@tanstack/react-query';
import type { LearnActivity } from '@simuwork/core';
import { api } from '../../../src/lib/api';
import { useI18n } from '../../../src/lib/i18n';
import { Box, Check, ChevronRight, ListChecks, PlayCircle } from '../../../src/ui/icons';
import { Card, radius, Screen, space, T, useTheme } from '../../../src/ui';

/** Learner: everything in my programme, in order, with what I have done. */
export default function ProgrammeScreen() {
  const { eid } = useLocalSearchParams<{ eid: string }>();
  const { t } = useI18n();
  const { c } = useTheme();
  const data = useQuery({ queryKey: ['learn-activities', eid], queryFn: () => api<{ data: { progress: number; activities: LearnActivity[] } }>(`/learn/enrolments/${eid}/activities`) });
  const d = data.data?.data;
  return (
    <Screen title={t('lp.title')} refreshing={data.isRefetching} onRefresh={() => void data.refetch()}>
      {d && (
        <>
          <View style={{ flexDirection: 'row', justifyContent: 'space-between' }}><T style={{ fontWeight: '700' }}>{t('lh.progress')}</T><T tone="muted">{`${d.progress}%`}</T></View>
          <View style={[styles.bar, { backgroundColor: c.line }]}><View style={{ width: `${d.progress}%`, height: '100%', backgroundColor: c.brand }} /></View>
          <Pressable accessibilityRole="link" onPress={() => router.push(`/learn/${eid}/checklist`)} style={[styles.row, { marginTop: space.lg, borderRadius: radius.lg, borderWidth: 1, borderColor: c.line, backgroundColor: c.surface }]}>
            <ListChecks size={22} color={c.brand} />
            <View style={{ flex: 1 }}><T style={{ fontWeight: '700' }}>{t('jr.title')}</T><T variant="caption" tone="muted">{t('jr.cardLead')}</T></View>
            <ChevronRight size={18} color={c.muted} />
          </Pressable>
          <Card style={{ padding: 0, marginTop: space.lg }}>
            {d.activities.map((a, i) => (
              <Pressable key={a.id} accessibilityRole="button" onPress={() => router.push(`/learn/${eid}/${a.kind === 'lesson' ? 'lesson' : 'simulator'}/${a.id}`)}
                style={[styles.row, i > 0 && { borderTopWidth: StyleSheet.hairlineWidth, borderTopColor: c.line }]}>
                <View style={[styles.icon, { backgroundColor: a.status === 'done' ? c.success : a.kind === 'lesson' ? c.brandSoft : c.nav }]}>
                  {a.status === 'done' ? <Check size={18} color="#FFFFFF" /> : a.kind === 'lesson' ? <PlayCircle size={18} color={c.brand} /> : <Box size={18} color="#FFFFFF" />}
                </View>
                <View style={{ flex: 1 }}>
                  <T style={{ fontWeight: '700' }}>{`${i + 1}. ${a.title}`}</T>
                  <T variant="caption" tone="muted">{`${t(a.kind === 'lesson' ? 'lp.lesson' : 'lp.simulator')} · ${t('lp.minutes', { n: a.minutes })} · ${t(`lp.status.${a.status}`)}`}</T>
                </View>
                <ChevronRight size={18} color={c.muted} />
              </Pressable>
            ))}
          </Card>
        </>
      )}
    </Screen>
  );
}

const styles = StyleSheet.create({
  bar: { height: 10, borderRadius: 5, overflow: 'hidden', marginTop: 6 },
  row: { flexDirection: 'row', alignItems: 'center', gap: space.md, padding: space.lg, minHeight: 64 },
  icon: { width: 36, height: 36, borderRadius: radius.md, alignItems: 'center', justifyContent: 'center' },
});
