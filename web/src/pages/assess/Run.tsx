import { useParams, Link } from 'react-router';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, Check, Fingerprint, Lightbulb, X } from 'lucide-react';
import type { RunReplay } from '@simuwork/core';
import { DecisionForm, DecisionHistory } from '../../components/assessment/DecisionForm';
import { Badge, Card, PageHeader, Skeleton } from '../../components/ui';
import { api } from '../../lib/api';
import { useI18n } from '../../lib/i18n';

const secs = (ms: number) => `${Math.floor(ms / 60000)}:${String(Math.floor((ms % 60000) / 1000)).padStart(2, '0')}`;

/** A real-job run, step by step, with the evidence fingerprint and the decision form beside it. */
export default function RunReview() {
  const { id = '' } = useParams();
  const { t, locale } = useI18n();
  const qc = useQueryClient();
  const q = useQuery({ queryKey: ['assess-run', id], queryFn: () => api<{ data: RunReplay }>(`/assess/attempts/${id}`) });
  const r = q.data?.data;
  if (!r) return <Skeleton className="h-96 max-w-5xl" />;
  return (
    <div className="sw-in max-w-5xl">
      <Link to="/assess" className="mb-3 inline-flex items-center gap-1 text-[13px] font-semibold text-brand"><ArrowLeft className="size-4" />{t('rv.back')}</Link>
      <PageHeader title={r.learner} lead={`${r.simulator.title} · ${new Date(r.finished_at ?? r.started_at).toLocaleString(locale)} · ${Math.round(r.duration_seconds / 60)} min${r.device ? ` · ${r.device}` : ''}`} />
      <div className="grid gap-5 lg:grid-cols-[1fr_380px]">
        <div className="space-y-4">
          <Card className="flex flex-wrap items-center gap-3 p-4">
            <Badge tone={r.passed ? 'success' : 'warning'}>{t('rv.scoreOf', { n: r.score ?? 0, pass: r.pass_mark })}</Badge>
            {r.critical && <Badge tone="danger">{t('rv.criticalLong')}</Badge>}
            {r.version_played !== r.simulator.version_now && <Badge tone="warning">{t('rv.oldVersion', { v: r.version_played })}</Badge>}
            <span className="ml-auto inline-flex items-center gap-1 font-mono text-[11px] text-muted" title={r.hash}><Fingerprint className="size-3.5" />{r.hash.slice(0, 12)}</span>
          </Card>
          <ol className="space-y-3">
            {r.steps.map((s, i) => (
              <li key={s.id}>
                <Card className="p-4">
                  <div className="flex items-start gap-3">
                    <span className={`grid size-7 shrink-0 place-items-center rounded-full text-[13px] font-bold ${s.done ? 'bg-[#E6F4F0] text-[#0E7C66]' : 'bg-[#FCEBEB] text-[#C92A2F]'}`}>{i + 1}</span>
                    <div className="min-w-0 flex-1">
                      <p className="font-semibold text-ink">{s.prompt}</p>
                      <div className="mt-1 flex flex-wrap gap-2 text-[12px]">
                        {s.critical && <Badge tone="danger">{t('rv.criticalStep')}</Badge>}
                        {s.mistakes > 0 && <Badge tone="warning">{t('rv.mistakes', { n: s.mistakes })}</Badge>}
                        {s.hints > 0 && <Badge>{t('rv.hints', { n: s.hints })}</Badge>}
                        {!s.done && <Badge tone="danger">{t('rv.notDone')}</Badge>}
                      </div>
                      {s.moves.length > 0 && (
                        <ul className="mt-2 space-y-1">
                          {s.moves.map((m, j) => (
                            <li key={j} className="flex items-center gap-2 text-[13px] text-ink">
                              <span className="w-10 font-mono text-[11px] text-muted">{secs(m.t)}</span>
                              {m.right === true ? <Check className="size-4 text-[#0E7C66]" aria-label={t('rv.right')} /> : m.right === false ? <X className="size-4 text-[#C92A2F]" aria-label={t('rv.wrong')} /> : <Lightbulb className="size-4 text-muted" aria-label={t('rv.help')} />}
                              <span className="truncate">{m.right === null ? t(m.action === 'hint' ? 'rv.askedHint' : 'rv.askedCoach') : m.value}</span>
                            </li>
                          ))}
                        </ul>
                      )}
                    </div>
                  </div>
                </Card>
              </li>
            ))}
          </ol>
        </div>
        <div className="space-y-4">
          {r.decision ? (
            <Card className="p-5">
              <Badge tone={r.decision.result === 'competent' ? 'success' : 'warning'}>{t(r.decision.result === 'competent' ? 'rv.competent' : 'rv.notYet')}</Badge>
              <p className="mt-2 text-[13px] text-muted">{t('rv.byOn', { n: r.decision.round, name: r.decision.assessor, date: new Date(r.decision.at).toLocaleDateString(locale) })}</p>
              {r.decision.feedback && <p className="mt-2 text-[14px] text-ink">{r.decision.feedback}</p>}
              <p className={`mt-3 text-[12px] font-semibold ${r.decision.hash_matches ? 'text-[#0E7C66]' : 'text-[#C92A2F]'}`}>{t(r.decision.hash_matches ? 'rv.hashSame' : 'rv.hashChanged')}</p>
            </Card>
          ) : r.task ? (
            <DecisionForm task={r.task} enrolmentId={r.enrolment_id} attemptId={r.id} onDone={() => void qc.invalidateQueries({ queryKey: ['assess-run', id] })} />
          ) : <Card className="p-5 text-[14px] text-muted">{t('rv.notATask')}</Card>}
          {r.task && <DecisionHistory history={r.task.history} />}
        </div>
      </div>
    </div>
  );
}
