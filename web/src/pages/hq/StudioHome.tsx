import { Link } from 'react-router';
import { useQuery } from '@tanstack/react-query';
import { BookOpen, Box, ChevronRight } from 'lucide-react';
import type { StudioActivity } from '@simuwork/core';
import { Badge, Card, cx, EmptyState, PageHeader, Skeleton } from '../../components/ui';
import { api } from '../../lib/api';
import { useI18n } from '../../lib/i18n';

type Group = { code: string; title: string; activities: (StudioActivity & { steps?: number })[] };

/** Scenario Studio / Course Studio home: every scenario (or lesson) across published programmes. */
export default function StudioHome({ kind }: { kind: 'scenario' | 'lesson' }) {
  const { t } = useI18n();
  const q = useQuery({ queryKey: ['studio-all', kind], queryFn: () => api<{ data: Group[] }>(`/platform/activities?kind=${kind}`) });
  const Icon = kind === 'scenario' ? Box : BookOpen;
  return (
    <div className="sw-in">
      <PageHeader title={t(kind === 'scenario' ? 'nav.hq.studio.scenarios' : 'nav.hq.studio.courses')} lead={t(kind === 'scenario' ? 'ss.homeLead' : 'ss.homeLeadCourses')} />
      {!q.data ? <Skeleton className="h-64 rounded-2xl" /> : q.data.data.length === 0 ? <Card><EmptyState icon={<Icon className="size-6" />} title={t('cs.notPublished')} body={t('cs.notPublishedBody')} /></Card> : (
        <div className="space-y-5">
          {q.data.data.map((g) => (
            <section key={g.code}>
              <div className="mb-2 flex items-center justify-between">
                <h2 className="text-[15px] font-bold text-ink">{g.title}</h2>
                <Link to={`/hq/programmes/${g.code}?tab=content`} className="text-[13px] font-semibold text-brand hover:underline">{t(kind === 'scenario' ? 'cs.newScenario' : 'cs.newLesson')}</Link>
              </div>
              {g.activities.length === 0 ? <p className="rounded-xl bg-surface-2 px-4 py-3 text-[13px] text-muted">{t('ss.noneYet')}</p> : (
                <Card className="overflow-hidden p-0">
                  <ul className="divide-y divide-line">
                    {g.activities.map((a) => (
                      <li key={a.id}>
                        <Link to={`/hq/programmes/${g.code}/${kind === 'scenario' ? 'scenarios' : 'lessons'}/${a.id}`} className="flex items-center gap-3 px-5 py-3.5 hover:bg-surface-2">
                          <span className={cx('flex size-9 shrink-0 items-center justify-center rounded-xl', kind === 'scenario' ? 'bg-nav text-white' : 'bg-brand-soft text-brand')}><Icon className="size-4" /></span>
                          <span className="min-w-0 flex-1">
                            <span className="block font-semibold text-ink">{a.title}</span>
                            <span className="block text-[12px] text-muted">{a.code} · {a.outcomes.join(', ')}</span>
                          </span>
                          <Badge tone={a.status === 'published' ? (a.has_draft ? 'warning' : 'success') : 'neutral'}>{t(a.status === 'published' ? (a.has_draft ? 'cs.status.changed' : 'cs.status.published') : 'cs.status.draft')}</Badge>
                          <ChevronRight className="size-4 text-muted" />
                        </Link>
                      </li>
                    ))}
                  </ul>
                </Card>
              )}
            </section>
          ))}
        </div>
      )}
    </div>
  );
}

