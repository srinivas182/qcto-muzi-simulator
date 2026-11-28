import { useCallback, useRef, useState, type ReactNode } from 'react';
import { Link, useParams } from 'react-router';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, BookOpen, Briefcase, Check, CircleAlert, Dumbbell, Lightbulb, RotateCcw, ShieldAlert, Wifi, WifiOff, X } from 'lucide-react';
import { scoreRun, type LearnScenario, type SimMode, type SimScore, type SimState } from '@simuwork/core';
import { SimRunner } from '../../components/sim/SimRunner';
import { Badge, Button, Card, cx, Skeleton } from '../../components/ui';
import { api } from '../../lib/api';
import { attempts, syncAttempts } from '../../lib/attempts';
import { useI18n } from '../../lib/i18n';

/** Learner: a workplace simulator. Choose practice or real job, do the task, then see how it went. */
export default function Simulator() {
  const { eid = '', aid = '' } = useParams();
  const { t, locale } = useI18n();
  const q = useQuery({ queryKey: ['learn-scenario', aid, locale], queryFn: () => api<{ data: LearnScenario }>(`/learn/enrolments/${eid}/scenarios/${aid}?locale=${locale}`) });
  if (q.isError) return <p className="py-10 text-center text-muted">{t('sim.notFound')}</p>;
  if (!q.data) return <div className="mx-auto max-w-3xl space-y-4"><Skeleton className="h-10 w-72" /><Skeleton className="h-96 rounded-2xl" /></div>;
  return <Play key={aid} eid={eid} sc={q.data.data} />;
}

type Run = { mode: SimMode; n: number };
type Finished = { mode: SimMode; state: SimState; score: SimScore; seconds: number; synced: boolean };

function Play({ eid, sc }: { eid: string; sc: LearnScenario }) {
  const { t } = useI18n();
  const qc = useQueryClient();
  const [run, setRun] = useState<Run | null>(null);
  const [finished, setFinished] = useState<Finished | null>(null);
  const attempt = useRef<Promise<string> | null>(null);

  const start = (mode: SimMode) => {
    attempt.current = attempts.start({ enrolment_id: eid, activity_id: sc.id, mode, content_version: sc.content_version, device: 'web' });
    setFinished(null);
    setRun((r) => ({ mode, n: (r?.n ?? 0) + 1 }));
  };
  const onMove = useCallback((s: SimState, seconds: number) => {
    void attempt.current?.then((id) => attempts.update(id, { result: { events: s.events, variant: s.variant }, duration_seconds: seconds }));
  }, []);
  const onDone = useCallback((s: SimState, seconds: number) => {
    const score = scoreRun(sc.content, s.events, s.mode, s.variant);
    void (async () => {
      const id = await attempt.current!;
      await attempts.finish(id, { completed: score.completed, score: score.score, duration_seconds: seconds, result: { events: s.events, variant: s.variant } });
      const progress = await syncAttempts();
      setFinished({ mode: s.mode, state: s, score, seconds, synced: eid in progress });
      setRun(null);
      void qc.invalidateQueries({ queryKey: ['learn-home'] });
      void qc.invalidateQueries({ queryKey: ['learn-activities', eid] });
      void qc.invalidateQueries({ queryKey: ['learn-scenario', sc.id] });
    })();
  }, [eid, qc, sc.content, sc.id]);

  const back = <Link to={`/learn/enrolments/${eid}`} className="inline-flex items-center gap-1 text-[14px] font-semibold text-brand hover:underline"><ArrowLeft className="size-4" />{t('lp.allLessons')}</Link>;

  if (run) {
    return (
      <div className="sw-in mx-auto max-w-3xl">
        <h1 className="mb-3 text-[20px] font-bold text-ink">{sc.title}</h1>
        <SimRunner key={run.n} content={sc.content} mode={run.mode} customer={sc.content.job?.customer ?? null} onMove={onMove} onDone={onDone} onQuit={() => setRun(null)} />
      </div>
    );
  }
  if (finished) return <Debrief eid={eid} sc={sc} f={finished} onAgain={start} back={back} />;

  return (
    <div className="sw-in mx-auto max-w-2xl space-y-4">
      {back}
      <div>
        <h1 className="text-[24px] font-bold text-ink">{sc.title}</h1>
        {sc.summary && <p className="mt-1 text-[15px] text-muted">{sc.summary}</p>}
      </div>
      <div className="grid gap-3 sm:grid-cols-2">
        {(['practice', 'job'] as const).map((mode) => {
          const r = sc.results[mode];
          return (
            <Card key={mode} className="flex flex-col p-5">
              <div className="flex items-center gap-2">
                {mode === 'practice' ? <Dumbbell className="size-5 text-brand" /> : <Briefcase className="size-5 text-brand" />}
                <h2 className="text-[17px] font-bold text-ink">{t(mode === 'practice' ? 'sim.practice' : 'sim.job')}</h2>
                {r?.passed && <Badge tone="success"><Check className="size-3" />{t('sim.passed')}</Badge>}
              </div>
              <p className="mt-2 flex-1 text-[14px] text-muted">{t(mode === 'practice' ? 'sim.practiceBody' : 'sim.jobBody')}</p>
              {r && <p className="mt-2 text-[13px] text-muted">{t('sim.best', { score: r.best, n: r.tries })}</p>}
              <Button className="mt-4" size="lg" variant={mode === 'practice' && !sc.results.practice?.passed ? 'primary' : mode === 'job' && sc.results.practice?.passed ? 'primary' : 'secondary'} onClick={() => start(mode)}>{t('sim.start')}</Button>
            </Card>
          );
        })}
      </div>
    </div>
  );
}

