import { useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import type { AssessResult, DecisionOut, PracticalTask } from '@simuwork/core';
import { Badge, Button, Card, cx, inputClass, useToast } from '../ui';
import { api, ApiError } from '../../lib/api';
import { useI18n } from '../../lib/i18n';

type Row = { met: boolean | null; note: string };
type Draft = { result: AssessResult | null; feedback: string; links: string[]; rows: Row[] };

const load = (key: string, n: number): Draft => {
  try {
    const d = JSON.parse(localStorage.getItem(key) ?? '') as Draft;
    if (Array.isArray(d.rows) && d.rows.length === n) return d;
  } catch { /* no draft */ }
  return { result: null, feedback: '', links: [], rows: Array.from({ length: n }, () => ({ met: null, note: '' })) };
};

/**
 * The assessor's decision on a practical task: competent or not yet, feedback linked to a step or a lesson, the
 * observation checklist when there is one, and the declaration. Work in progress is kept on this device.
 */
export function DecisionForm({ task, enrolmentId, attemptId, onDone }: { task: PracticalTask; enrolmentId: string; attemptId?: string; onDone: (d: DecisionOut) => void }) {
  const { t } = useI18n();
  const toast = useToast();
  const qc = useQueryClient();
  const key = `sw.decision.${enrolmentId}.${task.id}.${attemptId ?? 'obs'}`;
  const observe = task.kind === 'observation';
  const [d, setD] = useState<Draft>(() => load(key, observe ? task.items.length : 0));
  const [declared, setDeclared] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const put = (next: Draft) => { setD(next); try { localStorage.setItem(key, JSON.stringify(next)); } catch { /* full */ } };
  const allMet = d.rows.every((r) => r.met === true);
  const allTicked = d.rows.every((r) => r.met !== null);
  const toggle = (ref: string) => put({ ...d, links: d.links.includes(ref) ? d.links.filter((x) => x !== ref) : [...d.links, ref] });

  const save = useMutation({
    mutationFn: () => api<{ data: DecisionOut }>(`/assess/enrolments/${enrolmentId}/tasks/${task.id}/decisions`, { method: 'POST', body: {
      result: d.result, feedback: d.feedback || null, attempt_id: attemptId ?? null, declaration: true,
      links: d.links.map((ref) => (ref.startsWith('lesson:') ? { type: 'lesson', ref: ref.slice(7) } : { type: 'step', ref: ref.slice(5) })),
      observation: observe ? d.rows.map((r) => ({ met: r.met === true, note: r.note || null })) : null,
    } }),
    onSuccess: (r) => {
      try { localStorage.removeItem(key); } catch { /* ignore */ }
      void qc.invalidateQueries({ queryKey: ['assess-queue'] });
      toast(t('rv.saved'));
      onDone(r.data);
    },
    onError: (e) => setError(e instanceof ApiError ? Object.values(e.errors)[0]?.[0] ?? e.message : t('common.error')),
  });

  if (task.competent) return <Card className="p-5"><Badge tone="success">{t('rv.competent')}</Badge><p className="mt-2 text-[14px] text-muted">{t('rv.alreadyCompetent')}</p></Card>;
  if (task.rounds_used >= task.rounds_allowed) return <Card className="p-5"><p className="text-[14px] font-medium text-ink">{t('rv.noRoundsLeft')}</p></Card>;

  const ready = d.result !== null && declared && (!observe || allTicked) && (d.result === 'competent' || d.feedback.trim() !== '') && !(observe && d.result === 'competent' && !allMet);
  return (
    <Card className="space-y-4 p-5">
      <div className="flex items-baseline justify-between gap-3">
        <h2 className="text-[16px] font-semibold text-ink">{t('rv.decision')}</h2>
        <span className="text-[13px] text-muted">{t('rv.round', { n: task.rounds_used + 1, of: task.rounds_allowed })}</span>
      </div>
      {observe && (
        <fieldset className="space-y-2">
          <legend className="mb-1 text-[13px] font-semibold text-ink">{t('rv.checklist')}</legend>
          {task.items.map((item, i) => (
            <div key={item} className="rounded-xl border border-line p-3">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <span className="text-[14px] text-ink">{item}</span>
                <div role="radiogroup" aria-label={item} className="flex gap-1">
                  {([true, false] as const).map((met) => (
                    <button key={String(met)} type="button" role="radio" aria-checked={d.rows[i]!.met === met}
                      onClick={() => put({ ...d, rows: d.rows.map((r, j) => (j === i ? { ...r, met } : r)) })}
                      className={cx('h-8 rounded-lg px-3 text-[13px] font-semibold', d.rows[i]!.met === met ? (met ? 'bg-[#0E7C66] text-white' : 'bg-[#C92A2F] text-white') : 'bg-surface-2 text-ink')}>
                      {t(met ? 'rv.met' : 'rv.notMet')}
                    </button>
                  ))}
                </div>
              </div>
              <input className={cx(inputClass, 'mt-2 h-9 text-[13px]')} placeholder={t('rv.note')} aria-label={t('rv.noteFor', { item })} value={d.rows[i]!.note}
                onChange={(e) => put({ ...d, rows: d.rows.map((r, j) => (j === i ? { ...r, note: e.target.value } : r)) })} />
            </div>
          ))}
        </fieldset>
      )}
      <div role="radiogroup" aria-label={t('rv.decision')} className="flex gap-2">
        {(['competent', 'not_yet'] as const).map((r) => (
          <button key={r} type="button" role="radio" aria-checked={d.result === r} onClick={() => put({ ...d, result: r })}
            className={cx('h-10 flex-1 rounded-xl border text-[14px] font-semibold', d.result === r ? (r === 'competent' ? 'border-[#0E7C66] bg-[#E6F4F0] text-[#0E7C66]' : 'border-[#C92A2F] bg-[#FCEBEB] text-[#C92A2F]') : 'border-line text-ink')}>
            {t(r === 'competent' ? 'rv.competent' : 'rv.notYet')}
          </button>
        ))}
      </div>
      {observe && d.result === 'competent' && allTicked && !allMet && <p role="alert" className="text-[13px] text-[#C92A2F]">{t('rv.allItems')}</p>}
      <label className="block">
        <span className="text-[13px] font-semibold text-ink">{t(d.result === 'not_yet' ? 'rv.feedbackNeeded' : 'rv.feedback')}</span>
        <textarea className={cx(inputClass, 'mt-1 min-h-24 py-2')} value={d.feedback} maxLength={2000} onChange={(e) => put({ ...d, feedback: e.target.value })} />
      </label>
      {(task.link_options.steps.length > 0 || task.link_options.lessons.length > 0) && (
        <fieldset>
          <legend className="text-[13px] font-semibold text-ink">{t('rv.links')}</legend>
          <p className="text-[12px] text-muted">{t('rv.linksLead')}</p>
          <div className="mt-2 flex flex-wrap gap-2">
            {task.link_options.steps.map((s, i) => <Chip key={s.id} on={d.links.includes(`step:${s.id}`)} onClick={() => toggle(`step:${s.id}`)}>{t('rv.step', { n: i + 1 })}: {s.prompt}</Chip>)}
            {task.link_options.lessons.map((l) => <Chip key={l.code} on={d.links.includes(`lesson:${l.code}`)} onClick={() => toggle(`lesson:${l.code}`)}>{t('rv.lesson')}: {l.title}</Chip>)}
          </div>
        </fieldset>
      )}
      <label className="flex items-start gap-2 text-[13px] text-ink">
        <input type="checkbox" className="mt-0.5 size-4 accent-[var(--brand)]" checked={declared} onChange={(e) => setDeclared(e.target.checked)} />
        {t('rv.declaration')}
      </label>
      {error && <p role="alert" className="text-[13px] text-[#C92A2F]">{error}</p>}
      <Button disabled={!ready} loading={save.isPending} onClick={() => { setError(null); save.mutate(); }}>{t('rv.signOff')}</Button>
    </Card>
  );
}

function Chip({ on, onClick, children }: { on: boolean; onClick: () => void; children: React.ReactNode }) {
  return (
    <button type="button" aria-pressed={on} onClick={onClick} className={cx('max-w-full truncate rounded-full border px-3 py-1 text-[12px] font-medium', on ? 'border-brand bg-brand/10 text-brand' : 'border-line text-ink')}>
      {children}
    </button>
  );
}

/** Earlier decisions on a task, newest last. */
export function DecisionHistory({ history }: { history: DecisionOut[] }) {
  const { t, locale } = useI18n();
  if (history.length === 0) return null;
  return (
    <Card className="p-5">
      <h2 className="text-[15px] font-semibold text-ink">{t('rv.history')}</h2>
      <ol className="mt-3 space-y-3">
        {history.map((h) => (
          <li key={h.id} className="text-[13px]">
            <div className="flex items-center gap-2"><Badge tone={h.result === 'competent' ? 'success' : 'warning'}>{t(h.result === 'competent' ? 'rv.competent' : 'rv.notYet')}</Badge>
              <span className="text-muted">{t('rv.byOn', { n: h.round, name: h.assessor, date: new Date(h.at).toLocaleDateString(locale) })}</span></div>
            {h.feedback && <p className="mt-1 text-ink">{h.feedback}</p>}
          </li>
        ))}
      </ol>
    </Card>
  );
}
