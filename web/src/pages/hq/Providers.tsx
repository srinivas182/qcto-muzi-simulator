import { useMemo, useState } from 'react';
import { Link } from 'react-router';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Building2, Check, Copy, Plus, Search } from 'lucide-react';
import type { ProviderForHq, ProviderRow, ProviderStatus } from '@simuwork/core';
import type { MessageKey } from '@simuwork/core/locales/en';
import { useSignedIn } from '../../app/access';
import { AccreditationBadge } from '../../components/ProviderDetailsForm';
import { Badge, Button, Card, cx, Drawer, EmptyState, Field, inputClass, PageHeader, Skeleton, Switch, useToast } from '../../components/ui';
import { api, ApiError } from '../../lib/api';
import { useI18n } from '../../lib/i18n';
import { can } from '../../lib/session';
import { PROVIDERS_KEY, STATUS_TONE } from './providerShared';


/** HQ list of training providers: status, accreditation, the own-outcomes switch, and onboarding. */
export default function Providers() {
  const { t } = useI18n();
  const session = useSignedIn();
  const toast = useToast();
  const qc = useQueryClient();
  const [q, setQ] = useState('');
  const [status, setStatus] = useState<ProviderStatus | 'all'>('all');
  const [creating, setCreating] = useState(false);
  const list = useQuery({ queryKey: PROVIDERS_KEY, queryFn: async () => (await api<{ data: ProviderRow[] }>('/platform/providers')).data });
  const toggle = useMutation({
    mutationFn: ({ id, enabled }: { id: string; enabled: boolean }) => api<{ data: ProviderRow }>(`/platform/providers/${id}/programme-additions`, { method: 'PATCH', body: { enabled } }),
    onSuccess: (res) => {
      qc.setQueryData<ProviderRow[]>(PROVIDERS_KEY, (old) => old?.map((p) => (p.id === res.data.id ? res.data : p)));
      toast(t(res.data.programme_additions ? 'prov.additionsOn' : 'prov.additionsOff', { name: res.data.name }));
    },
    onError: (e) => toast(e instanceof ApiError ? e.message : t('common.error'), 'error'),
  });
  const manage = can(session, 'providers.manage');
  const shown = useMemo(
    () => (list.data ?? []).filter((p) => (status === 'all' || p.status === status) && (!q || `${p.name} ${p.slug}`.toLowerCase().includes(q.toLowerCase()))),
    [list.data, q, status],
  );
  const counts = useMemo(() => {
    const c: Record<string, number> = { all: list.data?.length ?? 0 };
    for (const p of list.data ?? []) c[p.status] = (c[p.status] ?? 0) + 1;
    return c;
  }, [list.data]);

  return (
    <div className="sw-in">
      <PageHeader
        title={t('prov.title')}
        lead={t('prov.lead')}
        actions={manage ? <Button icon={<Plus className="size-4" />} onClick={() => setCreating(true)}>{t('prov.new')}</Button> : undefined}
      />
      <div className="mb-5 flex flex-col gap-3 md:flex-row md:items-center">
        <div className="relative max-w-sm flex-1">
          <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted" aria-hidden />
          <input value={q} onChange={(e) => setQ(e.target.value)} placeholder={t('prov.search')} aria-label={t('prov.search')} className={cx(inputClass, 'h-10 pl-9 text-[14px]')} />
        </div>
        <div role="radiogroup" aria-label={t('prov.col.status')} className="inline-flex flex-wrap rounded-xl bg-surface-2 p-1">
          {(['all', 'trial', 'active', 'suspended', 'archived'] as const).map((s) => (
            <button key={s} role="radio" aria-checked={status === s} onClick={() => setStatus(s)} className={cx('h-8 rounded-lg px-3 text-[13px] font-semibold transition', status === s ? 'bg-surface text-ink shadow-sm' : 'text-muted hover:text-ink')}>
              {s === 'all' ? t('cov.filter.all') : t(`prov.status.${s}` as MessageKey)} <span className="tabular-nums opacity-60">{counts[s] ?? 0}</span>
            </button>
          ))}
        </div>
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
                <th className="hidden px-5 py-3 lg:table-cell">{t('acad.accreditation')}</th>
                <th className="px-5 py-3 text-right">{t('prov.col.additions')}</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-line">
              {shown.map((p) => (
                <tr key={p.id} className="transition hover:bg-surface-2/40">
                  <td className="px-5 py-3.5">
                    <Link to={`/hq/providers/${p.id}`} className="font-semibold text-ink hover:text-brand">{p.name}</Link>
                    <div className="text-[12px] text-muted">{p.slug}</div>
                  </td>
                  <td className="hidden px-5 py-3.5 sm:table-cell"><Badge tone={STATUS_TONE[p.status]}>{t(`prov.status.${p.status}` as MessageKey)}</Badge></td>
                  <td className="hidden px-5 py-3.5 lg:table-cell">{p.accreditation && <AccreditationBadge state={p.accreditation} />}</td>
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
      <NewProvider open={creating} onClose={() => setCreating(false)} />
    </div>
  );
}

function NewProvider({ open, onClose }: { open: boolean; onClose: () => void }) {
  const { t } = useI18n();
  const qc = useQueryClient();
  const empty = { name: '', slug: '', admin_name: '', admin_email: '', accreditation_number: '', accreditation_expires_at: '' };
  const [v, setV] = useState(empty);
  const [slugTouched, setSlugTouched] = useState(false);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [invite, setInvite] = useState<string | null>(null);
  const [copied, setCopied] = useState(false);
  const slug = slugTouched ? v.slug : v.name.toLowerCase().normalize('NFKD').replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 40);
  const create = useMutation({
    mutationFn: () => api<{ data: ProviderForHq; invite_url: string }>('/platform/providers', {
      method: 'POST',
      body: { ...v, slug, accreditation_number: v.accreditation_number || null, accreditation_expires_at: v.accreditation_expires_at || null },
    }),
    onSuccess: (res) => {
      void qc.invalidateQueries({ queryKey: PROVIDERS_KEY });
      setInvite(res.invite_url);
    },
    onError: (e) => setErrors(e instanceof ApiError ? e.errors : {}),
  });
  const close = () => { onClose(); setV(empty); setSlugTouched(false); setErrors({}); setInvite(null); setCopied(false); };
  const set = (k: keyof typeof empty) => (e: React.ChangeEvent<HTMLInputElement>) => setV({ ...v, [k]: e.target.value });

  return (
    <Drawer
      open={open}
      onClose={close}
      title={invite ? t('prov.created') : t('prov.new')}
      subtitle={invite ? t('prov.createdLead', { email: v.admin_email }) : t('prov.newLead')}
      footer={invite ? <Button onClick={close}>{t('common.done')}</Button> : (
        <>
          <Button variant="ghost" onClick={close}>{t('common.cancel')}</Button>
          <Button loading={create.isPending} disabled={!v.name || !v.admin_name || !v.admin_email} onClick={() => { setErrors({}); create.mutate(); }}>{t('prov.create')}</Button>
        </>
      )}
    >
      {invite ? (
        <div className="space-y-3">
          <p className="text-[14px] text-ink">{t('prov.inviteShare')}</p>
          <div className="flex gap-2">
            <input readOnly value={invite} aria-label={t('prov.inviteLink')} className={cx(inputClass, 'h-10 flex-1 font-mono text-[12px]')} onFocus={(e) => e.target.select()} />
            <Button variant="secondary" icon={copied ? <Check className="size-4" /> : <Copy className="size-4" />} onClick={() => void navigator.clipboard?.writeText(invite).then(() => setCopied(true))}>{copied ? t('prov.copied') : t('prov.copy')}</Button>
          </div>
        </div>
      ) : (
        <div className="space-y-4">
          <Field label={t('acad.f.name')} value={v.name} onChange={set('name')} error={errors.name?.[0]} autoFocus />
          <Field label={t('prov.f.slug')} value={slug} onChange={(e) => { setSlugTouched(true); setV({ ...v, slug: e.target.value }); }} hint={slug ? t('prov.f.slugHint', { slug }) : undefined} error={errors.slug?.[0]} />
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label={t('acad.f.accreditation_number')} value={v.accreditation_number} onChange={set('accreditation_number')} error={errors.accreditation_number?.[0]} />
            <Field label={t('acad.f.accreditation_expires_at')} type="date" value={v.accreditation_expires_at} onChange={set('accreditation_expires_at')} error={errors.accreditation_expires_at?.[0]} />
          </div>
          <h3 className="pt-2 text-[12px] font-bold uppercase tracking-[0.1em] text-muted">{t('prov.firstAdmin')}</h3>
          <Field label={t('prov.f.adminName')} value={v.admin_name} onChange={set('admin_name')} error={errors.admin_name?.[0]} />
          <Field label={t('prov.f.adminEmail')} type="email" value={v.admin_email} onChange={set('admin_email')} error={errors.admin_email?.[0]} />
        </div>
      )}
    </Drawer>
  );
}