/** How it went: the result, each step with mistakes marked, why it matters, and the lesson that covers it. */
function Debrief({ eid, sc, f, onAgain, back }: { eid: string; sc: LearnScenario; f: Finished; onAgain: (m: SimMode) => void; back: ReactNode }) {
  const { t } = useI18n();
  const { score } = f;
  const verdict = score.passed ? (f.mode === 'job' ? 'sim.passedJob' : 'sim.passedPractice') : f.mode === 'job' && score.critical ? 'sim.criticalFail' : 'sim.notPassed';
  const firstAt: Record<string, number> = {};
  for (const e of f.state.events) firstAt[e.step] ??= e.t;
  return (
    <div className="sw-in mx-auto max-w-2xl space-y-4">
      {back}
      <Card className="p-6 text-center">
        <p className="text-[13px] font-semibold uppercase tracking-wide text-muted">{t('sim.debrief')}</p>
        <p className={cx('mt-1 text-[40px] font-bold tabular-nums', score.passed ? 'text-[#0B7A5C]' : 'text-ink')}>{t('sim.score', { score: score.score })}</p>
        <p className="mt-1 flex items-center justify-center gap-1.5 text-[15px] font-semibold text-ink">{score.passed ? <Check className="size-5 text-[#0B7A5C]" /> : score.critical ? <ShieldAlert className="size-5 text-[#C92A2F]" /> : <CircleAlert className="size-5 text-[#B45309]" />}{t(verdict)}</p>
        <p className="mt-2 flex items-center justify-center gap-1.5 text-[13px] text-muted">{f.synced ? <Wifi className="size-4" /> : <WifiOff className="size-4" />}{t(f.synced ? 'lp.synced' : 'lp.savedOffline')}</p>
        <div className="mt-5 flex flex-wrap justify-center gap-2">
          <Button icon={<RotateCcw className="size-4" />} variant={score.passed && f.mode === 'practice' ? 'secondary' : 'primary'} onClick={() => onAgain(f.mode)}>{t('sim.again')}</Button>
          {score.passed && f.mode === 'practice' && <Button icon={<Briefcase className="size-4" />} onClick={() => onAgain('job')}>{t('sim.asJob')}</Button>}
        </div>
      </Card>

      <Card className="p-0">
        <h2 className="border-b border-line px-5 py-3 text-[15px] font-bold text-ink">{t('sim.replay')}</h2>
        <ol>
          {f.state.steps.map((st, i) => {
            const r = score.steps[st.id] ?? { mistakes: 0, hints: 0, done: false };
            const clean = r.done && r.mistakes === 0 && r.hints === 0;
            const lessonId = st.lesson ? sc.lessons[st.lesson.code] : undefined;
            const at = firstAt[st.id];
            return (
              <li key={st.id} className="flex gap-3 border-b border-line px-5 py-3 last:border-0">
                <span className={cx('mt-0.5 grid size-7 shrink-0 place-items-center rounded-full text-white', r.mistakes ? 'bg-[#C92A2F]' : r.hints ? 'bg-[#B45309]' : 'bg-[#0B7A5C]')}>{r.mistakes ? <X className="size-4" /> : r.hints ? <Lightbulb className="size-4" /> : <Check className="size-4" />}</span>
                <div className="min-w-0 flex-1">
                  <p className="text-[14px] font-semibold text-ink">{`${i + 1}. ${st.prompt}`}</p>
                  <p className="text-[12px] text-muted">
                    {clean ? t('sim.clean') : [r.mistakes ? t('sim.mistakes', { n: r.mistakes }) : '', r.hints ? t('sim.hintsUsed', { n: r.hints }) : ''].filter(Boolean).join(' · ')}
                    {at !== undefined && ` · ${t('sim.at', { time: `${Math.floor(at / 60)}:${String(at % 60).padStart(2, '0')}` })}`}
                  </p>
                  {!clean && st.why && <p className="mt-1.5 text-[14px] text-ink"><b>{t('sim.whyMatters')}: </b>{st.why}</p>}
                  {!clean && lessonId && <Link to={`/learn/enrolments/${eid}/lessons/${lessonId}${st.lesson?.block ? `#${st.lesson.block}` : ''}`} className="mt-1 inline-flex items-center gap-1 text-[13px] font-semibold text-brand hover:underline"><BookOpen className="size-4" />{t('sim.reviewLesson')}</Link>}
                </div>
              </li>
            );
          })}
        </ol>
      </Card>
    </div>
  );
}
