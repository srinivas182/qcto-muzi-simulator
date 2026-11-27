import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Building2, Search } from 'lucide-react';
import type { ProviderRow } from '@simuwork/core';
import type { MessageKey } from '@simuwork/core/locales/en';
import { useSignedIn } from '../../app/access';
import { Badge, Card, cx, EmptyState, inputClass, PageHeader, Skeleton, Switch, useToast } from '../../components/ui';
import { api, ApiError } from '../../lib/api';
import { useI18n } from '../../lib/i18n';
import { can } from '../../lib/session';

const KEY = ['hq-providers'] as const;
const STATUS_TONE: Record<string, 'success' | 'brand' | 'warning' | 'neutral'> = { active: 'success', trial: 'brand', suspended: 'warning', archived: 'neutral' };

/** HQ list of training providers with per-provider switches. (Full provider management arrives in Sprint 3.) */
export default function Providers() {
  const { t } = useI18n();
  const session = useSignedIn();
  const toast = useToast();
  const qc = useQueryClient();
  const [q, setQ] = useState('');
  const list = useQuery({ queryKey: KEY, queryFn: async () => (await api<{ data: ProviderRow[] }>('/platform/providers')).data });
  const toggle = useMutation({
    mutationFn: ({ id, enabled }: { id: string; enabled: boolean }) => api<{ data: ProviderRow }>(`/platform/providers/${id}/programme-additions`, { method: 'PATCH', body: { enabled } }),
    onSuccess: (res) => {
      qc.setQueryData<ProviderRow[]>(KEY, (old) => old?.map((p) => (p.id === res.data.id ? res.data : p)));
      toast(t(res.data.programme_additions ? 'prov.additionsOn' : 'prov.additionsOff', { name: res.data.name }));
    },
    onError: (e) => toast(e instanceof ApiError ? e.message : t('common.error'), 'error'),
  });
  const manage = can(session, 'providers.manage');
  const shown = useMemo(() => (list.data ?? []).filter((p) => !q || `${p.name} ${p.slug}`.toLowerCase().includes(q.toLowerCase())), [list.data, q]);

  return (
    <div className="sw-in">
      <PageHeader title={t('prov.title')} lead={t('prov.lead')} />
      <div className="relative mb-6 max-w-sm">
        <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted" aria-hidden />
        <input value={q} onChange={(e) => setQ(e.target.value)} placeholder={t('prov.search')} aria-label={t('prov.search')} className={cx(inputClass, 'h-10 pl-9 text-[14px]')} />
      </div>
      <Card className="overflow-hidden p-0">
        {list.isPending ? (
          <div className="space-y-2 p-5">{Array.from({ length: 4 }, (_, i) => <Skeleton key={i} className="h-12" />)}</div>
        ) : shown.length === 0 ? (
          <EmptyState icon={<Building2 className="size-6" />} title={t('prov.none')} />
        ) : (
          <table className="w-full text-left text-[14px]">
            <thead className="border-b border-line bg-surface-2/60 text-[12px] font-bold uppercase tracking-[0.08em] text-muted">
              <tr>
                <th className="px-5 py-3">{t('prov.col.provider')}</th>
                <th className="hidden px-5 py-3 sm:table-cell">{t('prov.col.status')}</th>
                <th className="px-5 py-3 text-right">{t('prov.col.additions')}</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-line">
              {shown.map((p) => (
                <tr key={p.id}>
                  <td className="px-5 py-3.5">
                    <div className="font-semibold text-ink">{p.name}</div>
                    <div className="text-[12px] text-muted">{p.slug}</div>
                  </td>
                  <td className="hidden px-5 py-3.5 sm:table-cell"><Badge tone={STATUS_TONE[p.status] ?? 'neutral'}>{t(`prov.status.${p.status}` as MessageKey)}</Badge></td>
                  <td className="px-5 py-3.5">
                    <div className="flex items-center justify-end gap-3">
                      <span className="hidden text-[13px] text-muted md:inline">{t(p.programme_additions ? 'prov.on' : 'prov.off')}</span>
                      <Switch checked={p.programme_additions} disabled={!manage || toggle.isPending} label={t('prov.additionsFor', { name: p.name })} onChange={(enabled) => toggle.mutate({ id: p.id, enabled })} />
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </Card>
      <p className="mt-3 max-w-2xl text-[13px] text-muted">{t('prov.additionsHelp')}</p>
    </div>
  );
}
