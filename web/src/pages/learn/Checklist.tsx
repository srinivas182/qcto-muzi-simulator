import { Link, useParams } from 'react-router';
import { useQuery } from '@tanstack/react-query';
import { ArrowLeft } from 'lucide-react';
import type { ChecklistArea } from '@simuwork/core';
import { Checklist } from '../../components/progress/Checklist';
import { Card, Skeleton } from '../../components/ui';
import { api } from '../../lib/api';
import { useI18n } from '../../lib/i18n';

/** Learner: my job-ready checklist. */
export default function MyChecklist() {
  const { eid = '' } = useParams();
  const { t } = useI18n();
  const q = useQuery({ queryKey: ['learn-checklist', eid], queryFn: () => api<{ data: { areas: ChecklistArea[] } }>(`/learn/enrolments/${eid}/checklist`) });
  return (
    <div className="sw-in mx-auto max-w-2xl space-y-4">
      <Link to={`/learn/enrolments/${eid}`} className="inline-flex items-center gap-1 text-[14px] font-semibold text-brand hover:underline"><ArrowLeft className="size-4" />{t('lp.allLessons')}</Link>
      <div>
        <h1 className="text-[24px] font-bold text-ink">{t('jr.title')}</h1>
        <p className="mt-1 text-[15px] text-muted">{t('jr.lead')}</p>
      </div>
      <Card className="p-5">{q.data ? <Checklist areas={q.data.data.areas} /> : <Skeleton className="h-64" />}</Card>
    </div>
  );
}
