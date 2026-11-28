import { useCallback, useEffect, useRef, useState } from 'react';
import { Alert, Pressable, StyleSheet, TextInput, View } from 'react-native';
import AsyncStorage from '@react-native-async-storage/async-storage';
import NetInfo from '@react-native-community/netinfo';
import { useLocalSearchParams } from 'expo-router';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import type { TestPaper, TestResult } from '@simuwork/core';
import { api } from '../../../../src/lib/api';
import { useI18n } from '../../../../src/lib/i18n';
import { Check, Clock, WifiOff } from '../../../../src/ui/icons';
import { Button, Card, radius, Screen, space, T, useTheme } from '../../../../src/ui';

type Answers = TestPaper['answers'];
const key = (sid: string) => `sw.test.${sid}`;
const now = () => Date.now();
const clock = (s: number) => `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;

/** Learner: a knowledge test on the phone. Answers are kept on the phone first and sent when there is signal. */
export default function TestScreen() {
  const { eid, tid } = useLocalSearchParams<{ eid: string; tid: string }>();
  const { t, locale } = useI18n();
  const [paper, setPaper] = useState<TestPaper | null>(null);
  const [result, setResult] = useState<TestResult | null>(null);
  const start = useMutation({
    mutationFn: async () => {
      const p = (await api<{ data: TestPaper }>(`/learn/enrolments/${eid}/tests/${tid}/start`, { method: 'POST', body: { locale, device: 'mobile' } })).data;
      const local = JSON.parse((await AsyncStorage.getItem(key(p.id))) ?? '{}') as Answers;
      return { ...p, answers: { ...p.answers, ...local } };
    },
    onSuccess: setPaper,
  });
  if (result) return <ResultView r={result} />;
  if (paper) return <Sitting key={paper.id} p={paper} onDone={setResult} />;
  return (
    <Screen title={t('as.readyTitle')}>
      <Card style={{ gap: space.sm }}>
        {(['as.rule.time', 'as.rule.offline', 'as.rule.once'] as const).map((k) => <T key={k}>{`• ${t(k)}`}</T>)}
      </Card>
      {start.isError ? <T style={{ marginTop: space.md, color: '#C92A2F' }}>{start.error instanceof Error ? start.error.message : t('common.error')}</T> : null}
      <View style={{ marginTop: space.lg }}><Button title={t('as.begin')} loading={start.isPending} onPress={() => start.mutate()} /></View>
    </Screen>
  );
}

function Sitting({ p, onDone }: { p: TestPaper; onDone: (r: TestResult) => void }) {
  const { t } = useI18n();
  const { c } = useTheme();
  const qc = useQueryClient();
  const [answers, setAnswers] = useState<Answers>(p.answers);
  const [offline, setOffline] = useState(false);
  const [left, setLeft] = useState(p.seconds_left);
  const ends = useRef(now() + p.seconds_left * 1000);
  const latest = useRef(answers);
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null);

  const send = useCallback(async (submit: boolean) => {
    try {
      const r = await api<{ data: TestPaper | TestResult }>(`/learn/sittings/${p.id}`, { method: 'PUT', body: { answers: latest.current, submit } });
      setOffline(false);
      if (r.data.status !== 'open') {
        await AsyncStorage.removeItem(key(p.id));
        void qc.invalidateQueries({ queryKey: ['learn-assessments'] });
        onDone(r.data as TestResult);
      }
    } catch {
      setOffline(true);
    }
  }, [p.id, qc, onDone]);

  const answer = (qid: string, v: number | number[] | string) => {
    const next = { ...latest.current, [qid]: v };
    latest.current = next;
    setAnswers(next);
    void AsyncStorage.setItem(key(p.id), JSON.stringify(next));
    if (timer.current) clearTimeout(timer.current);
    timer.current = setTimeout(() => void send(false), 1500);
  };

  useEffect(() => {
    const tick = setInterval(() => {
      const s = Math.max(0, Math.round((ends.current - now()) / 1000));
      setLeft(s);
      if (s === 0) { clearInterval(tick); void send(true); }
    }, 1000);
    const off = NetInfo.addEventListener((st) => { if (st.isConnected) void send(false); });
    return () => { clearInterval(tick); off(); };
  }, [send]);

  const done = p.questions.filter((q) => { const a = answers[q.id]; return a !== undefined && a !== '' && !(Array.isArray(a) && a.length === 0); }).length;
  return (
    <Screen title={p.task.title} subtitle={t('as.answered', { done, n: p.questions.length })}
      bottom={<Button title={t('as.handIn')} onPress={() => Alert.alert(t('as.handIn'), t('as.handInConfirm', { done, n: p.questions.length }), [{ text: t('common.cancel'), style: 'cancel' }, { text: t('as.handIn'), onPress: () => void send(true) }])} />}>
      <View style={[styles.row, { marginBottom: space.md }]}><Clock size={16} color={left < 120 ? c.danger : c.text} /><T style={{ fontWeight: '700', color: left < 120 ? c.danger : c.text }} accessibilityLiveRegion="polite">{clock(left)}</T></View>
      {offline ? <View style={[styles.row, styles.note]}><WifiOff size={16} color="#7A4B00" /><T style={{ flex: 1, color: '#7A4B00' }}>{t('as.offline')}</T></View> : null}
      {p.questions.map((q, i) => (
        <Card key={q.id} style={{ marginBottom: space.md, gap: space.sm }}>
          <T style={{ fontWeight: '700', fontSize: 16 }}>{`${i + 1}. ${q.prompt} `}<T variant="caption" tone="muted">{t('as.marks', { n: q.marks })}</T></T>
          {q.type === 'short' ? (
            <TextInput accessibilityLabel={q.prompt} multiline value={(answers[q.id] as string | undefined) ?? ''} onChangeText={(v) => answer(q.id, v)}
              style={[styles.input, { borderColor: c.line, color: c.text, backgroundColor: c.surface }]} />
          ) : (
            <>
              {q.type === 'multi' ? <T variant="caption" tone="muted">{t('as.pickAll')}</T> : null}
              {q.options.map((o, k) => {
                const cur = answers[q.id];
                const on = q.type === 'single' ? cur === k : Array.isArray(cur) && cur.includes(k);
                return (
                  <Pressable key={k} accessibilityRole={q.type === 'single' ? 'radio' : 'checkbox'} accessibilityState={{ checked: on }} accessibilityLabel={o}
                    onPress={() => answer(q.id, q.type === 'single' ? k : (Array.isArray(cur) ? (on ? cur.filter((x) => x !== k) : [...cur, k]) : [k]))}
                    style={[styles.option, { borderColor: on ? c.brand : c.line, backgroundColor: on ? c.brandSoft : c.surface }]}>
                    <View style={[styles.tick, { borderColor: on ? c.brand : c.line, backgroundColor: on ? c.brand : 'transparent', borderRadius: q.type === 'single' ? 10 : 4 }]}>{on ? <Check size={12} color="#FFFFFF" /> : null}</View>
                    <T style={{ flex: 1 }}>{o}</T>
                  </Pressable>
                );
              })}
            </>
          )}
        </Card>
      ))}
    </Screen>
  );
}

function ResultView({ r }: { r: TestResult }) {
  const { t } = useI18n();
  const { c } = useTheme();
  return (
    <Screen title={t(r.status === 'submitted' ? 'as.handedIn' : 'as.title')}>
      <Card style={{ alignItems: 'center', gap: space.sm }}>
        {r.status === 'submitted' ? <T style={{ textAlign: 'center' }}>{t('as.waitingBody')}</T> : (
          <>
            <T style={{ fontSize: 36, fontWeight: '800', color: r.result === 'competent' ? c.success : c.text }}>{`${r.score ?? 0}%`}</T>
            <T style={{ fontWeight: '700', textAlign: 'center' }}>{t(r.result === 'competent' ? 'as.competentLong' : 'as.notYetLong')}</T>
          </>
        )}
      </Card>
      {r.revise.length > 0 ? (
        <Card style={{ marginTop: space.md, gap: space.xs }}>
          <T style={{ fontWeight: '700' }}>{t('as.revise')}</T>
          {r.revise.map((o) => <T key={o.code}>{`${o.code} · ${o.statement}`}</T>)}
        </Card>
      ) : null}
      {r.late ? <T variant="caption" tone="muted" style={{ marginTop: space.md }}>{t('as.lateNote')}</T> : null}
    </Screen>
  );
}

const styles = StyleSheet.create({
  row: { flexDirection: 'row', alignItems: 'center', gap: space.sm },
  note: { padding: space.md, borderRadius: radius.md, backgroundColor: '#FFF4E5', marginBottom: space.md },
  option: { flexDirection: 'row', alignItems: 'center', gap: space.md, minHeight: 52, paddingHorizontal: space.md, borderRadius: radius.md, borderWidth: 1.5 },
  tick: { width: 20, height: 20, borderWidth: 2, alignItems: 'center', justifyContent: 'center' },
  input: { minHeight: 100, borderWidth: 1.5, borderRadius: radius.md, padding: space.md, textAlignVertical: 'top' },
});
