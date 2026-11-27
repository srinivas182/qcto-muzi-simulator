import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Box, PlayCircle } from 'lucide-react';
import type { CoverageChannel, CoverageStatus, ProgrammeCoverage, ProgrammeVersionContent } from '@simuwork/core';
import type { MessageKey } from '@simuwork/core/locales/en';
import { Card, cx, Skeleton, useToast } from '../../components/ui';
import { api, ApiError } from '../../lib/api';
import { useI18n } from '../../lib/i18n';
import { ReadinessRing } from './ReadinessRing';

const STATUSES: CoverageStatus[] = ['planned', 'in_production', 'linked'];
const TONE: Record<CoverageStatus | 'none', string> = {
  none: 'border-dashed border-line text-muted bg-transparent',
  planned: 'border-transparent bg-brand-soft text-brand',
  in_production: 'border-transparent bg-[#FDF4E1] text-[#8A5A00] dark:bg-[#8A5A00]/25 dark:text-[#F5C46B]',
  linked: 'border-transparent bg-[#E6F6F1] text-[#0B7A5C] dark:bg-[#0B7A5C]/20 dark:text-[#5FD3B1]',
};
type Filter = 'all' | 'gaps' | 'planned' | 'ready';

const coverageKey = (code: string) => ['hq-coverage', code] as const;

/** HQ coverage map: per outcome, how a simulator and a course cover it; readiness for the programme. */
export function CoverageTab({ code, content, editable }: { code: string; content: ProgrammeVersionContent; editable: boolean }) {
  const { t } = useI18n();
  const toast = useToast();
  const qc = useQueryClient();
  const [filter, setFilter] = useState<Filter>('all');
  const map = useQuery({ queryKey: coverageKey(code), queryFn: async () => (await api<{ data: ProgrammeCoverage }>(`/platform/programmes/${code}/coverage`)).data });
  const save = useMutation({
    mutationFn: ({ outcome, channel, status }: { outcome: string; channel: CoverageChannel; status: CoverageStatus | null }) =>
      api<{ data: ProgrammeCoverage }>(`/platform/programmes/${code}/coverage/${outcome}`, { method: 'PUT', body: { channel, status } }),
    onSuccess: (res) => {
      qc.setQueryData(coverageKey(code), res.data);
      void qc.invalidateQueries({ queryKey: ['hq-coverage-overview'] });
    },
    onError: (e) => toast(e instanceof ApiError ? e.message : t('common.error'), 'error'),
  });

  if (!map.data) return <Skeleton className="h-96" />;
  const { summary, outcomes } = map.data;
  const gaps = summary.outcomes - summary.ready - summary.planned;
  const stateOf = (c: string): Exclude<Filter, 'all'> => {
    const row = outcomes[c];
    if (row?.simulator?.status === 'linked' || row?.course?.status === 'linked') return 'ready';
    return row?.simulator || row?.course ? 'planned' : 'gaps';
  };

  return (
    <div className="space-y-5">
      <Card className="flex flex-col gap-6 p-5 sm:flex-row sm:items-center">
        <ReadinessRing value={summary.readiness} size={112} label={t('cov.readiness')} />
        <div className="grid flex-1 grid-cols-3 gap-3">
          <Stat label={t('cov.ready')} value={summary.ready} tone="text-[#0B7A5C] dark:text-[#5FD3B1]" />
          <Stat label={t('cov.inProgress')} value={summary.planned} tone="text-brand" />
          <Stat label={t('cov.gaps')} value={gaps} tone="text-[#B42328] dark:text-[#FF9A9E]" />
          <p className="col-span-3 text-[13px] text-muted">{t('cov.explain')}</p>
        </div>
      </Card>

      <div role="radiogroup" aria-label={t('cov.filter')} className="inline-flex rounded-xl bg-surface-2 p-1">
        {(['all', 'gaps', 'planned', 'ready'] as Filter[]).map((f) => (
          <button key={f} role="radio" aria-checked={filter === f} onClick={() => setFilter(f)} className={cx('h-8 rounded-lg px-3 text-[13px] font-semibold transition', filter === f ? 'bg-surface text-ink shadow-sm' : 'text-muted hover:text-ink')}>
            {t(`cov.filter.${f}` as MessageKey)}
          </button>
        ))}
      </div>

      {content.duty_areas.map((area) => {
        const rows = area.outcomes.filter((o) => filter === 'all' || stateOf(o.code) === filter);
        if (rows.length === 0) return null;
        return (
          <Card key={area.number} className="overflow-hidden p-0">
            <div className="flex items-center gap-3 border-b border-line bg-surface-2/50 px-5 py-3">
              <span className="flex size-7 shrink-0 items-center justify-center rounded-full bg-brand text-[12px] font-bold text-white">{area.number}</span>
              <h3 className="text-[15px] font-bold text-ink">{area.title}</h3>
            </div>
            <ul className="divide-y divide-line">
              {rows.map((o) => (
                <li key={o.code} className="flex flex-col gap-3 px-5 py-3 md:flex-row md:items-center">
                  <span className="h-fit w-fit shrink-0 rounded-md bg-surface-2 px-1.5 py-0.5 font-mono text-[11px] font-bold text-muted">{o.code}</span>
                  <p className="min-w-0 flex-1 text-[14px] text-ink">{o.statement}</p>
                  <div className="flex shrink-0 gap-2">
                    {(['simulator', 'course'] as CoverageChannel[]).map((channel) => (
                      <StatusPicker
                        key={channel}
                        channel={channel}
                        outcome={o.code}
                        value={outcomes[o.code]?.[channel]?.status ?? null}
                        title={outcomes[o.code]?.[channel]?.title ?? null}
                        editable={editable}
                        onChange={(status) => save.mutate({ outcome: o.code, channel, status })}
                      />
                    ))}
                  </div>
                </li>
              ))}
            </ul>
          </Card>
        );
      })}
    </div>
  );
}

