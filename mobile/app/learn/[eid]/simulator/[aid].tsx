import { useEffect, useReducer, useRef, useState } from 'react';
import { WebView, type WebViewMessageEvent } from 'react-native-webview';
import { Pressable, StyleSheet, View } from 'react-native';
import { router, useLocalSearchParams } from 'expo-router';
import * as Speech from 'expo-speech';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { scoreRun, simReducer, startRun, touchable, type LearnScenario, type SimAction, type SimMode, type SimScore, type SimState } from '@simuwork/core';
import { api } from '../../../../src/lib/api';
import { attempts, syncAttempts } from '../../../../src/lib/attempts';
import { onlineOrPack, runtimeFile } from '../../../../src/lib/packs';
import { useI18n } from '../../../../src/lib/i18n';
import { BookOpen, Briefcase, Check, Clock, Dumbbell, Hand, Lightbulb, MessageCircleQuestion, ShieldAlert, UserRound, Volume2, Wifi, WifiOff, X } from '../../../../src/ui/icons';
import { Button, Card, haptics, radius, Screen, space, T, useTheme } from '../../../../src/ui';

const now = () => Date.now();
const secondsSince = (t0: number) => Math.max(0, Math.round((now() - t0) / 1000));
const clock = (s: number) => `${Math.floor(Math.abs(s) / 60)}:${String(Math.abs(s) % 60).padStart(2, '0')}`;
const VOICES: Record<string, string> = { en: 'en-ZA', zu: 'zu-ZA', xh: 'xh-ZA', af: 'af-ZA' };

/**
 * Learner: a workplace simulator on the phone, step by step (the same runtime and scoring as the web 3D view).
 * The 3D view comes to the app with the offline pack in 4.6; this view needs no download beyond the scenario.
 */
export default function SimulatorScreen() {
  const { eid, aid } = useLocalSearchParams<{ eid: string; aid: string }>();
  const { t, locale } = useI18n();
  const q = useQuery({ queryKey: ['learn-scenario', aid, locale], queryFn: () => onlineOrPack(eid, () => api<{ data: LearnScenario }>(`/learn/enrolments/${eid}/scenarios/${aid}?locale=${locale}`),
    (p) => (p.scenarios[aid] ? { data: { ...p.scenarios[aid], results: { practice: null, job: null } } } : undefined)) });
  if (!q.data) return <Screen title={t('lp.simulator')}>{q.isError ? <T tone="muted">{t('sim.notFound')}</T> : null}</Screen>;
  return <Play key={aid} eid={eid} sc={q.data.data} />;
}

type Finished = { mode: SimMode; score: SimScore; synced: boolean; steps: SimState['steps'] };

function Play({ eid, sc }: { eid: string; sc: LearnScenario }) {
  const { t } = useI18n();
  const { c } = useTheme();
  const qc = useQueryClient();
  const [run, setRun] = useState<{ mode: SimMode; n: number } | null>(null);
  const [finished, setFinished] = useState<Finished | null>(null);
  const attempt = useRef<Promise<string> | null>(null);

  const start = (mode: SimMode) => {
    attempt.current = attempts.start({ enrolment_id: eid, activity_id: sc.id, mode, content_version: sc.content_version, device: 'mobile' });
    setFinished(null);
    setRun((r) => ({ mode, n: (r?.n ?? 0) + 1 }));
  };
  const onMove = (s: SimState, seconds: number) => { void attempt.current?.then((id) => attempts.update(id, { result: { events: s.events, variant: s.variant }, duration_seconds: seconds })); };
  const onDone = async (s: SimState, seconds: number) => {
    const score = scoreRun(sc.content, s.events, s.mode, s.variant);
    const id = await attempt.current!;
    await attempts.finish(id, { completed: score.completed, score: score.score, duration_seconds: seconds, result: { events: s.events, variant: s.variant } });
    const progress = await syncAttempts();
    (score.passed ? haptics.success : haptics.warn)();
    setFinished({ mode: s.mode, score, synced: eid in progress, steps: s.steps });
    setRun(null);
    void qc.invalidateQueries({ queryKey: ['learn-home'] });
    void qc.invalidateQueries({ queryKey: ['learn-activities', eid] });
    void qc.invalidateQueries({ queryKey: ['learn-scenario', sc.id] });
  };

  if (run) return <Runner key={run.n} eid={eid} sc={sc} mode={run.mode} onMove={onMove} onDone={(s, sec) => void onDone(s, sec)} onQuit={() => setRun(null)} />;
  if (finished) return <Debrief eid={eid} sc={sc} f={finished} onAgain={start} />;

  return (
    <Screen title={sc.title} subtitle={sc.summary ?? undefined}>
      {(['practice', 'job'] as const).map((mode) => {
        const r = sc.results[mode];
        const Icon = mode === 'practice' ? Dumbbell : Briefcase;
        return (
          <Card key={mode} style={{ marginBottom: space.md, gap: space.sm }}>
            <View style={styles.row}><Icon size={20} color={c.brand} /><T style={{ fontWeight: '700', fontSize: 17, flex: 1 }}>{t(mode === 'practice' ? 'sim.practice' : 'sim.job')}</T>{r?.passed ? <T tone="brand" style={{ fontWeight: '700' }}>{t('sim.passed')}</T> : null}</View>
            <T tone="muted">{t(mode === 'practice' ? 'sim.practiceBody' : 'sim.jobBody')}</T>
            {r ? <T variant="caption" tone="muted">{t('sim.best', { score: r.best, n: r.tries })}</T> : null}
            <Button title={`${t('sim.start')}: ${t(mode === 'practice' ? 'sim.practice' : 'sim.job')}`} variant={mode === 'practice' ? 'primary' : 'secondary'} onPress={() => start(mode)} />
          </Card>
        );
      })}
    </Screen>
  );
}

