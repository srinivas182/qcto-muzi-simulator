import { useCallback, useEffect, useRef, useState } from 'react';
import { Alert, Pressable, StyleSheet, TextInput, View } from 'react-native';
import AsyncStorage from '@react-native-async-storage/async-storage';
import NetInfo from '@react-native-community/netinfo';
import { useLocalSearchParams } from 'expo-router';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { CameraView, useCameraPermissions } from 'expo-camera';
import type { LearnAssessmentItem, TestPaper, TestResult } from '@simuwork/core';
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
  const [consent, setConsent] = useState(false);
  const list = useQuery({ queryKey: ['learn-assessments', eid], queryFn: () => api<{ data: LearnAssessmentItem[] }>(`/learn/enrolments/${eid}/assessments`) });
  const camera = list.data?.data.find((x) => x.id === tid)?.camera ?? false;
  const start = useMutation({
    mutationFn: async () => {
      const p = (await api<{ data: TestPaper }>(`/learn/enrolments/${eid}/tests/${tid}/start`, { method: 'POST', body: { locale, device: 'mobile', camera_consent: consent } })).data;
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
      {camera ? (
        <Card style={{ marginTop: space.md, gap: space.sm }}>
          <T style={{ fontWeight: '700' }}>{t('as.cam.title')}</T>
          <T tone="muted">{t('as.cam.body')}</T>
          <Pressable accessibilityRole="checkbox" accessibilityState={{ checked: consent }} accessibilityLabel={t('as.cam.agree')} onPress={() => setConsent(!consent)} style={styles.row}>
            <View style={[styles.box, consent && styles.boxOn]}>{consent ? <Check size={14} color="#fff" /> : null}</View>
            <T style={{ flex: 1 }}>{t('as.cam.agree')}</T>
          </Pressable>
          <T variant="caption" tone="muted">{t('as.cam.decline')}</T>
        </Card>
      ) : null}
      {start.isError ? <T style={{ marginTop: space.md, color: '#C92A2F' }}>{start.error instanceof Error ? start.error.message : t('common.error')}</T> : null}
      <View style={{ marginTop: space.lg }}><Button title={t('as.begin')} disabled={camera && !consent} loading={start.isPending} onPress={() => start.mutate()} /></View>
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
      {p.camera ? <CameraCheck sitting={p.id} minutes={Math.ceil(p.seconds_left / 60)} label={t('as.cam.on')} /> : null}
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

/**
 * Camera check (Sprint 5.1c): a small preview, one photo at the start and three at random times, taken at the
 * camera's smallest picture size and low quality, sent straight to the server. If the camera cannot start, the
 * test carries on and the assessor sees there are no photos.
 */
function CameraCheck({ sitting, minutes, label }: { sitting: string; minutes: number; label: string }) {
  const [perm, ask] = useCameraPermissions();
  const cam = useRef<CameraView>(null);
  const [size, setSize] = useState<string | undefined>(undefined);
  useEffect(() => { if (perm && !perm.granted && perm.canAskAgain) void ask(); }, [perm, ask]);
  useEffect(() => {
    if (!perm?.granted) return;
    const snap = async () => {
      try {
        const shot = await cam.current?.takePictureAsync({ quality: 0.2, shutterSound: false });
        if (!shot) return;
        const form = new FormData();
        form.append('photo', { uri: shot.uri, name: 'photo.jpg', type: 'image/jpeg' } as unknown as Blob);
        await api(`/learn/sittings/${sitting}/photos`, { method: 'POST', body: form });
      } catch { /* the test carries on */ }
    };
    const span = Math.max(1, minutes) * 60_000;
    const timers = [setTimeout(() => void snap(), 3000), ...[0, 1, 2].map(() => setTimeout(() => void snap(), 30_000 + Math.random() * (span - 60_000)))];
    return () => timers.forEach(clearTimeout);
  }, [perm?.granted, sitting, minutes]);
  if (!perm?.granted) return null;
  return (
    <View style={[styles.row, { marginBottom: space.md }]}>
      <CameraView ref={cam} facing="front" style={styles.cam} pictureSize={size}
        onCameraReady={() => { void cam.current?.getAvailablePictureSizesAsync().then((all) => setSize(smallest(all))).catch(() => undefined); }} />
      <T variant="caption" tone="muted">{label}</T>
    </View>
  );
}

/** The smallest picture size the camera offers ("640x480" etc.), so each photo stays well under 60 KB. */
function smallest(sizes: string[]): string | undefined {
  const area = (s: string) => s.split('x').map(Number).reduce((a, b) => a * (b || 1), 1);
  return [...sizes].filter((s) => /^\d+x\d+$/.test(s)).sort((a, b) => area(a) - area(b))[0];
}

const styles = StyleSheet.create({
  cam: { width: 48, height: 64, borderRadius: radius.sm, overflow: 'hidden' },
  box: { width: 22, height: 22, borderRadius: 6, borderWidth: 2, borderColor: '#5B6577', alignItems: 'center', justifyContent: 'center' },
  boxOn: { backgroundColor: '#0E7C66', borderColor: '#0E7C66' },
  row: { flexDirection: 'row', alignItems: 'center', gap: space.sm },
  note: { padding: space.md, borderRadius: radius.md, backgroundColor: '#FFF4E5', marginBottom: space.md },
  option: { flexDirection: 'row', alignItems: 'center', gap: space.md, minHeight: 52, paddingHorizontal: space.md, borderRadius: radius.md, borderWidth: 1.5 },
  tick: { width: 20, height: 20, borderWidth: 2, alignItems: 'center', justifyContent: 'center' },
  input: { minHeight: 100, borderWidth: 1.5, borderRadius: radius.md, padding: space.md, textAlignVertical: 'top' },
});
