import { useEffect } from 'react';
import { useParams } from 'react-router';
import { useQuery } from '@tanstack/react-query';
import { ShieldCheck } from 'lucide-react';
import type { SharedChecklist as Shared } from '@simuwork/core';
import { Checklist } from '../../components/progress/Checklist';
import { Card, Skeleton } from '../../components/ui';
import { api } from '../../lib/api';
import { useI18n } from '../../lib/i18n';

/** Public, read-only: a learner's job-ready checklist, opened from a link the learner shared. */
export default function SharedChecklist() {
  const { token = '' } = useParams();
  const { t, locale } = useI18n();
  const q = useQuery({ queryKey: ['shared-checklist', token], queryFn: () => api<{ data: Shared }>(`/checklists/${token}`), retry: false });
  useEffect(() => {
    // Keep shared checklists out of search engines.
    const m = document.createElement('meta');
    m.name = 'robots';
    m.content = 'noindex';
    document.head.appendChild(m);
    return () => m.remove();
  }, []);
  const d = q.data?.data;
  return (
    <main className="mx-auto w-full max-w-2xl flex-1 px-4 py-10">
      {q.isError ? <Card className="p-8 text-center text-[15px] text-muted">{t('jr.public.gone')}</Card> : !d ? <Skeleton className="h-96 rounded-2xl" /> : (
        <>
          <p className="text-[13px] font-semibold uppercase tracking-wide text-muted">{d.academy}</p>
          <h1 className="mt-1 text-[26px] font-bold tracking-tight text-ink">{t('jr.public.title', { name: d.learner })}</h1>
          <p className="mt-1 text-[15px] text-muted">{d.programme} · {t('jr.public.asOf', { date: new Date(d.as_of).toLocaleDateString(locale, { day: 'numeric', month: 'long', year: 'numeric' }) })}</p>
          <Card className="mt-5 p-5"><Checklist areas={d.areas} /></Card>
          <p className="mt-4 flex items-start gap-2 text-[13px] text-muted"><ShieldCheck className="mt-0.5 size-4 shrink-0" />{t('jr.public.note')}</p>
        </>
      )}
    </main>
  );
}
