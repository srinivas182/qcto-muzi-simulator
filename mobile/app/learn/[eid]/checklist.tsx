import { Share, StyleSheet, View } from 'react-native';
import { useLocalSearchParams } from 'expo-router';
import { useMutation, useQuery } from '@tanstack/react-query';
import type { ChecklistArea, ChecklistShareItem, OutcomeStatus } from '@simuwork/core';
import { api } from '../../../src/lib/api';
import { useI18n } from '../../../src/lib/i18n';
import { CircleAlert, CircleCheck, Circle, Share2 } from '../../../src/ui/icons';
import { Button, Card, radius, Screen, space, T, useTheme } from '../../../src/ui';

/** Learner: my job-ready checklist (each QCTO outcome: shown on a real job / practised / not yet). */
export default function ChecklistScreen() {
  const { eid } = useLocalSearchParams<{ eid: string }>();
  const { t } = useI18n();
  const { c } = useTheme();
  const q = useQuery({ queryKey: ['learn-checklist', eid], queryFn: () => api<{ data: { areas: ChecklistArea[] } }>(`/learn/enrolments/${eid}/checklist`) });
  const areas = q.data?.data.areas ?? [];
  const all = areas.flatMap((a) => a.outcomes);
  const shown = all.filter((o) => o.status === 'shown').length;
  // Make a read-only link (first name and skills only, 30 days) and hand it to the phone's share sheet.
  const share = useMutation({
    mutationFn: () => api<{ data: ChecklistShareItem }>(`/learn/enrolments/${eid}/shares`, { method: 'POST', body: { label: null } }),
    onSuccess: (r) => { if (r.data.url) void Share.share({ message: `${t('jr.title')}: ${r.data.url}` }); },
  });
  const icon = (s: OutcomeStatus) => (s === 'shown' ? <CircleCheck size={20} color={c.success} /> : s === 'practised' ? <CircleAlert size={20} color="#B45309" /> : <Circle size={20} color={c.muted} />);
  return (
    <Screen title={t('jr.title')} subtitle={t('jr.lead')} refreshing={q.isRefetching} onRefresh={() => void q.refetch()}>
      {q.data ? (
        <>
          <T style={{ fontWeight: '700', marginBottom: space.sm }}>{t('jr.summary', { shown, n: all.length })}</T>
          <View style={[styles.bar, { backgroundColor: c.line }]}><View style={{ width: `${(100 * shown) / Math.max(1, all.length)}%`, backgroundColor: c.success }} /></View>
          {areas.map((a) => (
            <View key={a.number} style={{ marginTop: space.lg }}>
              <T variant="overline" tone="muted" style={{ marginBottom: space.sm }}>{`${a.number}. ${a.title}`}</T>
              <Card style={{ padding: 0 }}>
                {a.outcomes.map((o, i) => (
                  <View key={o.code} accessible accessibilityLabel={`${o.plain_words || o.statement}: ${t(`jr.status.${o.status}`)}`} style={[styles.row, i > 0 && { borderTopWidth: StyleSheet.hairlineWidth, borderTopColor: c.line }]}>
                    {icon(o.status)}
                    <View style={{ flex: 1 }}><T>{o.plain_words || o.statement}</T><T variant="caption" tone="muted">{`${o.code} · ${t(`jr.status.${o.status}`)}`}</T></View>
                  </View>
                ))}
              </Card>
            </View>
          ))}
          <View style={{ marginTop: space.xl, gap: space.sm }}>
            <T variant="caption" tone="muted">{t('jr.share.lead')}</T>
            <Button variant="secondary" title={t('jr.share.title')} icon={<Share2 size={18} color={c.text} />} loading={share.isPending} onPress={() => share.mutate()} />
          </View>
        </>
      ) : null}
    </Screen>
  );
}

const styles = StyleSheet.create({
  bar: { height: 10, borderRadius: radius.sm, overflow: 'hidden', flexDirection: 'row' },
  row: { flexDirection: 'row', gap: space.md, padding: space.lg, alignItems: 'flex-start' },
});
