import { useEffect, useRef, useState } from 'react';
import { View } from 'react-native';
import { router, useLocalSearchParams } from 'expo-router';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { lessonScore, type LearnHomeData, type LearnLesson } from '@simuwork/core';
import { api } from '../../../../src/lib/api';
import { attempts, syncAttempts } from '../../../../src/lib/attempts';
import { useI18n } from '../../../../src/lib/i18n';
import { useSettings } from '../../../../src/lib/settings';
import { LessonView } from '../../../../src/lesson/LessonView';
import { Sparkles, Wifi, WifiOff } from '../../../../src/ui/icons';
import { Button, Card, EmptyState, haptics, Screen, space, T, useTheme } from '../../../../src/ui';

const now = () => Date.now();
const secondsSince = (t0: number) => Math.max(0, Math.round((now() - t0) / 1000));

/** Learner: one lesson on the phone. Answers are saved on the phone straight away; the result syncs when online. */
export default function LessonScreen() {
  const { eid, aid } = useLocalSearchParams<{ eid: string; aid: string }>();
  const { t, locale } = useI18n();
  const lesson = useQuery({ queryKey: ['learn-lesson', aid, locale], queryFn: () => api<{ data: LearnLesson }>(`/learn/enrolments/${eid}/lessons/${aid}?locale=${locale}`), staleTime: 5 * 60_000 });
  if (!lesson.data) return <Screen title={t('lp.lesson')}>{lesson.isError ? <T tone="muted">{t('lp.notFound')}</T> : null}</Screen>;
  return <Player key={aid} eid={eid} l={lesson.data.data} />;
}

function Player({ eid, l }: { eid: string; l: LearnLesson }) {
  const { t, locale } = useI18n();
  const { c } = useTheme();
  const { dataSaver } = useSettings();
  const qc = useQueryClient();
  const [answers, setAnswers] = useState<Record<string, boolean>>({});
  const [finished, setFinished] = useState<{ synced: boolean } | null>(null);
  const attempt = useRef<Promise<string> | null>(null);
  const started = useRef(0);
  const s = lessonScore(l.blocks, answers);

  useEffect(() => {
    if (!started.current) started.current = now();
    attempt.current ??= attempts.start({ enrolment_id: eid, activity_id: l.id, mode: 'lesson', content_version: l.content_version, device: 'mobile' });
  }, [eid, l.id, l.content_version]);

  const answer = (blockId: string, correct: boolean) => {
    if (blockId in answers) return;
    const next = { ...answers, [blockId]: correct };
    setAnswers(next);
    void attempt.current?.then((id) => attempts.update(id, { result: { answers: next }, duration_seconds: secondsSince(started.current) }));
  };
  const finish = async () => {
    const id = await attempt.current!;
    await attempts.finish(id, { completed: true, score: s.score, duration_seconds: secondsSince(started.current), result: { answers } });
    const progress = await syncAttempts();
    haptics.success();
    setFinished({ synced: eid in progress });
    void qc.invalidateQueries({ queryKey: ['learn-home'] });
    void qc.invalidateQueries({ queryKey: ['learn-activities', eid] });
  };
  const next = async () => {
    const home = await qc.fetchQuery({ queryKey: ['learn-home'], queryFn: () => api<{ data: LearnHomeData }>('/learn/home') });
    const n = home.data.enrolments.find((e) => e.id === eid)?.next;
    if (n) router.replace(`/learn/${eid}/${n.kind === 'lesson' ? 'lesson' : 'simulator'}/${n.id}`);
    else router.replace(`/learn/${eid}`);
  };

  if (finished) {
    return (
      <Screen center>
        <EmptyState icon={<Sparkles size={28} color={c.brand} />} title={t('lp.doneTitle')} body={s.score !== null ? t('lp.doneScore', { right: s.right, n: s.checks }) : ''} />
        <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 6, marginTop: space.md }}>
          {finished.synced ? <Wifi size={16} color={c.muted} /> : <WifiOff size={16} color={c.muted} />}
          <T variant="caption" tone="muted">{t(finished.synced ? 'lp.synced' : 'lp.savedOffline')}</T>
        </View>
        <View style={{ gap: space.md, marginTop: space.xl }}>
          <Button title={t('lp.next')} onPress={() => void next()} />
          <Button variant="ghost" title={t('lp.allLessons')} onPress={() => router.replace(`/learn/${eid}`)} />
        </View>
      </Screen>
    );
  }

  return (
    <Screen title={l.title} subtitle={`${t('lp.minutes', { n: l.minutes })}${locale !== 'en' && l.locale === 'en' ? ` · ${t('lp.inEnglish')}` : ''}`}
      bottom={
        <View style={{ gap: 6 }}>
          <T variant="caption" tone="muted" style={{ textAlign: 'center' }}>{s.checks ? t('lp.checksDone', { done: s.answered, n: s.checks }) : t('lp.readAll')}</T>
          <Button title={t('lp.finish')} disabled={s.answered < s.checks} onPress={() => void finish()} />
        </View>
      }>
      <Card style={{ padding: 0, backgroundColor: 'transparent', borderWidth: 0 }}>
        <LessonView blocks={l.blocks} assets={l.assets} scenarios={Object.fromEntries(Object.values(l.scenarios).map((x) => [x.code, x.title]))} dataSaver={dataSaver}
          onAnswer={answer} onTry={(code) => { const sc = l.scenarios[code]; if (sc) router.push(`/learn/${eid}/simulator/${sc.id}`); }} />
      </Card>
    </Screen>
  );
}