function Stat({ label, value, tone }: { label: string; value: number; tone: string }) {
  return (
    <div className="rounded-xl bg-surface-2/60 px-3 py-2.5">
      <div className={cx('text-[24px] font-bold tabular-nums', tone)}>{value}</div>
      <div className="text-[12px] font-semibold text-muted">{label}</div>
    </div>
  );
}

function StatusPicker({ channel, outcome, value, title, editable, onChange }: {
  channel: CoverageChannel; outcome: string; value: CoverageStatus | null; title: string | null; editable: boolean; onChange: (s: CoverageStatus | null) => void;
}) {
  const { t } = useI18n();
  const Icon = channel === 'simulator' ? Box : PlayCircle;
  const label = `${t(`cov.channel.${channel}` as MessageKey)} · ${outcome}`;
  const text = t(`cov.status.${value ?? 'none'}` as MessageKey);
  const cls = cx('inline-flex h-8 min-w-[9.5rem] items-center gap-1.5 rounded-full border px-2.5 text-[12px] font-semibold', TONE[value ?? 'none']);

  if (!editable) {
    return <span className={cls} title={title ?? undefined}><Icon className="size-3.5" aria-hidden />{t(`cov.channel.${channel}` as MessageKey)}: {text}</span>;
  }
  return (
    <label className={cx(cls, 'relative cursor-pointer focus-within:ring-2 focus-within:ring-brand/40')} title={title ?? undefined}>
      <Icon className="size-3.5 shrink-0" aria-hidden />
      <span className="truncate">{t(`cov.channel.${channel}` as MessageKey)}: {text}</span>
      <select aria-label={label} value={value ?? ''} onChange={(e) => onChange((e.target.value || null) as CoverageStatus | null)} className="absolute inset-0 cursor-pointer opacity-0">
        <option value="">{t('cov.status.none')}</option>
        {STATUSES.map((s) => <option key={s} value={s}>{t(`cov.status.${s}` as MessageKey)}</option>)}
      </select>
    </label>
  );
}