function Runner({ eid, sc, mode, onMove, onDone, onQuit }: {
  eid: string; sc: LearnScenario; mode: SimMode; onMove: (s: SimState, sec: number) => void; onDone: (s: SimState, sec: number) => void; onQuit: () => void;
}) {
  const { t, tx, locale } = useI18n();
  const { c } = useTheme();
  const content = sc.content;
  const started = useRef(now());
  const [s, dispatch] = useReducer((st: SimState, a: SimAction) => simReducer(st, a), content, (x) => startRun(x, mode));
  const [left, setLeft] = useState((content.job?.minutes ?? 0) * 60);
  const reported = useRef(0);
  // The 3D view comes with the offline pack; without it (or by choice) the step-by-step view is used.
  const [has3d] = useState(() => runtimeFile().exists);
  const [view, setView] = useState<'3d' | '2d'>(has3d ? '3d' : '2d');
  const web = useRef<WebView<object>>(null);
  const post = (msg: object) => web.current?.postMessage?.(JSON.stringify(msg));
  useEffect(() => { post({ type: 'state', scene: s.scene, holding: s.holding }); }, [s.scene, s.holding]);
  useEffect(() => { if (s.feedback?.kind === 'wrong') post({ type: 'flash', kind: s.feedback.effect }); }, [s.feedback]);
  const onWeb = (e: WebViewMessageEvent) => {
    try {
      const m = JSON.parse(e.nativeEvent.data) as { type: string; id?: string };
      if (m.type === 'ready') post({ type: 'state', scene: s.scene, holding: s.holding });
      if (m.type === 'tap' && m.id) dispatch({ type: 'tap', object: m.id, t: secondsSince(started.current) });
    } catch { /* not ours */ }
  };
  const step = s.steps[s.step];

  useEffect(() => {
    if (s.events.length === reported.current) return;
    reported.current = s.events.length;
    const sec = secondsSince(started.current);
    if (s.done) onDone(s, sec); else onMove(s, sec);
    if (s.feedback?.kind === 'wrong') haptics.warn();
  }, [s, onMove, onDone]);
  useEffect(() => {
    if (mode !== 'job') return;
    const id = setInterval(() => setLeft((content.job?.minutes ?? 0) * 60 - secondsSince(started.current)), 1000);
    return () => clearInterval(id);
  }, [mode, content.job?.minutes]);

  const [answer, setAnswer] = useState<{ step: string; text: string | null } | null>(null);
  // The AI coach (practice only); offline or on failure it falls back to the author's hints and "why it matters".
  const ask = (kind: 'why' | 'help') => {
    if (!step) return;
    dispatch({ type: 'coach', ask: kind, t: secondsSince(started.current) });
    setAnswer({ step: step.id, text: null });
    const fallback = kind === 'why' ? step.why || step.hints[0] || '' : step.hints.join(' ');
    api<{ data: { answer: string } }>(`/learn/enrolments/${eid}/scenarios/${sc.id}/coach`, { method: 'POST', body: { step: step.id, kind, variant: s.variant, locale } })
      .then((r) => r.data.answer || fallback, () => fallback)
      .then((text) => setAnswer((cur) => (cur?.step === step.id ? { step: step.id, text } : cur)));
  };
  if (!step) return <Screen center>{null}</Screen>;
  const coachAnswer = answer && answer.step === step.id ? answer : null;
  const name = (id: string) => tx(`sim.obj.${id}`, id);
  const at = () => secondsSince(started.current);

  return (
    <Screen title={sc.title} subtitle={t('sim.step', { n: s.step + 1, total: s.steps.length })}>
      <View style={[styles.row, { justifyContent: 'space-between', marginBottom: space.sm }]}>
        {mode === 'job' ? <View style={styles.row}><Clock size={16} color={left < 0 ? c.danger : c.text} /><T style={{ fontWeight: '700', color: left < 0 ? c.danger : c.text }}>{left < 0 ? t('sim.overTime') : t('sim.timeLeft', { time: clock(left) })}</T></View> : <View />}
        <Pressable accessibilityRole="button" onPress={onQuit} style={[styles.row, { minHeight: 44 }]}><X size={18} color={c.muted} /><T tone="muted">{t('sim.quit')}</T></Pressable>
      </View>
      {mode === 'job' && content.job?.customer ? <View style={[styles.note, { backgroundColor: '#FFF4E5' }]}><UserRound size={16} color="#7A4B00" /><T style={{ flex: 1, color: '#7A4B00' }}>{content.job.customer}</T></View> : null}

      <View style={[styles.prompt, { backgroundColor: c.nav }]}>
        <View style={styles.row}>
          <T tone="inverse" accessibilityLiveRegion="polite" style={{ flex: 1, fontSize: 18, fontWeight: '700', lineHeight: 24 }}>{step.prompt}</T>
          <Pressable accessibilityRole="button" accessibilityLabel={t('sim.readAloud')} onPress={() => { Speech.stop(); Speech.speak(step.prompt, { language: VOICES[locale] ?? 'en-ZA' }); }} style={styles.iconBtn}><Volume2 size={22} color="#FFFFFF" /></Pressable>
        </View>
        {step.hints.slice(0, s.hints[step.id] ?? 0).map((h, i) => <View key={i} style={[styles.row, { marginTop: space.sm }]}><Lightbulb size={16} color="#FCD34D" /><T tone="inverse" style={{ flex: 1, opacity: 0.9 }}>{h}</T></View>)}
        {coachAnswer ? <View accessibilityLiveRegion="polite" style={[styles.row, styles.coach]}><MessageCircleQuestion size={16} color="#93C5FD" /><T tone="inverse" style={{ flex: 1 }}>{coachAnswer.text ?? t('sim.coachThinking')}</T></View> : null}
        {mode === 'practice' ? (
          <View style={[styles.row, { flexWrap: 'wrap' }]}>
            {step.hints.length > (s.hints[step.id] ?? 0) ? <Pressable accessibilityRole="button" onPress={() => dispatch({ type: 'hint', t: at() })} style={[styles.row, styles.hintBtn]}><Lightbulb size={16} color="#FFFFFF" /><T tone="inverse" style={{ fontWeight: '700' }}>{t('sim.hint')}</T></Pressable> : null}
            <Pressable accessibilityRole="button" onPress={() => ask('help')} style={[styles.row, styles.hintBtn]}><MessageCircleQuestion size={16} color="#FFFFFF" /><T tone="inverse" style={{ fontWeight: '700' }}>{t('sim.askCoach')}</T></Pressable>
          </View>
        ) : null}
      </View>

      {s.feedback?.kind === 'wrong' ? (
        <View accessibilityRole="alert" style={[styles.note, { backgroundColor: '#FDECEC' }]}>
          <T style={{ flex: 1, color: '#7F1D1D' }}><T style={{ fontWeight: '700', color: '#7F1D1D' }}>{`${t('sim.wrong')} `}</T>{s.feedback.message}</T>
          {mode === 'practice' ? <Pressable accessibilityRole="button" onPress={() => { ask('why'); dispatch({ type: 'dismiss' }); }} style={styles.small}><T style={{ fontWeight: '700', color: '#7F1D1D' }}>{t('sim.why')}</T></Pressable> : null}
          <Pressable accessibilityRole="button" onPress={() => dispatch({ type: 'dismiss' })} style={styles.small}><T style={{ fontWeight: '700', color: '#7F1D1D' }}>{t('sim.ok')}</T></Pressable>
        </View>
      ) : null}
      {s.holding ? <View style={[styles.note, { backgroundColor: c.brandSoft }]}><Hand size={16} color={c.brand} /><T style={{ flex: 1 }}>{t('sim.holding', { name: name(s.holding).toLowerCase() })}</T></View> : null}

      {step.type === 'choose' ? (
        <View style={{ gap: space.sm }}>
          {step.options.map((o, i) => (
            <Pressable key={i} accessibilityRole="button" onPress={() => dispatch({ type: 'choose', index: i, t: at() })} style={[styles.option, { borderColor: c.line, backgroundColor: c.surface }]}><T>{o}</T></Pressable>
          ))}
        </View>
      ) : (
        <>
        {view === '3d' ? (
          <View style={[styles.scene, { borderColor: c.line }]}>
            <WebView ref={web} source={{ uri: runtimeFile().uri }} originWhitelist={['*']} allowFileAccess allowingReadAccessToURL={runtimeFile().uri}
              onMessage={onWeb} scrollEnabled={false} bounces={false} accessibilityLabel={step.prompt} style={{ backgroundColor: 'transparent' }} />
          </View>
        ) : null}
        <View style={styles.grid}>
          {touchable(content).map((id) => (
            <Pressable key={id} accessibilityRole="button" accessibilityState={{ selected: s.holding === id }} onPress={() => dispatch({ type: 'tap', object: id, t: at() })}
              style={[styles.tile, { borderColor: s.holding === id ? c.brand : c.line, backgroundColor: s.holding === id ? c.brandSoft : c.surface }]}>
              <T style={{ fontWeight: '700' }}>{name(id)}</T>
              <T variant="caption" tone="muted">{tx(`sim.state.${s.scene[id] ?? 'idle'}`, s.scene[id] ?? '')}</T>
            </Pressable>
          ))}
        </View>
        </>
      )}
      <T variant="caption" tone="muted" style={{ marginTop: space.md }}>{t('sim.how')}</T>
      {has3d ? (
        <View style={[styles.row, { marginTop: space.sm }]}>
          {(['3d', '2d'] as const).map((v) => (
            <Pressable key={v} accessibilityRole="button" accessibilityState={{ selected: view === v }} onPress={() => setView(v)} style={[styles.toggle, { borderColor: view === v ? c.brand : c.line }]}>
              <T style={{ fontWeight: view === v ? '700' : '400' }}>{t(v === '3d' ? 'sim.view3d' : 'sim.view2d')}</T>
            </Pressable>
          ))}
        </View>
      ) : null}
    </Screen>
  );
}

