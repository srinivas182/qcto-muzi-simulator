import { useState } from 'react';
import { Link, useParams } from 'react-router';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Archive, ArrowLeft, Globe, MailPlus, PauseCircle, PlayCircle } from 'lucide-react';
import type { AuditEntry, CursorPage, ProviderForHq, ProviderStatus } from '@simuwork/core';
import type { MessageKey } from '@simuwork/core/locales/en';
import { useSignedIn } from '../../app/access';
import { NotFound } from '../../app/states';
import { ProviderDetailsForm } from '../../components/ProviderDetailsForm';
import { Badge, Button, Card, cx, Drawer, Field, inputClass, Skeleton, Switch, useToast } from '../../components/ui';
import { api, ApiError } from '../../lib/api';
import { useI18n } from '../../lib/i18n';
import { can } from '../../lib/session';
import { relativeTime } from '../../lib/time';
import { PROVIDERS_KEY, STATUS_TONE } from './providerShared';

type Tab = 'details' | 'people' | 'activity';
const NEXT: Record<ProviderStatus, ProviderStatus[]> = { trial: ['active', 'suspended', 'archived'], active: ['suspended', 'archived'], suspended: ['active', 'archived'], archived: [] };

/** One training provider, for HQ: details, people, activity, status and switches. */
export default function ProviderDetail() {
  const { id = '' } = useParams();
  const session = useSignedIn();
  const { t, locale } = useI18n();
  const toast = useToast();
  const qc = useQueryClient();
  const [tab, setTab] = useState<Tab>('details');
  const [statusTo, setStatusTo] = useState<ProviderStatus | null>(null);
  const [inviting, setInviting] = useState(false);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const key = ['hq-provider', id] as const;
  const query = useQuery({ queryKey: key, queryFn: async () => (await api<{ data: ProviderForHq }>(`/platform/providers/${id}`)).data, retry: false });
  const manage = can(session, 'providers.manage');

  const save = async (changes: Record<string, unknown>) => {
    setErrors({});
    try {
      const res = await api<{ data: ProviderForHq }>(`/platform/providers/${id}`, { method: 'PATCH', body: changes });
      qc.setQueryData(key, res.data);
      void qc.invalidateQueries({ queryKey: PROVIDERS_KEY });
      toast(t('acad.saved'));
      return true;
    } catch (e) {
      if (e instanceof ApiError) setErrors(e.errors);
      toast(e instanceof ApiError ? e.message : t('common.error'), 'error');
      return false;
    }
  };
  const additions = useMutation({
    mutationFn: (enabled: boolean) => api(`/platform/providers/${id}/programme-additions`, { method: 'PATCH', body: { enabled } }),
    onSuccess: () => { void qc.invalidateQueries({ queryKey: key }); void qc.invalidateQueries({ queryKey: PROVIDERS_KEY }); },
  });

  if (query.isError) return <NotFound />;
  const p = query.data;
  if (!p) return <div className="space-y-4"><Skeleton className="h-10 w-80" /><Skeleton className="h-64" /></div>;
  const host = p.domains.find((d) => d.primary)?.host ?? p.domains[0]?.host;

  return (
    <div className="sw-in">
      <Link to="/hq/providers" className="mb-3 inline-flex items-center gap-1.5 text-[13px] font-semibold text-muted hover:text-ink"><ArrowLeft className="size-4" />{t('prov.title')}</Link>
      <div className="mb-6 flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        <div className="min-w-0">
          <div className="flex flex-wrap items-center gap-2">
            <h1 className="text-[26px] font-bold tracking-tight text-ink">{p.name}</h1>
            <Badge tone={STATUS_TONE[p.status]}>{t(`prov.status.${p.status}` as MessageKey)}</Badge>
          </div>
          {host && <p className="mt-1 inline-flex items-center gap-1.5 text-[14px] text-muted"><Globe className="size-4" />{host}</p>}
          {p.status_reason && <p className="mt-1 text-[13px] text-muted">{t('prov.lastChange', { reason: p.status_reason, when: p.status_changed_at ? relativeTime(p.status_changed_at, locale) : '' })}</p>}
        </div>
        {manage && (
          <div className="flex flex-wrap gap-2">
            <Button variant="secondary" icon={<MailPlus className="size-4" />} onClick={() => setInviting(true)} disabled={p.status === 'archived'}>{t('prov.inviteAdmin')}</Button>
            {NEXT[p.status].map((s) => (
              <Button key={s} variant={s === 'active' ? 'primary' : 'secondary'} icon={s === 'active' ? <PlayCircle className="size-4" /> : s === 'suspended' ? <PauseCircle className="size-4" /> : <Archive className="size-4" />} onClick={() => setStatusTo(s)}>
                {t(`prov.action.${s}` as MessageKey)}
              </Button>
            ))}
          </div>
        )}
      </div>

      <div className="mb-5 grid gap-3 sm:grid-cols-3">
        <Stat label={t('prov.stat.people')} value={p.people.length} />
        <Stat label={t('prov.stat.centres')} value={p.centres} />
        <Card className="flex items-center justify-between gap-3 p-4">
          <div>
            <div className="text-[13px] font-semibold text-ink">{t('prov.col.additions')}</div>
            <div className="text-[12px] text-muted">{t(p.programme_additions ? 'prov.on' : 'prov.off')}</div>
          </div>
          <Switch checked={p.programme_additions} disabled={!manage || additions.isPending} label={t('prov.additionsFor', { name: p.name })} onChange={(v) => additions.mutate(v)} />
        </Card>
      </div>

      <div role="tablist" className="mb-5 inline-flex rounded-xl bg-surface-2 p-1">
        {(['details', 'people', 'activity'] as Tab[]).map((k) => (
          <button key={k} role="tab" aria-selected={tab === k} onClick={() => setTab(k)} className={cx('h-9 rounded-lg px-4 text-[13px] font-semibold transition', tab === k ? 'bg-surface text-ink shadow-sm' : 'text-muted hover:text-ink')}>
            {t(`prov.tab.${k}` as MessageKey)}
          </button>
        ))}
      </div>

      {tab === 'details' && <ProviderDetailsForm key={p.status_changed_at ?? p.id} provider={p} scope="hq" editable={manage && p.status !== 'archived'} onSave={save} errors={errors} />}
      {tab === 'people' && (
        <Card className="overflow-hidden p-0">
          <table className="w-full text-left text-[14px]">
            <thead className="border-b border-line bg-surface-2/60 text-[12px] font-bold uppercase tracking-[0.08em] text-muted">
              <tr><th className="px-5 py-3">{t('prov.col.person')}</th><th className="px-5 py-3">{t('prov.col.roles')}</th><th className="hidden px-5 py-3 md:table-cell">{t('prov.col.lastSignIn')}</th></tr>
            </thead>
            <tbody className="divide-y divide-line">
              {p.people.length === 0 && <tr><td colSpan={3} className="px-5 py-6 text-center text-muted">{t('prov.noPeople')}</td></tr>}
              {p.people.map((u) => (
                <tr key={u.id}>
                  <td className="px-5 py-3"><div className="font-semibold text-ink">{u.name}</div><div className="text-[12px] text-muted">{u.email ?? u.phone}</div></td>
                  <td className="px-5 py-3"><div className="flex flex-wrap gap-1">{u.roles.map((r) => <Badge key={r} tone="neutral">{r}</Badge>)}</div></td>
                  <td className="hidden px-5 py-3 text-muted md:table-cell">{u.last_login_at ? relativeTime(u.last_login_at, locale) : t('prov.never')}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </Card>
      )}
      {tab === 'activity' && <Activity tenantId={p.id} />}

      <StatusDrawer provider={p} to={statusTo} onClose={() => setStatusTo(null)} onDone={(data) => { qc.setQueryData(key, data); void qc.invalidateQueries({ queryKey: PROVIDERS_KEY }); }} />
      <InviteDrawer providerId={p.id} open={inviting} onClose={() => setInviting(false)} />
    </div>
  );
}

function Stat({ label, value }: { label: string; value: number }) {
  return <Card className="p-4"><div className="text-[24px] font-bold tabular-nums text-ink">{value}</div><div className="text-[12px] font-semibold text-muted">{label}</div></Card>;
}

function Activity({ tenantId }: { tenantId: string }) {
  const { t, locale } = useI18n();
  const log = useQuery({ queryKey: ['hq-provider-audit', tenantId], queryFn: () => api<CursorPage<AuditEntry>>(`/platform/audit?tenant_id=${tenantId}`) });
  if (!log.data) return <Skeleton className="h-64" />;
  return (
    <Card className="p-0">
      <ul className="divide-y divide-line">
        {log.data.data.length === 0 && <li className="px-5 py-6 text-center text-muted">{t('audit.empty')}</li>}
        {log.data.data.map((e) => (
          <li key={e.id} className="flex flex-col gap-0.5 px-5 py-3 sm:flex-row sm:items-center sm:gap-4">
            <span className="w-32 shrink-0 text-[12px] text-muted">{relativeTime(e.created_at, locale)}</span>
            <span className="font-mono text-[12px] font-semibold text-ink">{e.action}</span>
            <span className="text-[13px] text-muted">{e.actor_name ?? '—'}{typeof e.meta?.reason === 'string' ? ` · ${e.meta.reason}` : ''}</span>
          </li>
        ))}
      </ul>
    </Card>
  );
}

function StatusDrawer({ provider, to, onClose, onDone }: { provider: ProviderForHq; to: ProviderStatus | null; onClose: () => void; onDone: (p: ProviderForHq) => void }) {
  const { t } = useI18n();
  const toast = useToast();
  const [reason, setReason] = useState('');
  const [confirm, setConfirm] = useState('');
  const change = useMutation({
    mutationFn: () => api<{ data: ProviderForHq }>(`/platform/providers/${provider.id}/status`, { method: 'POST', body: { status: to, reason: reason.trim() } }),
    onSuccess: (res) => { onDone(res.data); toast(t('prov.statusChanged', { status: t(`prov.status.${res.data.status}` as MessageKey) })); close(); },
    onError: (e) => toast(e instanceof ApiError ? e.message : t('common.error'), 'error'),
  });
  const close = () => { setReason(''); setConfirm(''); onClose(); };
  const blocked = reason.trim().length < 5 || (to === 'archived' && confirm.trim() !== provider.name);

  return (
    <Drawer
      open={to !== null}
      onClose={close}
      title={to ? t(`prov.action.${to}` as MessageKey) : ''}
      subtitle={to ? t(`prov.effect.${to}` as MessageKey) : ''}
      footer={<><Button variant="ghost" onClick={close}>{t('common.cancel')}</Button><Button disabled={blocked} loading={change.isPending} onClick={() => change.mutate()}>{to ? t(`prov.action.${to}` as MessageKey) : ''}</Button></>}
    >
      <div className="space-y-4">
        <div>
          <label htmlFor="status-reason" className="mb-1.5 block text-[13px] font-semibold text-ink">{t('prov.reason')}</label>
          <textarea id="status-reason" rows={3} value={reason} onChange={(e) => setReason(e.target.value)} placeholder={t('prov.reasonHint')} className={cx(inputClass, 'h-auto py-2.5')} />
        </div>
        {to === 'archived' && <Field label={t('prov.typeName', { name: provider.name })} value={confirm} onChange={(e) => setConfirm(e.target.value)} />}
      </div>
    </Drawer>
  );
}

function InviteDrawer({ providerId, open, onClose }: { providerId: string; open: boolean; onClose: () => void }) {
  const { t } = useI18n();
  const toast = useToast();
  const [v, setV] = useState({ name: '', email: '' });
  const send = useMutation({
    mutationFn: () => api<{ invite_url: string }>(`/platform/providers/${providerId}/invitations`, { method: 'POST', body: v }),
    onSuccess: () => { toast(t('prov.inviteSent', { email: v.email })); setV({ name: '', email: '' }); onClose(); },
    onError: (e) => toast(e instanceof ApiError ? e.message : t('common.error'), 'error'),
  });
  return (
    <Drawer open={open} onClose={onClose} title={t('prov.inviteAdmin')} subtitle={t('prov.inviteAdminLead')}
      footer={<><Button variant="ghost" onClick={onClose}>{t('common.cancel')}</Button><Button disabled={!v.name || !v.email} loading={send.isPending} onClick={() => send.mutate()}>{t('prov.sendInvite')}</Button></>}>
      <div className="space-y-4">
        <Field label={t('prov.f.adminName')} value={v.name} onChange={(e) => setV({ ...v, name: e.target.value })} />
        <Field label={t('prov.f.adminEmail')} type="email" value={v.email} onChange={(e) => setV({ ...v, email: e.target.value })} />
      </div>
    </Drawer>
  );
}
