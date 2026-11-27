import { useMemo, useState } from 'react';
import { Link } from 'react-router';
import { useQuery } from '@tanstack/react-query';
import { GraduationCap, Search } from 'lucide-react';
import type { CoverageScore, ProgrammeSummary } from '@simuwork/core';
import { Badge, Card, cx, EmptyState, inputClass, PageHeader, Skeleton } from '../../components/ui';
import { api } from '../../lib/api';
import { useI18n } from '../../lib/i18n';
import { ReadinessRing } from './ReadinessRing';

export default function Programmes() {
  const { t, locale } = useI18n();
  const [q, setQ] = useState('');
  const list = useQuery({ queryKey: ['hq-programmes'], queryFn: async () => (await api<{ data: ProgrammeSummary[] }>('/platform/programmes')).data });
  const coverage = useQuery({ queryKey: ['hq-coverage-overview'], queryFn: async () => (await api<{ data: Record<string, CoverageScore> }>('/platform/coverage')).data });
  const shown = useMemo(() => (list.data ?? []).filter((p) => !q || `${p.title} ${p.prefix} ${p.summary}`.toLowerCase().includes(q.toLowerCase())), [list.data, q]);

  return (
    <div className="sw-in">
      <PageHeader title={t('prog.title')} lead={t('prog.lead')} />
      <div className="relative mb-6 max-w-sm">
        <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted" aria-hidden />
        <input value={q} onChange={(e) => setQ(e.target.value)} placeholder={t('prog.search')} aria-label={t('prog.search')} className={cx(inputClass, 'h-10 pl-9 text-[14px]')} />
      </div>

      {list.isPending ? (
        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">{Array.from({ length: 6 }, (_, i) => <Skeleton key={i} className="h-48 rounded-2xl" />)}</div>
      ) : shown.length === 0 ? (
        <Card><EmptyState icon={<GraduationCap className="size-6" />} title={t('prog.none')} /></Card>
      ) : (
        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
          {shown.map((p) => (
            <Link key={p.code} to={`/hq/programmes/${p.code}`} className="group">
              <Card className="flex h-full flex-col p-5 transition group-hover:-translate-y-0.5 group-hover:border-brand/40 group-hover:shadow-lg">
                <div className="flex items-start justify-between gap-3">
                  <span className="rounded-lg bg-brand-soft px-2 py-1 font-mono text-[12px] font-bold text-brand">{p.prefix}</span>
                  {p.draft ? (
                    <Badge tone={p.draft.status === 'in_review' ? 'warning' : 'brand'}>{t(p.draft.status === 'in_review' ? 'prog.status.in_review' : 'prog.status.draft', { n: p.draft.version })}</Badge>
                  ) : null}
                </div>
                <h3 className="mt-3 text-[16px] font-bold leading-snug text-ink">{p.title}</h3>
                <p className="mt-1 line-clamp-2 text-[14px] text-muted">{p.summary}</p>
                <div className="mt-auto flex items-end gap-3 pt-5">
                  <div className="min-w-0 flex-1">
                  {p.counts && <p className="text-[13px] font-medium text-ink">{t('prog.counts', { areas: p.counts.duty_areas, outcomes: p.counts.outcomes, evidence: p.counts.evidence })}</p>}
                  <p className="mt-1 text-[12px] text-muted">
                    {p.version ? `${t('prog.version', { n: p.version })} · ${t('prog.publishedOn', { date: new Date(p.published_at ?? '').toLocaleDateString(locale, { day: 'numeric', month: 'short', year: 'numeric' }) })}` : ''}
                  </p>
                  </div>
                  {coverage.data?.[p.code] && <ReadinessRing value={coverage.data[p.code]!.readiness} size={48} label={t('cov.readinessOf', { title: p.title })} />}
                </div>
              </Card>
            </Link>
          ))}
        </div>
      )}
    </div>
  );
}
