import { useMemo, useState } from 'react';
import { Link, useParams } from 'react-router';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, ArrowRightLeft, Printer, RefreshCw, Search, UserPlus } from 'lucide-react';
import type { CohortDetail as Detail, CohortItem, EnrolmentItem, EnrolmentStatus, LearnerItem } from '@simuwork/core';
import type { MessageKey } from '@simuwork/core/locales/en';
import { QrCode } from '../../components/cohorts/QrCode';
import { Badge, Button, Card, cx, Drawer, inputClass, Skeleton, Switch, useToast } from '../../components/ui';
import { api, ApiError } from '../../lib/api';
import { useI18n } from '../../lib/i18n';
import { can, useSession } from '../../lib/session';

const TONE: Record<EnrolmentStatus, 'success' | 'warning' | 'neutral' | 'danger'> = { enrolled: 'warning', active: 'success', completed: 'success', withdrawn: 'neutral', no_show: 'neutral' };
const firstError = (e: unknown, fallback: string) => (e instanceof ApiError ? Object.values(e.errors)[0]?.[0] ?? e.message : fallback);

/** Provider: one cohort — its QR join code, learners, seats, and settings. */
export default function CohortDetail() {
  const { id = '' } = useParams();
  const { t, locale } = useI18n();
  const toast = useToast();
  const qc = useQueryClient();
  const { data: session } = useSession();
  const manage = can(session, 'classes.manage');
  const enrolRight = can(session, 'learners.manage');
  const [adding, setAdding] = useState(false);
  const [moving, setMoving] = useState<EnrolmentItem | null>(null);
  const cohort = useQuery({ queryKey: ['cohort', id], queryFn: () => api<{ data: Detail }>(`/cohorts/${id}`) });
  const refresh = () => { void qc.invalidateQueries({ queryKey: ['cohort', id] }); void qc.invalidateQueries({ queryKey: ['cohorts'] }); };
  const date = (d: string) => new Date(d).toLocaleDateString(locale, { day: 'numeric', month: 'short', year: 'numeric' });

  const patch = useMutation({
    mutationFn: (body: Record<string, unknown>) => api(`/cohorts/${id}`, { method: 'PATCH', body }),
    onSuccess: refresh,
    onError: (e) => toast(firstError(e, t('common.error')), 'error'),
  });
  const action = useMutation({
    mutationFn: (path: string) => api(`/cohorts/${id}/${path}`, { method: 'POST' }),
    onSuccess: refresh,
    onError: (e) => toast(firstError(e, t('common.error')), 'error'),
  });
  const withdraw = useMutation({
    mutationFn: ({ e, reason }: { e: string; reason: string }) => api<{ data: EnrolmentItem }>(`/enrolments/${e}/withdraw`, { method: 'POST', body: { reason } }),
    onSuccess: (r) => { refresh(); toast(t(r.data.holds_seat ? 'coh.withdrawnKept' : 'coh.withdrawnBack')); },
    onError: (e) => toast(firstError(e, t('common.error')), 'error'),
  });

  const c = cohort.data?.data;
  if (!c) return <div className="space-y-4"><Skeleton className="h-10 w-72" /><Skeleton className="h-64 rounded-2xl" /></div>;
  const joinUrl = `${window.location.origin}/learn/join/${c.join_code}`;
  const current = c.enrolments.filter((e) => e.status === 'enrolled' || e.status === 'active');
  const past = c.enrolments.filter((e) => !current.includes(e));

  return (
    <div className="sw-in">
      <Link to="/admin/cohorts" className="mb-3 inline-flex items-center gap-1.5 text-[13px] font-semibold text-muted hover:text-ink"><ArrowLeft className="size-4" />{t('coh.title')}</Link>
      <div className="mb-6 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <p className="text-[12px] font-semibold uppercase tracking-wide text-muted">{c.programme.title} · {t('coh.version', { n: c.version })}</p>
          <h1 className="mt-1 text-[28px] font-bold tracking-tight text-ink">{c.name}</h1>
          <p className="mt-1 text-[14px] text-muted">{c.centre.name} · {date(c.starts_on)}{c.ends_on ? ` – ${date(c.ends_on)}` : ''}{c.facilitators.length > 0 && ` · ${c.facilitators.map((f) => f.name).join(', ')}`}</p>
        </div>
        {enrolRight && !c.archived && <Button icon={<UserPlus className="size-4" />} onClick={() => setAdding(true)}>{t('coh.addLearners')}</Button>}
      </div>

      <div className="grid gap-5 lg:grid-cols-[1fr_320px]">
        <Card className="overflow-hidden p-0">
          <div className="flex items-center justify-between border-b border-line px-5 py-4">
            <h2 className="text-[15px] font-bold text-ink">{t('coh.learners', { n: current.length })}</h2>
            <span className="text-[13px] text-muted">{t('coh.freeSeats', { n: c.free_seats })}</span>
          </div>
          {current.length === 0 ? <p className="px-5 py-10 text-center text-[14px] text-muted">{t('coh.noLearners')}</p> : (
            <ul className="divide-y divide-line">
              {current.map((e) => (
                <li key={e.id} className="flex flex-wrap items-center gap-3 px-5 py-3">
                  <div className="min-w-0 flex-1 basis-full sm:basis-0">
                    <p className="font-semibold text-ink">{e.learner.name}</p>
                    <p className="text-[12px] text-muted">{e.seat_returns_on ? t('coh.notStarted', { date: date(e.seat_returns_on) }) : t('coh.progress', { n: e.progress })}</p>
                  </div>
                  <Badge tone={TONE[e.status]}>{t(`coh.status.${e.status}` as MessageKey)}</Badge>
                  {enrolRight && (
                    <div className="flex gap-1">
                      <Button size="sm" variant="ghost" icon={<ArrowRightLeft className="size-4" />} onClick={() => setMoving(e)}>{t('coh.transfer')}</Button>
                      <Button size="sm" variant="ghost" onClick={() => { const reason = window.prompt(t('coh.withdrawWhy', { name: e.learner.name })); if (reason?.trim()) withdraw.mutate({ e: e.id, reason: reason.trim() }); }}>{t('coh.withdraw')}</Button>
                    </div>
                  )}
                </li>
              ))}
            </ul>
          )}
          {past.length > 0 && (
            <details className="border-t border-line px-5 py-3">
              <summary className="cursor-pointer text-[13px] font-semibold text-muted">{t('coh.past', { n: past.length })}</summary>
              <ul className="mt-2 space-y-1.5 text-[13px]">{past.map((e) => <li key={e.id} className="flex justify-between gap-3"><span>{e.learner.name}</span><span className="text-muted">{t(`coh.status.${e.status}` as MessageKey)}{e.reason ? ` · ${e.reason}` : ''}{e.holds_seat ? '' : ` · ${t('coh.seatBack')}`}</span></li>)}</ul>
            </details>
          )}
        </Card>

        <div className="space-y-5">
          <Card className="p-5 text-center print:shadow-none">
            <h2 className="text-[15px] font-bold text-ink">{t('coh.joinTitle')}</h2>
            <p className="mt-1 text-[13px] text-muted">{t('coh.joinLead')}</p>
            <div className={cx('mx-auto mt-4 w-fit', !c.join_open && 'opacity-30')}><QrCode text={joinUrl} label={t('coh.qrLabel', { code: c.join_code })} /></div>
            <p className="mt-3 font-mono text-[22px] font-bold tracking-[0.2em] text-ink">{c.join_code}</p>
            {manage && !c.archived && (
              <div className="mt-4 space-y-3 text-left">
                <label className="flex items-center justify-between gap-3 text-[14px] font-medium text-ink">{t('coh.joinOpenLabel')}<Switch checked={c.join_open} onChange={(v) => patch.mutate({ join_open: v })} label={t('coh.joinOpenLabel')} /></label>
                <div className="flex gap-2">
                  <Button size="sm" variant="secondary" icon={<Printer className="size-4" />} onClick={() => window.print()}>{t('coh.print')}</Button>
                  <Button size="sm" variant="secondary" icon={<RefreshCw className="size-4" />} loading={action.isPending} onClick={() => window.confirm(t('coh.newCodeConfirm')) && action.mutate('join-code')}>{t('coh.newCode')}</Button>
                </div>
              </div>
            )}
          </Card>
          {manage && !c.archived && (
            <Card className="p-5">
              <h2 className="text-[15px] font-bold text-ink">{t('coh.archiveTitle')}</h2>
              <p className="mt-1 text-[13px] text-muted">{t('coh.archiveLead')}</p>
              <Button className="mt-3" size="sm" variant="secondary" disabled={current.length > 0} onClick={() => window.confirm(t('coh.archiveConfirm')) && action.mutate('archive')}>{t('coh.archive')}</Button>
            </Card>
          )}
        </div>
      </div>

      <AddLearners cohort={c} open={adding} onClose={() => setAdding(false)} onDone={refresh} />
      <Transfer cohort={c} enrolment={moving} onClose={() => setMoving(null)} onDone={refresh} />
    </div>
  );
}

