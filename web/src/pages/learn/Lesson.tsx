import { useEffect, useRef, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, ArrowRight, PartyPopper, Wifi, WifiOff } from 'lucide-react';
import { lessonScore, type LearnHomeData, type LearnLesson } from '@simuwork/core';
import { LessonView } from '../../components/lesson/LessonView';
import { Button, Card, Skeleton, Switch } from '../../components/ui';
import { api } from '../../lib/api';
import { attempts, dataSaver, syncAttempts } from '../../lib/attempts';
import { useI18n } from '../../lib/i18n';

const now = () => Date.now();
const secondsSince = (t0: number) => Math.max(0, Math.round((now() - t0) / 1000));

/** Learner: one lesson. Every answer is saved on the device straight away; the result syncs when online. */
export default function Lesson() {
  const { eid = '', aid = '' } = useParams();
  const { t, locale } = useI18n();
  const lesson = useQuery({ queryKey: ['learn-lesson', aid, locale], queryFn: () => api<{ data: LearnLesson }>(`/learn/enrolments/${eid}/lessons/${aid}?locale=${locale}`), staleTime: 5 * 60_000 });
  if (lesson.isError) return <p className="py-10 text-center text-muted">{t('lp.notFound')}</p>;
  if (!lesson.data) return <div className="mx-auto max-w-2xl space-y-4"><Skeleton className="h-10 w-72" /><Skeleton className="h-96 rounded-2xl" /></div>;
  return <Player key={aid} eid={eid} l={lesson.data.data} />;
}

function Player({ eid, l }: { eid: string; l: LearnLesson }) {
  const { t, locale: appLocale } = useI18n();
  const navigate = useNavigate();
  const qc = useQueryClient();
  const [saver, setSaver] = useState(dataSaver.get);
  const [answers, setAnswers] = useState<Record<string, boolean>>({});
  const [finished, setFinished] = useState<{ score: number | null; synced: boolean } | null>(null);
  const attempt = useRef<Promise<string> | null>(null);
  const started = useRef(0);
  const s = lessonScore(l.blocks, answers);

  useEffect(() => {
    // One attempt per visit, saved on the device before anything else happens.
    if (!started.current) started.current = now();
    attempt.current ??= attempts.start({ enrolment_id: eid, activity_id: l.id, mode: 'lesson', content_version: l.content_version, device: 'web' });
  }, [eid, l.id, l.content_version]);

  const answer = (blockId: string, correct: boolean) => {
    if (blockId in answers) return; // the first answer counts
    const next = { ...answers, [blockId]: correct };
    setAnswers(next);
    void attempt.current?.then((id) => attempts.update(id, { result: { answers: next }, duration_seconds: secondsSince(started.current) }));
  };

  const finish = async () => {
    const id = await attempt.current!;
    await attempts.finish(id, { completed: true, score: s.score, duration_seconds: secondsSince(started.current), result: { answers } });
    const progress = await syncAttempts();
    setFinished({ score: s.score, synced: eid in progress });
    void qc.invalidateQueries({ queryKey: ['learn-home'] });
    void qc.invalidateQueries({ queryKey: ['learn-activities', eid] });
  };

  const goNext = async () => {
    const home = await qc.fetchQuery({ queryKey: ['learn-home'], queryFn: () => api<{ data: LearnHomeData }>('/learn/home') });
    const next = home.data.enrolments.find((e) => e.id === eid)?.next;
    void navigate(next ? `/learn/enrolments/${eid}/${next.kind === 'lesson' ? 'lessons' : 'simulators'}/${next.id}` : `/learn/enrolments/${eid}`);
  };

  if (finished) {
    return (
      <div className="sw-in mx-auto max-w-md pt-6">
        <Card className="p-7 text-center">
          <PartyPopper className="mx-auto size-12 text-brand" />
          <h1 className="mt-3 text-[22px] font-bold text-ink">{t('lp.doneTitle')}</h1>
          {finished.score !== null && <p className="mt-2 text-[15px] text-muted">{t('lp.doneScore', { right: s.right, n: s.checks })}</p>}
          <p className="mt-3 flex items-center justify-center gap-1.5 text-[13px] text-muted">{finished.synced ? <Wifi className="size-4" /> : <WifiOff className="size-4" />}{t(finished.synced ? 'lp.synced' : 'lp.savedOffline')}</p>
          <div className="mt-6 flex flex-col gap-2">
            <Button size="lg" icon={<ArrowRight className="size-5" />} onClick={() => void goNext()}>{t('lp.next')}</Button>
            <Link to={`/learn/enrolments/${eid}`} className="text-[14px] font-semibold text-brand hover:underline">{t('lp.allLessons')}</Link>
          </div>
        </Card>
      </div>
    );
  }

  return (
    <div className="sw-in mx-auto max-w-2xl pb-28">
      <Link to={`/learn/enrolments/${eid}`} className="mb-3 inline-flex items-center gap-1.5 text-[13px] font-semibold text-muted hover:text-ink"><ArrowLeft className="size-4" />{t('lp.allLessons')}</Link>
      <h1 className="text-[26px] font-bold tracking-tight text-ink">{l.title}</h1>
      <div className="mt-2 flex flex-wrap items-center justify-between gap-3 text-[13px] text-muted">
        <span>{t('lp.minutes', { n: l.minutes })}{appLocale !== 'en' && l.locale === 'en' ? ` · ${t('lp.inEnglish')}` : ''}</span>
        <label className="flex items-center gap-2 font-medium text-ink">{t('lp.dataSaver')}<Switch checked={saver} onChange={(v) => { setSaver(v); dataSaver.set(v); }} label={t('lp.dataSaver')} /></label>
      </div>
      <div className="mt-5"><LessonView blocks={l.blocks} assets={l.assets} scenarios={Object.fromEntries(Object.values(l.scenarios).map((x) => [x.code, x.title]))} dataSaver={saver} onAnswer={answer}
        onTry={(code) => { const sc = l.scenarios[code]; if (sc) void navigate(`/learn/enrolments/${eid}/simulators/${sc.id}`); }} /></div>

      <div className="fixed inset-x-0 bottom-0 z-20 border-t border-line bg-surface/95 px-4 py-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] backdrop-blur-md">
        <div className="mx-auto flex max-w-2xl items-center gap-3">
          <span className="flex-1 text-[13px] text-muted">{s.checks > 0 ? t('lp.checksDone', { done: s.answered, n: s.checks }) : t('lp.readAll')}</span>
          <Button size="lg" disabled={s.answered < s.checks} onClick={() => void finish()}>{t('lp.finish')}</Button>
        </div>
      </div>
    </div>
  );
}
