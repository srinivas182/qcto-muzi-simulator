import { useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import { Card, cx } from '../ui';
import { api } from '../../lib/api';
import { useI18n } from '../../lib/i18n';

const QUESTIONS = ['easy', 'real', 'ready'] as const;
const FACES = ['😞', '🙁', '😐', '🙂', '😄'];

/** Pilot measure: three one-tap questions after a simulator (1–5). Optional, and asked once per visit. */
export function RateCard({ eid, aid }: { eid: string; aid: string }) {
  const { t } = useI18n();
  const [answers, setAnswers] = useState<Partial<Record<(typeof QUESTIONS)[number], number>>>({});
  const send = useMutation({ mutationFn: (body: Record<string, number>) => api(`/learn/enrolments/${eid}/scenarios/${aid}/rating`, { method: 'POST', body }) });
  const pick = (q: (typeof QUESTIONS)[number], v: number) => {
    const next = { ...answers, [q]: v };
    setAnswers(next);
    if (QUESTIONS.every((k) => next[k])) send.mutate(next as Record<string, number>);
  };
  if (send.isSuccess) return <Card className="p-4 text-center text-[14px] text-ink">{t('sim.rateThanks')}</Card>;
  return (
    <Card className="p-5">
      <p className="text-[15px] font-bold text-ink">{t('sim.rateTitle')}</p>
      <div className="mt-3 space-y-3">
        {QUESTIONS.map((q) => (
          <fieldset key={q}>
            <legend className="mb-1.5 text-[14px] text-ink">{t(`sim.rate.${q}`)}</legend>
            <div className="flex gap-1.5">
              {FACES.map((f, i) => (
                <button key={i} type="button" aria-label={`${t(`sim.rate.${q}`)} ${i + 1}/5`} aria-pressed={answers[q] === i + 1} onClick={() => pick(q, i + 1)}
                  className={cx('grid size-11 place-items-center rounded-xl border text-[22px] transition', answers[q] === i + 1 ? 'border-brand bg-brand-soft' : 'border-line hover:border-brand')}>{f}</button>
              ))}
            </div>
          </fieldset>
        ))}
      </div>
    </Card>
  );
}
