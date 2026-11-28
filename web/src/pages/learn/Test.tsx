import { useCallback, useEffect, useRef, useState, type ReactNode } from 'react';
import { Link, useParams } from 'react-router';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, Camera, CircleCheck, Clock, Hourglass, WifiOff } from 'lucide-react';
import type { LearnAssessmentItem, TestPaper, TestResult } from '@simuwork/core';
import { startCameraCheck } from '../../lib/cameraCheck';
import { Button, Card, cx, inputClass } from '../../components/ui';
import { api } from '../../lib/api';
import { useI18n } from '../../lib/i18n';

type Answers = TestPaper['answers'];
const store = (sid: string) => `sw.test.${sid}`;
const loadLocal = (sid: string): Answers => {
  try { return JSON.parse(localStorage.getItem(store(sid)) ?? '{}') as Answers; } catch { return {}; }
};
const saveLocal = (sid: string, a: Answers) => {
  try { localStorage.setItem(store(sid), JSON.stringify(a)); } catch { /* private mode: the server copy still counts */ }
};
const now = () => Date.now();
const clock = (s: number) => `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;

/**
 * Learner: a knowledge test. Every answer is kept on this device straight away and sent to the server a moment
 * later; if the signal drops, it is sent when the device is back online. The server's clock decides the time.
 */
export default function Test() {
  const { eid = '', tid = '' } = useParams();
  const { t, locale } = useI18n();
  const [paper, setPaper] = useState<TestPaper | null>(null);
  const [result, setResult] = useState<TestResult | null>(null);
  const [consent, setConsent] = useState(false);
  const list = useQuery({ queryKey: ['learn-assessments', eid], queryFn: () => api<{ data: LearnAssessmentItem[] }>(`/learn/enrolments/${eid}/assessments`) });
  const camera = list.data?.data.find((x) => x.id === tid)?.camera ?? false;
  const start = useMutation({
    mutationFn: () => api<{ data: TestPaper }>(`/learn/enrolments/${eid}/tests/${tid}/start`, { method: 'POST', body: { locale, device: 'web', camera_consent: consent } }),
    onSuccess: (r) => setPaper(r.data),
  });
  const back = <Link to={`/learn/enrolments/${eid}/assessments`} className="inline-flex items-center gap-1 text-[14px] font-semibold text-brand hover:underline"><ArrowLeft className="size-4" />{t('as.title')}</Link>;

  if (result) return <Result r={result} back={back} />;
  if (paper) return <Sitting key={paper.id} p={paper} onDone={setResult} />;
  return (
    <div className="sw-in mx-auto max-w-md space-y-4 pt-4">
      {back}
      <Card className="p-6 text-center">
        <h1 className="text-[20px] font-bold text-ink">{t('as.readyTitle')}</h1>
        <ul className="mt-3 space-y-1.5 text-left text-[14px] text-ink">
          <li>• {t('as.rule.time')}</li><li>• {t('as.rule.offline')}</li><li>• {t('as.rule.once')}</li>
        </ul>
        {camera && (
          <div className="mt-4 rounded-xl border border-line p-3 text-left">
            <p className="flex items-center gap-2 text-[14px] font-semibold text-ink"><Camera className="size-4 text-brand" />{t('as.cam.title')}</p>
            <p className="mt-1 text-[13px] text-muted">{t('as.cam.body')}</p>
            <label className="mt-2 flex items-start gap-2 text-[14px] text-ink"><input type="checkbox" className="mt-1 size-4 accent-[var(--brand)]" checked={consent} onChange={(e) => setConsent(e.target.checked)} />{t('as.cam.agree')}</label>
            <p className="mt-2 text-[12px] text-muted">{t('as.cam.decline')}</p>
          </div>
        )}
        {start.isError && <p role="alert" className="mt-3 text-[14px] font-medium text-[#C92A2F]">{start.error instanceof Error ? start.error.message : t('common.error')}</p>}
        <Button className="mt-5 w-full" size="lg" disabled={camera && !consent} loading={start.isPending} onClick={() => start.mutate()}>{t('as.begin')}</Button>
      </Card>
    </div>
  );
}

function Sitting({ p, onDone }: { p: TestPaper; onDone: (r: TestResult) => void }) {
  const { t } = useI18n();
  const qc = useQueryClient();
  const [answers, setAnswers] = useState<Answers>(() => ({ ...p.answers, ...loadLocal(p.id) }));
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
        try { localStorage.removeItem(store(p.id)); } catch { /* ignore */ }
        void qc.invalidateQueries({ queryKey: ['learn-assessments'] });
        onDone(r.data as TestResult);
      }
    } catch {
      setOffline(true); // kept on this device; sent again when the connection is back
    }
  }, [p.id, qc, onDone]);

  const answer = (qid: string, v: number | number[] | string) => {
    const next = { ...latest.current, [qid]: v };
    latest.current = next;
    setAnswers(next);
    saveLocal(p.id, next);
    if (timer.current) clearTimeout(timer.current);
    timer.current = setTimeout(() => void send(false), 1500);
  };

  useEffect(() => {
    if (!p.camera) return;
    return startCameraCheck(Math.ceil(p.seconds_left / 60), (photo) => {
      const form = new FormData();
      form.append('photo', photo, 'photo.jpg');
      void api(`/learn/sittings/${p.id}/photos`, { method: 'POST', body: form }).catch(() => undefined);
    });
  }, [p.camera, p.id, p.seconds_left]);

  useEffect(() => {
    const tick = setInterval(() => {
      const s = Math.max(0, Math.round((ends.current - now()) / 1000));
      setLeft(s);
      if (s === 0) { clearInterval(tick); void send(true); } // time is up: hand in what we have
    }, 1000);
    const online = () => void send(false);
    window.addEventListener('online', online);
    return () => { clearInterval(tick); window.removeEventListener('online', online); };
  }, [send]);

  const done = p.questions.filter((q) => { const a = answers[q.id]; return a !== undefined && a !== '' && !(Array.isArray(a) && a.length === 0); }).length;

  return (
    <div className="sw-in mx-auto max-w-2xl space-y-4">
      <div className="sticky top-0 z-10 -mx-4 flex items-center gap-3 border-b border-line bg-bg/95 px-4 py-3 backdrop-blur">
        <h1 className="min-w-0 flex-1 truncate text-[16px] font-bold text-ink">{p.task.title}</h1>
        <span className="text-[13px] text-muted">{t('as.answered', { done, n: p.questions.length })}</span>
        <span className={cx('flex items-center gap-1 font-semibold tabular-nums', left < 120 ? 'text-[#C92A2F]' : 'text-ink')} aria-live="polite"><Clock className="size-4" />{clock(left)}</span>
      </div>
      {p.camera && <p className="flex items-center gap-2 text-[12px] text-muted"><Camera className="size-3.5" />{t('as.cam.on')}</p>}
      {offline && <p role="status" className="flex items-center gap-2 rounded-xl bg-[#FFF4E5] px-3 py-2 text-[14px] text-[#7A4B00]"><WifiOff className="size-4" />{t('as.offline')}</p>}
      {p.questions.map((q, i) => (
        <Card key={q.id} className="p-5">
          <fieldset>
            <legend className="text-[16px] font-semibold text-ink">{`${i + 1}. ${q.prompt}`} <span className="text-[12px] font-normal text-muted">{t('as.marks', { n: q.marks })}</span></legend>
            {q.type === 'short' ? (
              <textarea aria-label={q.prompt} rows={4} className={cx(inputClass, 'mt-3 h-auto py-2.5')} value={(answers[q.id] as string | undefined) ?? ''} onChange={(e) => answer(q.id, e.target.value)} />
            ) : (
              <div className="mt-3 space-y-2">
                {q.type === 'multi' && <p className="text-[13px] text-muted">{t('as.pickAll')}</p>}
                {q.options.map((o, k) => {
                  const cur = answers[q.id];
                  const on = q.type === 'single' ? cur === k : Array.isArray(cur) && cur.includes(k);
                  return (
                    <label key={k} className={cx('flex min-h-12 cursor-pointer items-center gap-3 rounded-xl border-[1.5px] px-4 py-2.5 text-[15px]', on ? 'border-brand bg-brand-soft' : 'border-line hover:border-brand')}>
                      <input type={q.type === 'single' ? 'radio' : 'checkbox'} name={q.id} className="size-4 accent-[var(--brand)]" checked={on}
                        onChange={() => answer(q.id, q.type === 'single' ? k : (Array.isArray(cur) ? (on ? cur.filter((x) => x !== k) : [...cur, k]) : [k]))} />
                      {o}
                    </label>
                  );
                })}
              </div>
            )}
          </fieldset>
        </Card>
      ))}
      <Button size="lg" className="w-full" onClick={() => window.confirm(t('as.handInConfirm', { done, n: p.questions.length })) && void send(true)}>{t('as.handIn')}</Button>
    </div>
  );
}

function Result({ r, back }: { r: TestResult; back: ReactNode }) {
  const { t } = useI18n();
  return (
    <div className="sw-in mx-auto max-w-md space-y-4 pt-4">
      {back}
      <Card className="p-6 text-center">
        {r.status === 'submitted' ? (
          <><Hourglass className="mx-auto size-10 text-[#B45309]" /><h1 className="mt-3 text-[20px] font-bold text-ink">{t('as.handedIn')}</h1><p className="mt-2 text-[14px] text-muted">{t('as.waitingBody')}</p></>
        ) : (
          <>
            <p className={cx('text-[40px] font-bold tabular-nums', r.result === 'competent' ? 'text-[#0B7A5C]' : 'text-ink')}>{`${r.score ?? 0}%`}</p>
            <p className="mt-1 flex items-center justify-center gap-1.5 text-[16px] font-semibold text-ink">{r.result === 'competent' && <CircleCheck className="size-5 text-[#0B7A5C]" />}{t(r.result === 'competent' ? 'as.competentLong' : 'as.notYetLong')}</p>
            {r.revise.length > 0 && (
              <div className="mt-5 text-left">
                <p className="text-[13px] font-bold text-ink">{t('as.revise')}</p>
                <ul className="mt-1.5 space-y-1 text-[14px] text-ink">{r.revise.map((o) => <li key={o.code}><span className="font-mono text-[12px] text-muted">{o.code}</span> {o.statement}</li>)}</ul>
              </div>
            )}
          </>
        )}
        {r.late && <p className="mt-3 text-[12px] text-muted">{t('as.lateNote')}</p>}
      </Card>
    </div>
  );
}
