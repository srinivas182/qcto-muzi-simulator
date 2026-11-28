import { Link, useParams } from 'react-router';
import { useQuery } from '@tanstack/react-query';
import { ArrowLeft, BookOpen, Box, CheckCircle2, CircleDot, Circle, ClipboardCheck, ListChecks } from 'lucide-react';
import type { LearnActivity } from '@simuwork/core';
import { Card, cx, Skeleton } from '../../components/ui';
import { api } from '../../lib/api';
import { useI18n } from '../../lib/i18n';

/** Learner: everything in my programme, in order, with what I have done. */
export default function Programme() {
  const { eid = '' } = useParams();
  const { t } = useI18n();
  const data = useQuery({ queryKey: ['learn-activities', eid], queryFn: () => api<{ data: { progress: number; activities: LearnActivity[] } }>(`/learn/enrolments/${eid}/activities`) });
  const d = data.data?.data;

  return (
    <div className="sw-in mx-auto max-w-2xl">
      <Link to="/learn" className="mb-3 inline-flex items-center gap-1.5 text-[13px] font-semibold text-muted hover:text-ink"><ArrowLeft className="size-4" />{t('lh.back')}</Link>
      <h1 className="text-[26px] font-bold tracking-tight text-ink">{t('lp.title')}</h1>
      {!d ? <Skeleton className="mt-6 h-72 rounded-2xl" /> : (
        <>
          <div className="mt-4" aria-label={t('lh.progress')}>
            <div className="flex justify-between text-[13px]"><span className="font-semibold text-ink">{t('lh.progress')}</span><span className="text-muted">{d.progress}%</span></div>
            <div className="mt-1.5 h-2.5 overflow-hidden rounded-full bg-surface-2"><div className="h-full rounded-full bg-brand" style={{ width: `${d.progress}%` }} /></div>
          </div>
          <Link to={`/learn/enrolments/${eid}/checklist`} className="mt-4 flex items-center gap-3 rounded-2xl border border-line bg-surface px-4 py-3 hover:border-brand">
            <ListChecks className="size-5 text-brand" />
            <span className="flex-1"><span className="block font-semibold text-ink">{t('jr.title')}</span><span className="block text-[13px] text-muted">{t('jr.cardLead')}</span></span>
          </Link>
          <Link to={`/learn/enrolments/${eid}/assessments`} className="mt-3 flex items-center gap-3 rounded-2xl border border-line bg-surface px-4 py-3 hover:border-brand">
            <ClipboardCheck className="size-5 text-brand" />
            <span className="flex-1"><span className="block font-semibold text-ink">{t('as.title')}</span><span className="block text-[13px] text-muted">{t('as.cardLead')}</span></span>
          </Link>
          <Card className="mt-4 overflow-hidden p-0">
            <ol className="divide-y divide-line">
              {d.activities.map((a, i) => <Row key={a.id} eid={eid} a={a} n={i + 1} />)}
            </ol>
          </Card>
        </>
      )}
    </div>
  );
}

function Row({ eid, a, n }: { eid: string; a: LearnActivity; n: number }) {
  const { t } = useI18n();
  const Icon = a.status === 'done' ? CheckCircle2 : a.status === 'started' ? CircleDot : Circle;
  const href = a.kind === 'lesson' ? `/learn/enrolments/${eid}/lessons/${a.id}` : `/learn/enrolments/${eid}/simulators/${a.id}`;
  return (
    <li>
      <Link to={href} className="flex min-h-16 items-center gap-4 px-5 py-3 hover:bg-surface-2">
        <Icon className={cx('size-6 shrink-0', a.status === 'done' ? 'text-[#1E7A46]' : a.status === 'started' ? 'text-brand' : 'text-line')} aria-label={t(`lp.status.${a.status}`)} />
        <span className="min-w-0 flex-1">
          <span className="block text-[15px] font-semibold text-ink">{n}. {a.title}</span>
          <span className="flex items-center gap-1.5 text-[13px] text-muted">
            {a.kind === 'lesson' ? <BookOpen className="size-3.5" /> : <Box className="size-3.5" />}
            {t(a.kind === 'lesson' ? 'lp.lesson' : 'lp.simulator')} · {t('lp.minutes', { n: a.minutes })}{a.required ? '' : ` · ${t('lp.optional')}`}{a.best_score !== null ? ` · ${t('lp.best', { n: a.best_score })}` : ''}
          </span>
        </span>
      </Link>
    </li>
  );
}
