import { useState } from 'react';
import { Link } from 'react-router';
import { useQuery } from '@tanstack/react-query';
import { Camera, Inbox } from 'lucide-react';
import type { AssessQueue, CohortItem } from '@simuwork/core';
import { Badge, Card, cx, EmptyState, inputClass, PageHeader, Skeleton } from '../../components/ui';
import { api } from '../../lib/api';
import { useI18n } from '../../lib/i18n';

type Tab = 'mark' | 'runs' | 'observe' | 'done';

/** The assessor's one queue (Sprint 5.2): written answers to mark, real-job runs to judge, observations to do. */
export default function AssessQueuePage() {
  const { t, locale } = useI18n();
  const [tab, setTab] = useState<Tab>('runs');
  const [cohort, setCohort] = useState('');
  const cohorts = useQuery({ queryKey: ['cohorts', false], queryFn: () => api<{ data: CohortItem[] }>('/cohorts') });
  const q = useQuery({ queryKey: ['assess-queue', cohort], queryFn: () => api<{ data: AssessQueue }>(`/assess/queue${cohort ? `?cohort=${cohort}` : ''}`) });
  const data = q.data?.data;
  const date = (s?: string | null) => (s ? new Date(s).toLocaleDateString(locale, { day: 'numeric', month: 'short' }) : '');
  const count = (k: Tab) => (data ? data[k].length : 0);

  const rows = (() => {
    if (!data) return null;
    if (tab === 'mark') return data.mark.map((r) => ({ id: r.id, to: `/assess/sittings/${r.id}`, who: r.learner, cohort: r.cohort, task: r.task.title, at: date(r.at),
      tags: <>{r.late && <Badge tone="warning">{t('rv.late')}</Badge>}{r.camera && <Camera aria-label={t('rv.camera')} className="size-4 text-muted" />}</> }));
    if (tab === 'runs') return data.runs.map((r) => ({ id: r.id, to: `/assess/runs/${r.id}`, who: r.learner, cohort: r.cohort, task: r.task.title, at: date(r.at),
      tags: <><Badge tone={r.passed ? 'success' : 'warning'}>{t('rv.score', { n: r.score ?? 0 })}</Badge>{r.critical && <Badge tone="danger">{t('rv.critical')}</Badge>}<span className="text-[12px] text-muted">{t('rv.round', { n: r.round, of: r.rounds_allowed })}</span></> }));
    if (tab === 'observe') return data.observe.map((r) => ({ id: r.id, to: `/assess/observe/${r.enrolment_id}/${r.task.id}`, who: r.learner, cohort: r.cohort, task: r.task.title, at: '',
      tags: <>{r.ready ? <Badge tone="success">{t('rv.ready')}</Badge> : <Badge>{t('rv.notReady')}</Badge>}<span className="text-[12px] text-muted">{t('rv.round', { n: r.round, of: r.rounds_allowed })}</span></> }));
    return data.done.map((r) => ({ id: r.id, to: null, who: r.learner, cohort: r.cohort, task: r.task.title, at: date(r.at),
      tags: <><Badge tone={r.result === 'competent' ? 'success' : 'warning'}>{t(r.result === 'competent' ? 'rv.competent' : 'rv.notYet')}</Badge><span className="text-[12px] text-muted">{r.assessor}</span></> }));
  })();

  return (
    <div className="sw-in max-w-5xl">
      <PageHeader title={t('rv.title')} lead={t('rv.lead')} actions={
        <select aria-label={t('rv.cohort')} className={cx(inputClass, 'h-10 w-56')} value={cohort} onChange={(e) => setCohort(e.target.value)}>
          <option value="">{t('rv.allCohorts')}</option>
          {cohorts.data?.data.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
        </select>
      } />
      <div role="tablist" className="mb-4 inline-flex flex-wrap rounded-xl bg-surface-2 p-1">
        {(['runs', 'observe', 'mark', 'done'] as Tab[]).map((k) => (
          <button key={k} role="tab" aria-selected={tab === k} onClick={() => setTab(k)} className={cx('h-9 rounded-lg px-4 text-[13px] font-semibold transition', tab === k ? 'bg-surface text-ink shadow-sm' : 'text-muted hover:text-ink')}>
            {t(`rv.tab.${k}`)}{k !== 'done' && data ? ` (${count(k)})` : ''}
          </button>
        ))}
      </div>
      {rows === null ? <Skeleton className="h-64" /> : rows.length === 0 ? (
        <EmptyState icon={<Inbox className="size-6" />} title={t('rv.empty')} body={t(`rv.empty.${tab}`)} />
      ) : (
        <Card className="divide-y divide-line">
          {rows.map((r) => {
            const body = (
              <div className="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3">
                <div className="min-w-0 flex-1"><p className="truncate font-semibold text-ink">{r.who}</p><p className="truncate text-[13px] text-muted">{r.task} · {r.cohort}</p></div>
                <div className="flex items-center gap-2">{r.tags}</div>
                {r.at && <span className="w-16 text-right text-[12px] text-muted">{r.at}</span>}
              </div>
            );
            return r.to ? <Link key={r.id} to={r.to} className="block hover:bg-surface-2">{body}</Link> : <div key={r.id}>{body}</div>;
          })}
        </Card>
      )}
    </div>
  );
}
