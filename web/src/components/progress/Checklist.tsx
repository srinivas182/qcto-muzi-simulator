import { CircleCheck, CircleDashed, CircleDot } from 'lucide-react';
import type { ChecklistArea, OutcomeStatus } from '@simuwork/core';
import { cx } from '../ui';
import { useI18n } from '../../lib/i18n';

const ICON: Record<OutcomeStatus, typeof CircleCheck> = { shown: CircleCheck, practised: CircleDot, not_yet: CircleDashed };
const TONE: Record<OutcomeStatus, string> = { shown: 'text-[#0B7A5C]', practised: 'text-[#B45309]', not_yet: 'text-muted' };

/** The job-ready checklist: each QCTO outcome, by duty area, marked shown / practised / not yet. */
export function Checklist({ areas }: { areas: ChecklistArea[] }) {
  const { t } = useI18n();
  const all = areas.flatMap((a) => a.outcomes);
  const shown = all.filter((o) => o.status === 'shown').length;
  const practised = all.filter((o) => o.status === 'practised').length;
  return (
    <div className="space-y-4">
      <div>
        <p className="text-[15px] font-semibold text-ink">{t('jr.summary', { shown, n: all.length })}</p>
        <div className="mt-2 flex h-2.5 overflow-hidden rounded-full bg-line" aria-hidden>
          <span className="bg-[#0B7A5C]" style={{ width: `${(100 * shown) / Math.max(1, all.length)}%` }} />
          <span className="bg-[#F59E0B]" style={{ width: `${(100 * practised) / Math.max(1, all.length)}%` }} />
        </div>
        <p className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-[12px] text-muted">
          {(['shown', 'practised', 'not_yet'] as const).map((s) => { const I = ICON[s]; return <span key={s} className="flex items-center gap-1"><I className={cx('size-3.5', TONE[s])} />{t(`jr.status.${s}`)}</span>; })}
        </p>
      </div>
      {areas.map((a) => (
        <section key={a.number}>
          <h3 className="mb-1.5 text-[13px] font-bold uppercase tracking-wide text-muted">{`${a.number}. ${a.title}`}</h3>
          <ul className="divide-y divide-line rounded-xl border border-line">
            {a.outcomes.map((o) => {
              const I = ICON[o.status];
              return (
                <li key={o.code} className="flex gap-3 px-3 py-2.5">
                  <I className={cx('mt-0.5 size-5 shrink-0', TONE[o.status])} aria-label={t(`jr.status.${o.status}`)} />
                  <span className="min-w-0 flex-1 text-[14px] text-ink"><span className="font-mono text-[12px] font-semibold text-muted">{o.code}</span> {o.plain_words || o.statement}</span>
                </li>
              );
            })}
          </ul>
        </section>
      ))}
    </div>
  );
}
