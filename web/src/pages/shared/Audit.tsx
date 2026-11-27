import { useState } from 'react';
import { useInfiniteQuery, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Headset, ScrollText } from 'lucide-react';
import { useSignedIn } from '../../app/access';
import { Avatar, Badge, Button, Card, cx, EmptyState, PageHeader, Skeleton, useToast } from '../../components/ui';
import { api } from '../../lib/api';
import { areaPrefix } from '../../lib/area';
import { useI18n } from '../../lib/i18n';
import { relativeTime } from '../../lib/time';
import type { MessageKey } from '../../locales/en';
import type { AuditEntry, CursorPage, SupportSessionRow } from '../../lib/types';

const FILTERS = ['', 'auth', 'roles', 'consent', 'support', 'branding'] as const;

export default function Audit() {
  const session = useSignedIn();
  const { t, tx, locale } = useI18n();
  const [filter, setFilter] = useState<(typeof FILTERS)[number]>('');

  const log = useInfiniteQuery({
    queryKey: ['audit', session.area, filter],
    initialPageParam: '',
    queryFn: ({ pageParam }) => {
      const qs = new URLSearchParams();
      if (filter) qs.set('action', filter + '.');
      if (pageParam) qs.set('cursor', pageParam);
      return api<CursorPage<AuditEntry>>(`${areaPrefix()}/audit?${qs}`);
    },
    getNextPageParam: (last) => last.next_cursor ?? undefined,
  });
  const entries = log.data?.pages.flatMap((p) => p.data) ?? [];

  return (
    <div className="sw-in">
      <PageHeader title={t('audit.title')} lead={t('audit.lead')} />

      {session.area === 'tenant' && <SupportAccess />}

      <div className="mb-4 flex flex-wrap gap-2" role="toolbar">
        {FILTERS.map((f) => (
          <button key={f || 'all'} onClick={() => setFilter(f)} aria-pressed={filter === f}
            className={cx('h-8 rounded-full border px-3.5 text-[13px] font-semibold transition', filter === f ? 'border-brand bg-brand text-white' : 'border-line bg-surface text-muted hover:text-ink')}>
            {f ? t(`audit.filter.${f}` as MessageKey) : t('audit.all')}
          </button>
        ))}
      </div>

      <Card className="overflow-hidden">
        {log.isPending ? (
          <div className="space-y-3 p-5">{Array.from({ length: 6 }, (_, i) => <Skeleton key={i} className="h-10" />)}</div>
        ) : entries.length === 0 ? (
          <EmptyState icon={<ScrollText className="size-6" />} title={t('audit.empty')} />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[640px] text-left text-[14px]">
              <thead className="border-b border-line bg-surface-2/60 text-[12px] font-bold uppercase tracking-[0.08em] text-muted">
                <tr>
                  <th className="px-5 py-3">{t('audit.what')}</th>
                  <th className="px-5 py-3">{t('audit.who')}</th>
                  <th className="px-5 py-3">{t('audit.when')}</th>
                  <th className="hidden px-5 py-3 md:table-cell">{t('audit.where')}</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-line">
                {entries.map((e) => (
                  <tr key={e.id} className="hover:bg-surface-2/50">
                    <td className="px-5 py-3.5">
                      <div className="font-semibold text-ink">{tx('action.' + e.action, e.action)}</div>
                      {e.subject_type && <div className="text-[12px] text-muted">{e.subject_type}</div>}
                    </td>
                    <td className="px-5 py-3.5">
                      <div className="flex items-center gap-2.5">
                        <Avatar name={e.actor_name ?? '·'} className="size-7 text-[11px]" />
                        <span className="text-ink">{e.actor_name ?? t('audit.system')}</span>
                        {e.support_session_id && <Badge tone="warning">{t('audit.viaSupport')}</Badge>}
                      </div>
                    </td>
                    <td className="whitespace-nowrap px-5 py-3.5 text-muted" title={new Date(e.created_at).toLocaleString(locale)}>{relativeTime(e.created_at, locale)}</td>
                    <td className="hidden px-5 py-3.5 font-mono text-[12px] text-muted md:table-cell">{e.ip_address ?? '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>
      {log.hasNextPage && (
        <div className="mt-4 flex justify-center">
          <Button variant="secondary" loading={log.isFetchingNextPage} onClick={() => log.fetchNextPage()}>{t('audit.loadMore')}</Button>
        </div>
      )}
    </div>
  );
}

/** Providers see every HQ support view of their academy and can end one at any time. */
function SupportAccess() {
  const { t, locale } = useI18n();
  const toast = useToast();
  const qc = useQueryClient();
  const sessions = useQuery({ queryKey: ['support-sessions'], queryFn: async () => (await api<{ data: SupportSessionRow[] }>('/support-sessions')).data });
  const end = useMutation({
    mutationFn: (id: string) => api(`/support-sessions/${id}`, { method: 'DELETE' }),
    onSuccess: () => { toast(t('audit.endedToast')); qc.invalidateQueries({ queryKey: ['support-sessions'] }); qc.invalidateQueries({ queryKey: ['audit'] }); },
  });

  return (
    <Card className="mb-8 p-5">
      <div className="flex items-start gap-3">
        <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-[#FDF4E1] text-[#8A5A00] dark:bg-[#8A5A00]/25 dark:text-[#F5C46B]"><Headset className="size-5" /></span>
        <div className="min-w-0 flex-1">
          <h2 className="text-[16px] font-bold text-ink">{t('audit.supportTitle')}</h2>
          <p className="text-[14px] text-muted">{t('audit.supportLead')}</p>
          <div className="mt-4 space-y-2">
            {sessions.isPending && <Skeleton className="h-12" />}
            {sessions.data?.length === 0 && <p className="text-[14px] text-muted">{t('audit.supportEmpty')}</p>}
            {sessions.data?.slice(0, 5).map((s) => (
              <div key={s.id} className="flex flex-wrap items-center gap-3 rounded-xl border border-line px-3.5 py-2.5">
                <div className="min-w-0 flex-1">
                  <div className="text-[14px] font-semibold text-ink">{s.staff_name}</div>
                  <div className="truncate text-[13px] text-muted">{s.reason}</div>
                </div>
                {s.active ? (
                  <>
                    <Badge tone="warning">{t('audit.active')} · {t('audit.until', { time: new Date(s.expires_at).toLocaleTimeString(locale, { hour: '2-digit', minute: '2-digit' }) })}</Badge>
                    <Button size="sm" variant="danger" loading={end.isPending} onClick={() => end.mutate(s.id)}>{t('audit.end')}</Button>
                  </>
                ) : (
                  <Badge>{t('audit.ended')} · {relativeTime(s.ended_at ?? s.expires_at, locale)}</Badge>
                )}
              </div>
            ))}
          </div>
        </div>
      </div>
    </Card>
  );
}
