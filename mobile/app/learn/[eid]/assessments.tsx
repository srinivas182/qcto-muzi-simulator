import { Pressable, StyleSheet, View } from 'react-native';
import { router, useLocalSearchParams } from 'expo-router';
import { useQuery } from '@tanstack/react-query';
import type { AssessmentComponent, LearnAssessmentItem } from '@simuwork/core';
import { api } from '../../../src/lib/api';
import { useI18n } from '../../../src/lib/i18n';
import { ChevronRight, ClipboardCheck } from '../../../src/ui/icons';
import { Card, radius, Screen, space, T, useTheme } from '../../../src/ui';

const COMPONENTS: AssessmentComponent[] = ['knowledge', 'practical', 'workplace'];

/** Learner: my summative assessments, by QCTO component. */
export default function AssessmentsScreen() {
  const { eid } = useLocalSearchParams<{ eid: string }>();
  const { t } = useI18n();
  const { c } = useTheme();
  const q = useQuery({ queryKey: ['learn-assessments', eid], queryFn: () => api<{ data: LearnAssessmentItem[] }>(`/learn/enrolments/${eid}/assessments`) });
  const items = q.data?.data ?? [];
  return (
    <Screen title={t('as.title')} subtitle={t('as.lead')} refreshing={q.isRefetching} onRefresh={() => void q.refetch()}>
      {q.data && items.length === 0 ? <T tone="muted">{t('as.none')}</T> : null}
      {COMPONENTS.map((comp) => {
        const list = items.filter((x) => x.component === comp);
        if (list.length === 0) return null;
        return (
          <View key={comp} style={{ marginBottom: space.lg }}>
            <T variant="overline" tone="muted" style={{ marginBottom: space.sm }}>{t(`as.component.${comp}`)}</T>
            <Card style={{ padding: 0 }}>
              {list.map((x, i) => {
                const left = (x.attempts_allowed ?? 0) - x.attempts_used;
                const canSit = x.kind === 'test' && x.result !== 'competent' && !x.waiting_for_marking && (x.open_sitting !== null || left > 0);
                const status = x.result === 'competent' ? t('as.competent') : x.waiting_for_marking ? t('as.waiting') : x.result === 'not_yet' ? t('as.notYet') : '';
                return (
                  <Pressable key={x.id} disabled={!canSit} accessibilityRole={canSit ? 'button' : 'text'} onPress={() => router.push(`/learn/${eid}/test/${x.id}`)}
                    style={[styles.row, i > 0 && { borderTopWidth: StyleSheet.hairlineWidth, borderTopColor: c.line }]}>
                    <View style={[styles.icon, { backgroundColor: c.brandSoft }]}><ClipboardCheck size={18} color={c.brand} /></View>
                    <View style={{ flex: 1 }}>
                      <T style={{ fontWeight: '700' }}>{x.title}</T>
                      <T variant="caption" tone="muted">{x.kind === 'test' ? t('as.testMeta', { n: x.questions ?? 0, min: x.minutes ?? 0, pass: x.pass_mark ?? 0, left: Math.max(0, left) }) : t('as.withAssessor')}</T>
                      {status ? <T variant="caption" tone="brand" style={{ fontWeight: '700' }}>{status}</T> : null}
                    </View>
                    {canSit ? <ChevronRight size={18} color={c.muted} /> : null}
                  </Pressable>
                );
              })}
            </Card>
          </View>
        );
      })}
    </Screen>
  );
}

const styles = StyleSheet.create({
  row: { flexDirection: 'row', alignItems: 'center', gap: space.md, padding: space.lg, minHeight: 64 },
  icon: { width: 36, height: 36, borderRadius: radius.md, alignItems: 'center', justifyContent: 'center' },
});
