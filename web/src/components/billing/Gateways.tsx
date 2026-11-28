import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { CheckCircle2, Copy, PlugZap, XCircle } from 'lucide-react';
import type { GatewaySetting } from '@simuwork/core';
import { Badge, Button, Card, cx, Field, inputClass, Skeleton, Switch, useToast } from '../ui';
import { api, ApiError } from '../../lib/api';
import { useI18n } from '../../lib/i18n';

const KEY = ['hq-gateways'] as const;

/** Super Admin: switch payment gateways on or off, test or live, keys (write-only), connection test. */
export function Gateways() {
  const { t } = useI18n();
  const list = useQuery({ queryKey: KEY, queryFn: async () => (await api<{ data: GatewaySetting[] }>('/platform/billing/gateways')).data });
  return (
    <section className="max-w-4xl space-y-4">
      <div>
        <h2 className="text-[17px] font-bold text-ink">{t('gw.title')}</h2>
        <p className="text-[14px] text-muted">{t('gw.lead')}</p>
      </div>
      {list.data ? list.data.map((g) => <GatewayCard key={`${g.driver}:${g.mode}:${g.enabled}:${g.fields.map((f) => f.hint).join()}`} gateway={g} />) : <Skeleton className="h-64" />}
    </section>
  );
}

function GatewayCard({ gateway }: { gateway: GatewaySetting }) {
  const { t, locale } = useI18n();
  const toast = useToast();
  const qc = useQueryClient();
  const [keys, setKeys] = useState<Record<string, string>>({});
  const [mode, setMode] = useState(gateway.mode);
  const update = useMutation({
    mutationFn: (body: Record<string, unknown>) => api<{ data: GatewaySetting }>(`/platform/billing/gateways/${gateway.driver}`, { method: 'PATCH', body }),
    onSuccess: (res) => { qc.setQueryData<GatewaySetting[]>(KEY, (old) => old?.map((g) => (g.driver === res.data.driver ? res.data : g))); setKeys({}); toast(t('acad.saved')); },
    onError: (e) => toast(e instanceof ApiError ? Object.values(e.errors)[0]?.[0] ?? e.message : t('common.error'), 'error'),
  });
  const test = useMutation({
    mutationFn: () => api<{ data: GatewaySetting }>(`/platform/billing/gateways/${gateway.driver}/test`, { method: 'POST' }),
    onSuccess: (res) => qc.setQueryData<GatewaySetting[]>(KEY, (old) => old?.map((g) => (g.driver === res.data.driver ? res.data : g))),
    onError: (e) => toast(e instanceof ApiError ? Object.values(e.errors)[0]?.[0] ?? e.message : t('common.error'), 'error'),
  });
  const dirty = Object.values(keys).some((v) => v !== '') || mode !== gateway.mode;

  return (
    <Card className="p-5">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-3">
          <span className="flex size-10 items-center justify-center rounded-xl bg-brand-soft text-brand"><PlugZap className="size-5" /></span>
          <div>
            <h3 className="text-[15px] font-bold text-ink">{gateway.label}</h3>
            <div className="flex gap-1.5">
              <Badge tone={gateway.enabled ? 'success' : 'neutral'}>{t(gateway.enabled ? 'prov.on' : 'prov.off')}</Badge>
              <Badge tone={gateway.mode === 'live' ? 'brand' : 'warning'}>{t(gateway.mode === 'live' ? 'gw.live' : 'gw.test')}</Badge>
            </div>
          </div>
        </div>
        <Switch checked={gateway.enabled} disabled={update.isPending || (!gateway.complete && !gateway.enabled)} label={t('gw.enable', { name: gateway.label })} onChange={(enabled) => update.mutate({ enabled })} />
      </div>

      <div className="mt-4 grid gap-4 sm:grid-cols-2">
        {gateway.fields.map((f) => (
          <Field key={f.key} label={f.label} type={f.secret ? 'password' : 'text'} autoComplete="off" value={keys[f.key] ?? ''} placeholder={f.set ? (f.hint ?? '') : t('gw.notSet')} onChange={(e) => setKeys({ ...keys, [f.key]: e.target.value })} hint={f.secret && f.set ? t('gw.keepHint') : undefined} />
        ))}
        <div>
          <label htmlFor={`mode-${gateway.driver}`} className="mb-1.5 block text-[13px] font-semibold text-ink">{t('gw.mode')}</label>
          <select id={`mode-${gateway.driver}`} value={mode} onChange={(e) => setMode(e.target.value as 'test' | 'live')} className={inputClass}>
            <option value="test">{t('gw.test')}</option>
            <option value="live">{t('gw.live')}</option>
          </select>
        </div>
      </div>

      <div className="mt-4 rounded-xl bg-surface-2/60 px-3 py-2.5 text-[13px]">
        <div className="font-semibold text-ink">{t('gw.notify')}</div>
        <div className="mt-1 flex items-center gap-2">
          <code className="min-w-0 flex-1 truncate text-muted">{gateway.notify_url}</code>
          <button type="button" aria-label={t('prov.copy')} onClick={() => void navigator.clipboard?.writeText(gateway.notify_url).then(() => toast(t('prov.copied')))} className="rounded-lg p-1.5 text-muted hover:bg-surface"><Copy className="size-4" /></button>
        </div>
      </div>

      <div className="mt-4 flex flex-wrap items-center gap-3">
        <Button disabled={!dirty} loading={update.isPending} onClick={() => update.mutate({ credentials: Object.fromEntries(Object.entries(keys).filter(([, v]) => v !== '')), mode })}>{t('gw.save')}</Button>
        <Button variant="secondary" disabled={!gateway.complete} loading={test.isPending} onClick={() => test.mutate()}>{t('gw.testConnection')}</Button>
        {gateway.last_test && (
          <span className={cx('inline-flex items-center gap-1.5 text-[13px]', gateway.last_test.ok ? 'text-[#0B7A5C] dark:text-[#5FD3B1]' : 'text-[#B42328] dark:text-[#FF9A9E]')}>
            {gateway.last_test.ok ? <CheckCircle2 className="size-4" /> : <XCircle className="size-4" />}
            {gateway.last_test.message} · {new Date(gateway.last_test.at).toLocaleString(locale)}
          </span>
        )}
      </div>
    </Card>
  );
}
