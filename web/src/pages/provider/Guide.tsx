import { Link, useParams } from 'react-router';
import { useQuery } from '@tanstack/react-query';
import { ArrowLeft, BookOpen, ChevronRight } from 'lucide-react';
import type { ReferencePageContent, ReferencePageSummary } from '@simuwork/core';
import { NotFound } from '../../app/states';
import { Markdown } from '../../components/Markdown';
import { Card, PageHeader, Skeleton } from '../../components/ui';
import { api } from '../../lib/api';
import { useI18n } from '../../lib/i18n';

/** The guide's general chapters (framework, mentor's guide, profiles) for mentors and providers. */
export default function Guide() {
  const { slug } = useParams();
  return slug ? <GuidePage slug={slug} /> : <GuideIndex />;
}

function GuideIndex() {
  const { t } = useI18n();
  const pages = useQuery({ queryKey: ['reference-pages'], queryFn: async () => (await api<{ data: ReferencePageSummary[] }>('/reference-pages')).data });
  return (
    <div className="sw-in">
      <PageHeader title={t('guide.title')} lead={t('guide.lead')} />
      <div className="grid max-w-3xl gap-3">
        {pages.isPending ? Array.from({ length: 3 }, (_, i) => <Skeleton key={i} className="h-20 rounded-2xl" />) : pages.data?.map((p) => (
          <Link key={p.slug} to={`/admin/guide/${p.slug}`} className="group">
            <Card className="flex items-center gap-4 p-5 transition group-hover:border-brand/40 group-hover:shadow-lg">
              <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-brand-soft text-brand"><BookOpen className="size-5" /></span>
              <span className="flex-1 text-[16px] font-bold text-ink">{p.title}</span>
              <ChevronRight className="size-5 text-muted transition group-hover:translate-x-0.5" />
            </Card>
          </Link>
        ))}
      </div>
    </div>
  );
}

function GuidePage({ slug }: { slug: string }) {
  const { t } = useI18n();
  const page = useQuery({ queryKey: ['reference-page', slug], queryFn: async () => (await api<{ data: ReferencePageContent }>(`/reference-pages/${slug}`)).data, retry: false });
  if (page.isError) return <NotFound />;
  return (
    <div className="sw-in">
      <Link to="/admin/guide" className="mb-3 inline-flex items-center gap-1.5 text-[13px] font-semibold text-muted hover:text-ink"><ArrowLeft className="size-4" />{t('guide.back')}</Link>
      {page.data ? (
        <>
          <h1 className="mb-6 text-[26px] font-bold tracking-tight text-ink">{page.data.title}</h1>
          <Card className="p-6 sm:p-8"><Markdown source={page.data.markdown} /></Card>
          {page.data.source?.document && <p className="mt-3 text-[12px] text-muted">{t('prog.source', { document: page.data.source.document, page: page.data.source.page ?? '' })}</p>}
        </>
      ) : <div className="space-y-4"><Skeleton className="h-10 w-96" /><Skeleton className="h-[60vh]" /></div>}
    </div>
  );
}
