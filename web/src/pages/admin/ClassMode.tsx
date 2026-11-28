import { useState } from 'react';
import { Link, useParams } from 'react-router';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, CircleCheck, Hand, Radio, Square, TriangleAlert } from 'lucide-react';
import type { ClassBoard, CohortBoard } from '@simuwork/core';
import { Badge, Button, Card, cx, inputClass, Skeleton, useToast } from '../../components/ui';
import { api } from '../../lib/api';
import { useI18n } from '../../lib/i18n';
import { can, useSession } from '../../lib/session';

/** Facilitator: run one simulator with the whole class and watch each learner's step live. */
export default function ClassMode() {
  const { id = '' } = useParams();
  const { t } = useI18n();
  const toast = useToast();
  const qc = useQueryClient();
  const { data: session } = useSession();
  const run = can(session, 'live_classroom.run');
  const live = useQuery({ queryKey: ['class-live', id], queryFn: () => api<{ data: ClassBoard | null }>(`/cohorts/${id}/class`), refetchInterval: 4000 });
  const board = useQuery({ queryKey: ['cohort-progress', id], queryFn: () => api<{ data: CohortBoard }>(`/cohorts/${id}/progress`) });
  const scenarios = board.data?.data.activities.filter((a) => a.kind === 'scenario') ?? [];
  const [pick, setPick] = useState('');
  const [mode, setMode] = useState<'practice' | 'job'>('practice');
  const refresh = () => void qc.invalidateQueries({ queryKey: ['class-live', id] });
  const start = useMutation({ mutationFn: () => api(`/cohorts/${id}/class`, { method: 'POST', body: { activity_id: pick || scenarios[0]?.id, mode } }), onSuccess: refresh, onError: () => toast(t('common.error'), 'error') });
  const end = useMutation({ mutationFn: (sid: string) => api(`/class-sessions/${sid}/end`, { method: 'POST' }), onSuccess: () => { toast(t('cm.ended')); refresh(); } });
  const b = live.data?.data;

  return (
    <div className="sw-in">
      <Link to={`/admin/cohorts/${id}`} className="mb-3 inline-flex items-center gap-1.5 text-[13px] font-semibold text-muted hover:text-ink"><ArrowLeft className="size-4" />{t('pg.back')}</Link>
      <h1 className="text-[26px] font-bold tracking-tight text-ink">{t('cm.title')}</h1>
      <p className="mt-1 text-[14px] text-muted">{t('cm.lead')}</p>
      {live.isLoading ? <Skeleton className="mt-4 h-64 rounded-2xl" /> : !b ? (
        <Card className="mt-5 max-w-xl space-y-4 p-5">
          {scenarios.length === 0 ? <p className="text-[14px] text-muted">{t('cm.noScenarios')}</p> : (
            <>
              <label className="block"><span className="mb-1.5 block text-[13px] font-semibold text-ink">{t('cm.which')}</span>
                <select className={inputClass} value={pick || scenarios[0]!.id} onChange={(e) => setPick(e.target.value)}>{scenarios.map((s) => <option key={s.id} value={s.id}>{`${s.code} · ${s.title}`}</option>)}</select>
              </label>
              <fieldset className="flex gap-4 text-[14px]">
                <legend className="mb-1.5 text-[13px] font-semibold text-ink">{t('cm.mode')}</legend>
                {(['practice', 'job'] as const).map((m) => <label key={m} className="flex items-center gap-2"><input type="radio" name="mode" className="size-4 accent-[var(--brand)]" checked={mode === m} onChange={() => setMode(m)} />{t(m === 'practice' ? 'sim.practice' : 'sim.job')}</label>)}
              </fieldset>
              {run && <Button size="lg" icon={<Radio className="size-5" />} loading={start.isPending} onClick={() => start.mutate()}>{t('cm.start')}</Button>}
            </>
          )}
        </Card>
      ) : (
        <>
          <div className="mt-4 flex flex-wrap items-center gap-3">
            <Badge tone="success"><Radio className="size-3 animate-pulse" />{t('cm.liveNow')}</Badge>
            <span className="font-semibold text-ink">{`${b.session.code} · ${b.session.title}`}</span>
            <span className="text-[13px] text-muted">{t(b.session.mode === 'job' ? 'sim.job' : 'sim.practice')}</span>
            {run && <Button className="ml-auto" variant="secondary" icon={<Square className="size-4" />} onClick={() => end.mutate(b.session.id)}>{t('cm.end')}</Button>}
          </div>
          <div className="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
            {([['cm.joined', `${b.summary.joined}/${b.summary.learners}`], ['cm.finished', b.summary.finished], ['cm.stuck', b.summary.stuck]] as const).map(([k, v]) => (
              <Card key={k} className={cx('p-4', k === 'cm.stuck' && b.summary.stuck > 0 && 'border-[#F5C26B] bg-[#FFF7E6]')}><p className="text-[12px] font-semibold text-muted">{t(k)}</p><p className="mt-1 text-[24px] font-bold tabular-nums text-ink">{v}</p></Card>
            ))}
          </div>
          <Card className="mt-4 p-0">
            <ul className="divide-y divide-line">
              {b.learners.map((l) => (
                <li key={l.id} className="flex flex-wrap items-center gap-3 px-5 py-3">
                  <span className="min-w-40 flex-1 font-semibold text-ink">{l.name}</span>
                  {!l.joined ? <span className="text-[13px] text-muted">{t('cm.notJoined')}</span> : (
                    <>
                      <span className="flex w-48 items-center gap-2 text-[13px] text-ink"><span className="h-2 flex-1 overflow-hidden rounded-full bg-line"><span className={cx('block h-full', l.finished ? 'bg-[#0B7A5C]' : 'bg-brand')} style={{ width: `${(100 * (l.finished ? l.steps : l.step - 1)) / Math.max(1, l.steps)}%` }} /></span>{t('cm.step', { n: l.step, total: l.steps })}</span>
                      <span className="text-[12px] text-muted">{t('cm.counts', { mistakes: l.mistakes, help: l.help })}</span>
                      {l.finished ? <Badge tone={l.passed ? 'success' : 'neutral'}><CircleCheck className="size-3" />{`${l.score ?? 0}%`}</Badge>
                        : l.stuck ? <Badge tone="warning"><TriangleAlert className="size-3" />{t('cm.stuckBadge')}</Badge>
                        : <Badge tone="brand"><Hand className="size-3" />{t('cm.working')}</Badge>}
                    </>
                  )}
                </li>
              ))}
            </ul>
          </Card>
        </>
      )}
    </div>
  );
}