function Debrief({ eid, sc, f, onAgain }: { eid: string; sc: LearnScenario; f: Finished; onAgain: (m: SimMode) => void }) {
  const { t } = useI18n();
  const { c } = useTheme();
  const { score } = f;
  const verdict = score.passed ? (f.mode === 'job' ? 'sim.passedJob' : 'sim.passedPractice') : f.mode === 'job' && score.critical ? 'sim.criticalFail' : 'sim.notPassed';
  return (
    <Screen title={t('sim.debrief')}>
      <Card style={{ alignItems: 'center', gap: space.sm }}>
        <T style={{ fontSize: 34, fontWeight: '800', color: score.passed ? c.success : c.text }}>{t('sim.score', { score: score.score })}</T>
        <View style={styles.row}>{score.passed ? <Check size={18} color={c.success} /> : <ShieldAlert size={18} color={score.critical ? c.danger : c.muted} />}<T style={{ fontWeight: '700', flexShrink: 1 }}>{t(verdict)}</T></View>
        <View style={styles.row}>{f.synced ? <Wifi size={14} color={c.muted} /> : <WifiOff size={14} color={c.muted} />}<T variant="caption" tone="muted">{t(f.synced ? 'lp.synced' : 'lp.savedOffline')}</T></View>
      </Card>
      <View style={{ gap: space.sm, marginVertical: space.lg }}>
        {score.passed && f.mode === 'practice' ? <Button title={t('sim.asJob')} onPress={() => onAgain('job')} /> : null}
        <Button variant={score.passed && f.mode === 'practice' ? 'secondary' : 'primary'} title={t('sim.again')} onPress={() => onAgain(f.mode)} />
      </View>
      <T style={{ fontWeight: '700', marginBottom: space.sm }}>{t('sim.replay')}</T>
      <Card style={{ padding: 0 }}>
        {f.steps.map((st, i) => {
          const r = score.steps[st.id] ?? { mistakes: 0, hints: 0, done: false };
          const clean = r.done && !r.mistakes && !r.hints;
          const lessonId = st.lesson ? sc.lessons[st.lesson.code] : undefined;
          return (
            <View key={st.id} style={[styles.stepRow, i > 0 && { borderTopWidth: StyleSheet.hairlineWidth, borderTopColor: c.line }]}>
              <View style={[styles.mark, { backgroundColor: r.mistakes ? c.danger : r.hints ? '#B45309' : c.success }]}>{r.mistakes ? <X size={14} color="#FFFFFF" /> : r.hints ? <Lightbulb size={14} color="#FFFFFF" /> : <Check size={14} color="#FFFFFF" />}</View>
              <View style={{ flex: 1, gap: 2 }}>
                <T style={{ fontWeight: '700' }}>{`${i + 1}. ${st.prompt}`}</T>
                <T variant="caption" tone="muted">{clean ? t('sim.clean') : [r.mistakes ? t('sim.mistakes', { n: r.mistakes }) : '', r.hints ? t('sim.hintsUsed', { n: r.hints }) : ''].filter(Boolean).join(' · ')}</T>
                {!clean && st.why ? <T><T style={{ fontWeight: '700' }}>{`${t('sim.whyMatters')}: `}</T>{st.why}</T> : null}
                {!clean && lessonId ? (
                  <Pressable accessibilityRole="link" onPress={() => router.push(`/learn/${eid}/lesson/${lessonId}`)} style={[styles.row, { minHeight: 44 }]}><BookOpen size={16} color={c.brand} /><T tone="brand" style={{ fontWeight: '700' }}>{t('sim.reviewLesson')}</T></Pressable>
                ) : null}
              </View>
            </View>
          );
        })}
      </Card>
    </Screen>
  );
}

