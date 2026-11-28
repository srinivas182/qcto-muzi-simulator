import { Link, useParams } from 'react-router';
import { useQuery } from '@tanstack/react-query';
import { ArrowLeft, Briefcase, CircleCheck, ClipboardCheck, Hourglass, PenLine } from 'lucide-react';
import type { AssessmentComponent, LearnAssessmentItem } from '@simuwork/core';
import { Badge, Card, Skeleton } from '../../components/ui';
import { api } from '../../lib/api';
import { useI18n } from '../../lib/i18n';

const COMPONENTS: AssessmentComponent[] = ['knowledge', 'practical', 'workplace'];

/** Learner: my summative assessments for this programme, by QCTO component. */
export default function Assessments() {
  const { eid = '' } = useParams();
  const { t } = useI18n();
  const q = useQuery({ queryKey: ['learn-assessments', eid], queryFn: () => api<{ data: LearnAssessmentItem[] }>(`/learn/enrolments/${eid}/assessments`) });
  return (
    <div className="sw-in mx-auto max-w-2xl space-y-4">
      <Link to={`/learn/enrolments/${eid}`} className="inline-flex items-center gap-1 text-[14px] font-semibold text-brand hover:underline"><ArrowLeft className="size-4" />{t('lp.allLessons')}</Link>
      <div><h1 className="text-[24px] font-bold text-ink">{t('as.title')}</h1><p className="mt-1 text-[15px] text-muted">{t('as.lead')}</p></div>
      {!q.data ? <Skeleton className="h-64 rounded-2xl" /> : q.data.data.length === 0 ? <Card className="p-6 text-center text-[14px] text-muted">{t('as.none')}</Card> : COMPONENTS.map((c) => {
        const items = q.data!.data.filter((x) => x.component === c);
        if (items.length === 0) return null;
        return (
          <section key={c}>
            <h2 className="mb-2 text-[13px] font-bold uppercase tracking-wide text-muted">{t(`as.component.${c}`)}</h2>
            <Card className="p-0"><ul className="divide-y divide-line">{items.map((x) => <Item key={x.id} eid={eid} x={x} />)}</ul></Card>
          </section>
        );
      })}
    </div>
  );
}

function Item({ eid, x }: { eid: string; x: LearnAssessmentItem }) {
  const { t } = useI18n();
  const left = (x.attempts_allowed ?? 0) - x.attempts_used;
  const status = x.result === 'competent' ? <Badge tone="success"><CircleCheck className="size-3" />{t('as.competent')}</Badge>
    : x.waiting_for_marking ? <Badge tone="warning"><Hourglass className="size-3" />{t('as.waiting')}</Badge>
    : x.result === 'not_yet' ? <Badge tone="neutral">{t('as.notYet')}</Badge> : null;
  const body = (
    <span className="flex items-center gap-3 px-4 py-3.5">
      <span className="grid size-10 shrink-0 place-items-center rounded-xl bg-brand-soft text-brand">{x.kind === 'test' ? <PenLine className="size-5" /> : x.kind === 'logbook' ? <Briefcase className="size-5" /> : <ClipboardCheck className="size-5" />}</span>
      <span className="min-w-0 flex-1">
        <span className="block font-semibold text-ink">{x.title}</span>
        <span className="block text-[12px] text-muted">{x.kind === 'test' ? t('as.testMeta', { n: x.questions ?? 0, min: x.minutes ?? 0, pass: x.pass_mark ?? 0, left: Math.max(0, left) }) : t('as.withAssessor')}</span>
      </span>
      {status}
    </span>
  );
  const canSit = x.kind === 'test' && x.result !== 'competent' && !x.waiting_for_marking && (x.open_sitting !== null || left > 0);
  return <li>{canSit ? <Link to={`/learn/enrolments/${eid}/tests/${x.id}`} className="block hover:bg-surface-2" aria-label={`${x.title}: ${t(x.open_sitting ? 'as.resume' : 'as.start')}`}>{body}</Link> : body}</li>;
}
