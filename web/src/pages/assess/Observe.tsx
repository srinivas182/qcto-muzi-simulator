import { useParams, Link } from 'react-router';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft } from 'lucide-react';
import type { PracticalTask } from '@simuwork/core';
import { AccommodationsButton } from '../../components/assessment/Accommodations';
import { DecisionForm, DecisionHistory } from '../../components/assessment/DecisionForm';
import { PageHeader, Skeleton } from '../../components/ui';
import { api } from '../../lib/api';
import { useI18n } from '../../lib/i18n';

/** A short observation: the checklist, met or not met per item, and the decision. Kept on this device until sent. */
export default function ObserveTask() {
  const { eid = '', tid = '' } = useParams();
  const { t } = useI18n();
  const qc = useQueryClient();
  const q = useQuery({ queryKey: ['assess-task', eid, tid], queryFn: () => api<{ data: PracticalTask & { learner: string; enrolment_id: string } }>(`/assess/enrolments/${eid}/tasks/${tid}`) });
  const task = q.data?.data;
  if (!task) return <Skeleton className="h-96 max-w-3xl" />;
  return (
    <div className="sw-in max-w-3xl space-y-4">
      <Link to="/assess" className="inline-flex items-center gap-1 text-[13px] font-semibold text-brand"><ArrowLeft className="size-4" />{t('rv.back')}</Link>
      <PageHeader title={task.learner} lead={task.title} actions={<AccommodationsButton enrolmentId={eid} learner={task.learner} />} />
      <DecisionForm task={task} enrolmentId={eid} learner={task.learner} onDone={() => void qc.invalidateQueries({ queryKey: ['assess-task', eid, tid] })} />
      <DecisionHistory history={task.history} />
    </div>
  );
}