function AddLearners({ cohort, open, onClose, onDone }: { cohort: Detail; open: boolean; onClose: () => void; onDone: () => void }) {
  const { t } = useI18n();
  const toast = useToast();
  const [q, setQ] = useState('');
  const [picked, setPicked] = useState<string[]>([]);
  const learners = useQuery({ queryKey: ['learners'], queryFn: () => api<{ data: LearnerItem[] }>('/learners'), enabled: open });
  const inCohort = useMemo(() => new Set(cohort.enrolments.filter((e) => e.status === 'enrolled' || e.status === 'active').map((e) => e.learner.id)), [cohort]);
  const shown = (learners.data?.data ?? []).filter((l) => l.status !== 'inactive' && !inCohort.has(l.id) && l.name.toLowerCase().includes(q.trim().toLowerCase()));
  const close = () => { setPicked([]); setQ(''); onClose(); };
  const over = picked.length > cohort.free_seats;

  const enrol = useMutation({
    mutationFn: () => api<{ data: { enrolled: number; skipped: { name: string; reason: string }[] } }>(`/cohorts/${cohort.id}/enrolments`, { method: 'POST', body: { learner_ids: picked } }),
    onSuccess: (r) => {
      onDone();
      toast(t('coh.enrolled', { n: r.data.enrolled }));
      r.data.skipped.forEach((s) => toast(`${s.name}: ${s.reason}`, 'error'));
      close();
    },
    onError: (e) => toast(firstError(e, t('common.error')), 'error'),
  });

  return (
    <Drawer open={open} onClose={close} title={t('coh.addLearners')} subtitle={t('coh.addLead', { n: cohort.free_seats })}
      footer={<div className="flex items-center justify-between gap-3"><span className={cx('text-[13px] font-medium', over ? 'text-[#C92A2F]' : 'text-muted')}>{t('coh.picked', { n: picked.length, free: cohort.free_seats })}</span><Button disabled={picked.length === 0 || over} loading={enrol.isPending} onClick={() => enrol.mutate()}>{t('coh.enrolN', { n: picked.length })}</Button></div>}>
      <label className="relative mb-3 block">
        <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted" />
        <span className="sr-only">{t('lrn.search')}</span>
        <input value={q} onChange={(e) => setQ(e.target.value)} placeholder={t('lrn.search')} className={cx(inputClass, 'pl-9')} />
      </label>
      {!learners.data ? <Skeleton className="h-48 rounded-xl" /> : shown.length === 0 ? <p className="py-8 text-center text-[14px] text-muted">{t('coh.nobodyToAdd')}</p> : (
        <ul className="divide-y divide-line rounded-xl border border-line">
          {shown.map((l) => (
            <li key={l.id}>
              <label className="flex cursor-pointer items-center gap-3 px-3 py-2.5 hover:bg-surface-2">
                <input type="checkbox" className="size-4 accent-[var(--brand)]" checked={picked.includes(l.id)} onChange={(e) => setPicked((x) => (e.target.checked ? [...x, l.id] : x.filter((i) => i !== l.id)))} />
                <span className="flex-1 font-medium text-ink">{l.name}</span>
                <span className="font-mono text-[12px] text-muted">{l.id_masked.slice(-4)}</span>
              </label>
            </li>
          ))}
        </ul>
      )}
    </Drawer>
  );
}

