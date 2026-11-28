import { useState } from 'react';
import { Link, useParams } from 'react-router';
import { useMutation, useQuery } from '@tanstack/react-query';
import { ArrowLeft, BellRing, BookOpen, Box, Check, Clock, ListChecks } from 'lucide-react';
import type { ChecklistArea, CohortBoard } from '@simuwork/core';
import { Checklist } from '../../components/progress/Checklist';
import { Badge, Card, cx, Drawer, Skeleton, useToast } from '../../components/ui';
import { can, useSession } from '../../lib/session';
import { api } from '../../lib/api';
import { useI18n } from '../../lib/i18n';

const DAY = 86_400_000;
const daysSince = (iso: string, now: number) => Math.floor((now - new Date(iso).getTime()) / DAY);
const loadedAt = () => Date.now();

/** Facilitator: who is where in the cohort. Quiet learners (7+ days) come first. */
export default function CohortProgress() {
  const { id = '' } = useParams();
  const { t } = useI18n();
  const q = useQuery({ queryKey: ['cohort-progress', id], queryFn: () => api<{ data: CohortBoard }>(`/cohorts/${id}/progress`) });
  const [open, setOpen] = useState<{ id: string; name: string } | null>(null);
  const [now] = useState(loadedAt);
  const toast = useToast();
  const { data: session } = useSession();
  const nudge = useMutation({
    mutationFn: (eid: string) => api(`/enrolments/${eid}/nudge`, { method: 'POST' }),
    onSuccess: () => toast(t('pg.nudged')),
    onError: () => toast(t('pg.nudgeFailed'), 'error'),
  });
  const b = q.data?.data;

  return (
    <div className="sw-in">
      <Link to={`/admin/cohorts/${id}`} className="mb-3 inline-flex items-center gap-1.5 text-[13px] font-semibold text-muted hover:text-ink"><ArrowLeft className="size-4" />{t('pg.back')}</Link>
      <h1 className="text-[26px] font-bold tracking-tight text-ink">{t('pg.title')}</h1>
      {!b ? <Skeleton className="mt-4 h-80 rounded-2xl" /> : (
        <>
          <div className="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
            {([['pg.learners', b.summary.learners], ['pg.average', `${b.summary.average}%`], ['pg.atRisk', b.summary.at_risk], ['pg.jobReady', b.summary.job_ready]] as const).map(([k, v]) => (
              <Card key={k} className={cx('p-4', k === 'pg.atRisk' && b.summary.at_risk > 0 && 'border-[#F5C26B] bg-[#FFF7E6]')}><p className="text-[12px] font-semibold text-muted">{t(k)}</p><p className="mt-1 text-[24px] font-bold tabular-nums text-ink">{v}</p></Card>
            ))}
          </div>
          <Card className="mt-4 overflow-x-auto p-0">
            <table className="w-full min-w-[720px] border-collapse text-[13px]">
              <thead>
                <tr className="border-b border-line text-left">
                  <th className="sticky left-0 bg-surface px-4 py-3 font-semibold text-muted">{t('pg.learner')}</th>
                  <th className="px-3 py-3 font-semibold text-muted">{t('pg.progress')}</th>
                  {b.activities.map((a) => (
                    <th key={a.id} className="px-2 py-3 text-center font-semibold text-muted" title={a.title}>
                      <span className="inline-flex items-center gap-1">{a.kind === 'lesson' ? <BookOpen className="size-3.5" /> : <Box className="size-3.5" />}{a.code.split('-').pop()}</span>
                    </th>
                  ))}
                  <th className="px-3 py-3 font-semibold text-muted">{t('pg.jobReadyCol')}</th>
                </tr>
              </thead>
              <tbody>
                {b.learners.map((l) => (
                  <tr key={l.id} className="border-b border-line last:border-0">
                    <td className="sticky left-0 bg-surface px-4 py-2.5">
                      <p className="font-semibold text-ink">{l.name}</p>
                      <p className="flex items-center gap-1 text-[12px] text-muted">
                        {l.at_risk && <Badge tone="warning"><Clock className="size-3" />{t('pg.quiet')}</Badge>}
                        {l.at_risk && can(session, 'classes.manage') && <button type="button" onClick={() => nudge.mutate(l.id)} className="inline-flex items-center gap-1 rounded-md px-1.5 font-semibold text-brand hover:bg-surface-2"><BellRing className="size-3" />{t('pg.nudge')}</button>}
                        {l.last_activity_at ? t('pg.lastActive', { n: daysSince(l.last_activity_at, now) }) : t('pg.neverActive')}
                      </p>
                    </td>
                    <td className="px-3 py-2.5"><span className="flex items-center gap-2"><span className="h-1.5 w-14 overflow-hidden rounded-full bg-line"><span className="block h-full bg-brand" style={{ width: `${l.progress}%` }} /></span><span className="tabular-nums text-ink">{l.progress}%</span></span></td>
                    {b.activities.map((a) => {
                      const c = l.cells[a.id];
                      return (
                        <td key={a.id} className="px-2 py-2.5 text-center">
                          {!c ? <span className="text-muted">·</span> : c.status === 'done'
                            ? <span title={t('pg.cellDone', { best: c.best ?? '—', tries: c.tries })} className={cx('inline-flex size-7 items-center justify-center rounded-full text-[11px] font-bold text-white', c.job_passed ? 'bg-[#0B7A5C]' : 'bg-brand')}>{c.job_passed ? <Check className="size-4" /> : c.best ?? '✓'}</span>
                            : <span title={t('pg.cellStarted', { tries: c.tries })} className="inline-block size-7 rounded-full border-2 border-dashed border-brand" />}
                        </td>
                      );
                    })}
                    <td className="px-3 py-2.5">
                      <button type="button" onClick={() => setOpen({ id: l.id, name: l.name })} className="inline-flex h-8 items-center gap-1.5 rounded-lg px-2 font-semibold text-brand hover:bg-surface-2"><ListChecks className="size-4" />{`${l.job_ready.shown}/${l.job_ready.of}`}</button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </Card>
          <p className="mt-3 text-[12px] text-muted">{t('pg.legend')}</p>
        </>
      )}
      <Pilot id={id} />
      {open && <ChecklistDrawer id={open.id} name={open.name} onClose={() => setOpen(null)} />}
    </div>
  );
}

function ChecklistDrawer({ id, name, onClose }: { id: string; name: string; onClose: () => void }) {
  const { t } = useI18n();
  const q = useQuery({ queryKey: ['checklist', id], queryFn: () => api<{ data: { areas: ChecklistArea[] } }>(`/enrolments/${id}/checklist`) });
  return <Drawer open onClose={onClose} title={t('jr.title')} subtitle={name}>{q.data ? <Checklist areas={q.data.data.areas} /> : <Skeleton className="h-64" />}</Drawer>;
}

type PilotData = {
  first_simulator: { code: string; title: string; learners: number; started: number; completed: number; completion: number; median_minutes: number | null; median_tries: number | null } | null;
  help: { per_run: number; runs: number; top_steps: { activity: string; step: string; prompt: string; asks: number }[] };
  data: { learners_offline: number; avg_mb: number };
  rating: { count: number; easy: number | null; real: number | null; ready: number | null };
};

/** Pilot measures for this class: what we check before producing content for the other programmes. */
function Pilot({ id }: { id: string }) {
  const { t } = useI18n();
  const q = useQuery({ queryKey: ['pilot', id], queryFn: () => api<{ data: PilotData }>(`/cohorts/${id}/pilot`) });
  const p = q.data?.data;
  if (!p) return null;
  const f = p.first_simulator;
  const tile = (label: string, value: string, sub?: string) => (
    <Card key={label} className="p-4"><p className="text-[12px] font-semibold text-muted">{label}</p><p className="mt-1 text-[22px] font-bold tabular-nums text-ink">{value}</p>{sub && <p className="text-[12px] text-muted">{sub}</p>}</Card>
  );
  return (
    <section className="mt-8">
      <h2 className="text-[18px] font-bold text-ink">{t('pg.pilot')}</h2>
      <p className="mt-1 text-[13px] text-muted">{t('pg.pilotLead')}</p>
      <div className="mt-3 grid grid-cols-2 gap-3 lg:grid-cols-4">
        {tile(t('pg.p.completion'), f ? `${f.completion}%` : '—', f ? t('pg.p.completionSub', { done: f.completed, n: f.learners, code: f.code }) : undefined)}
        {tile(t('pg.p.time'), f?.median_minutes != null ? t('pg.p.minutes', { n: f.median_minutes }) : '—', f?.median_tries != null ? t('pg.p.tries', { n: f.median_tries }) : undefined)}
        {tile(t('pg.p.help'), String(p.help.per_run), t('pg.p.helpSub', { n: p.help.runs }))}
        {tile(t('pg.p.data'), p.data.learners_offline ? `${p.data.avg_mb} MB` : '—', t('pg.p.dataSub', { n: p.data.learners_offline }))}
      </div>
      <div className="mt-3 grid gap-3 lg:grid-cols-2">
        <Card className="p-4">
          <p className="text-[13px] font-bold text-ink">{t('pg.p.rating', { n: p.rating.count })}</p>
          {(['easy', 'real', 'ready'] as const).map((k) => <p key={k} className="mt-1.5 flex justify-between text-[13px]"><span className="text-muted">{t(`sim.rate.${k}`)}</span><span className="font-semibold tabular-nums text-ink">{p.rating[k] ?? '—'} / 5</span></p>)}
        </Card>
        <Card className="p-4">
          <p className="text-[13px] font-bold text-ink">{t('pg.p.topSteps')}</p>
          {p.help.top_steps.length === 0 ? <p className="mt-1.5 text-[13px] text-muted">{t('pg.p.noAsks')}</p> : p.help.top_steps.map((s) => <p key={`${s.activity}${s.step}`} className="mt-1.5 flex justify-between gap-3 text-[13px]"><span className="text-ink">{`${s.activity} · ${s.prompt}`}</span><span className="shrink-0 font-semibold tabular-nums text-muted">{s.asks}×</span></p>)}
        </Card>
      </div>
    </section>
  );
}