const styles = StyleSheet.create({
  row: { flexDirection: 'row', alignItems: 'center', gap: space.sm },
  note: { flexDirection: 'row', alignItems: 'center', gap: space.sm, padding: space.md, borderRadius: radius.md, marginBottom: space.md },
  prompt: { padding: space.lg, borderRadius: radius.lg, marginBottom: space.md },
  iconBtn: { width: 44, height: 44, borderRadius: radius.md, alignItems: 'center', justifyContent: 'center', backgroundColor: 'rgba(255,255,255,0.12)' },
  hintBtn: { alignSelf: 'flex-start', marginTop: space.md, paddingHorizontal: space.md, minHeight: 44, borderRadius: radius.md, backgroundColor: 'rgba(255,255,255,0.12)' },
  option: { minHeight: 56, justifyContent: 'center', paddingHorizontal: space.lg, borderRadius: radius.md, borderWidth: 1.5 },
  grid: { flexDirection: 'row', flexWrap: 'wrap', gap: space.sm },
  coach: { marginTop: space.md, padding: space.md, borderRadius: radius.md, backgroundColor: 'rgba(255,255,255,0.12)' },
  small: { minHeight: 44, minWidth: 44, paddingHorizontal: space.sm, alignItems: 'center', justifyContent: 'center' },
  scene: { height: 300, borderRadius: radius.lg, borderWidth: 1, overflow: 'hidden', marginBottom: space.md },
  toggle: { minHeight: 44, paddingHorizontal: space.md, borderRadius: radius.md, borderWidth: 1.5, justifyContent: 'center' },
  tile: { width: '48%', minHeight: 64, padding: space.md, borderRadius: radius.md, borderWidth: 1.5, justifyContent: 'center' },
  stepRow: { flexDirection: 'row', gap: space.md, padding: space.lg },
  mark: { width: 26, height: 26, borderRadius: 13, alignItems: 'center', justifyContent: 'center', marginTop: 2 },
});