function Transfer({ cohort, enrolment, onClose, onDone }: { cohort: Detail; enrolment: EnrolmentItem | null; onClose: () => void; onDone: () => void }) {
  const { t } = useI18n();
  const toast = useToast();
  const [to, setTo] = useState('');
  const others = useQuery({ queryKey: ['cohorts', false], queryFn: () => api<{ data: CohortItem[] }>('/cohorts'), enabled: enrolment !== null });
  const options = (others.data?.data ?? []).filter((c) => c.id !== cohort.id && c.programme.id === cohort.programme.id);
  const move = useMutation({
    mutationFn: () => api(`/enrolments/${enrolment?.id}/transfer`, { method: 'POST', body: { cohort_id: to } }),
    onSuccess: () => { onDone(); toast(t('coh.moved')); setTo(''); onClose(); },
    onError: (e) => toast(firstError(e, t('common.error')), 'error'),
  });
  if (!enrolment) return null;
  return (
    <Drawer open onClose={onClose} title={t('coh.transferTitle', { name: enrolment.learner.name })} subtitle={t('coh.transferLead')}
      footer={<div className="flex justify-end"><Button disabled={!to} loading={move.isPending} onClick={() => move.mutate()}>{t('coh.transfer')}</Button></div>}>
      {options.length === 0 ? <p className="text-[14px] text-muted">{t('coh.noOtherCohort')}</p> : (
        <div className="space-y-2">
          {options.map((c) => (
            <label key={c.id} className={cx('flex cursor-pointer items-center gap-3 rounded-xl border px-4 py-3', to === c.id ? 'border-brand bg-surface-2' : 'border-line')}>
              <input type="radio" name="to" className="accent-[var(--brand)]" checked={to === c.id} onChange={() => setTo(c.id)} />
              <span className="flex-1"><span className="block font-semibold text-ink">{c.name}</span><span className="text-[12px] text-muted">{c.centre.name} · v{c.version}</span></span>
            </label>
          ))}
        </div>
      )}
    </Drawer>
  );
}
