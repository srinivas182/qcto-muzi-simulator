import { useMemo, useState } from 'react';
import { Link } from 'react-router';
import { useQuery } from '@tanstack/react-query';
import { GraduationCap, Search } from 'lucide-react';
import type { ProgrammeSummary } from '@simuwork/core';
import { Card, cx, EmptyState, inputClass, PageHeader, Skeleton } from '../../components/ui';
import { api } from '../../lib/api';
import { useI18n } from '../../lib/i18n';

/** Provider catalogue: the published programmes, read-only. */
export default function Programmes() {
  const { t } = useI18n();
  const [q, setQ] = useState('');
  const list = useQuery({ queryKey: ['programmes'], queryFn: async () => (await api<{ data: ProgrammeSummary[] }>('/programmes')).data });
  const shown = useMemo(() => (list.data ?? []).filter((p) => !q || `${p.title} ${p.prefix} ${p.summary}`.toLowerCase().includes(q.toLowerCase())), [list.data, q]);
  const families = useMemo(() => [...new Set(shown.map((p) => p.family))], [shown]);

  return (
    <div className="sw-in">
      <PageHeader title={t('cat.title')} lead={t('cat.lead')} />
      <div className="relative mb-6 max-w-sm">
        <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted" aria-hidden />
        <input value={q} onChange={(e) => setQ(e.target.value)} placeholder={t('prog.search')} aria-label={t('prog.search')} className={cx(inputClass, 'h-10 pl-9 text-[14px]')} />
      </div>

      {list.isPending ? (
        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">{Array.from({ length: 6 }, (_, i) => <Skeleton key={i} className="h-44 rounded-2xl" />)}</div>
      ) : shown.length === 0 ? (
        <Card><EmptyState icon={<GraduationCap className="size-6" />} title={t('prog.none')} /></Card>
      ) : (
        <div className="space-y-8">
          {families.map((family) => (
            <section key={family}>
              <h2 className="mb-3 text-[12px] font-bold uppercase tracking-[0.12em] text-muted">{family}</h2>
              <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                {shown.filter((p) => p.family === family).map((p) => (
                  <Link key={p.code} to={`/admin/programmes/${p.code}`} className="group">
                    <Card className="flex h-full flex-col p-5 transition group-hover:-translate-y-0.5 group-hover:border-brand/40 group-hover:shadow-lg">
                      <span className="w-fit rounded-lg bg-brand-soft px-2 py-1 font-mono text-[12px] font-bold text-brand">{p.prefix}</span>
                      <h3 className="mt-3 text-[16px] font-bold leading-snug text-ink">{p.title}</h3>
                      <p className="mt-1 line-clamp-2 text-[14px] text-muted">{p.summary}</p>
                      {p.counts && <p className="mt-auto pt-5 text-[13px] font-medium text-ink">{t('prog.counts', { areas: p.counts.duty_areas, outcomes: p.counts.outcomes, evidence: p.counts.evidence })}</p>}
                    </Card>
                  </Link>
                ))}
              </div>
            </section>
          ))}
        </div>
      )}
    </div>
  );
}
