import { useState } from 'react';
import { Link } from 'react-router';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { AlertTriangle, Search } from 'lucide-react';
import { formatZar, type BillingSettingsData, type BillingSummary, type InvoiceRow } from '@simuwork/core';
import type { MessageKey } from '@simuwork/core/locales/en';
import { useSignedIn } from '../../app/access';
import { Gateways } from '../../components/billing/Gateways';
import { InvoiceDrawer } from '../../components/billing/InvoiceDrawer';
import { InvoiceTable } from '../../components/billing/InvoiceTable';
import { Button, Card, cx, Field, inputClass, PageHeader, Skeleton, useToast } from '../../components/ui';
import { api, ApiError } from '../../lib/api';
import { useI18n } from '../../lib/i18n';
import { can } from '../../lib/session';

type Tab = 'overview' | 'invoices' | 'settings';
type Status = 'all' | 'issued' | 'overdue' | 'paid' | 'credited';

/** HQ billing: money in and owed, every invoice, and (Super Admin) the seller and payment settings. */
export default function Billing() {
  const { t } = useI18n();
  const session = useSignedIn();
  const [tab, setTab] = useState<Tab>('overview');
  const tabs: Tab[] = can(session, 'billing.settings') ? ['overview', 'invoices', 'settings'] : ['overview', 'invoices'];
  return (
    <div className="sw-in">
      <PageHeader title={t('bill.title')} lead={t('bill.lead')} />
      <div role="tablist" className="mb-5 inline-flex rounded-xl bg-surface-2 p-1">
        {tabs.map((k) => <button key={k} role="tab" aria-selected={tab === k} onClick={() => setTab(k)} className={cx('h-9 rounded-lg px-4 text-[13px] font-semibold transition', tab === k ? 'bg-surface text-ink shadow-sm' : 'text-muted hover:text-ink')}>{t(`bill.tab.${k}` as MessageKey)}</button>)}
      </div>
      {tab === 'overview' && <Overview onInvoices={() => setTab('invoices')} />}
      {tab === 'invoices' && <Invoices manage={can(session, 'billing.manage')} />}
      {tab === 'settings' && <div className="space-y-10"><Settings /><Gateways /></div>}
    </div>
  );
}

function Overview({ onInvoices }: { onInvoices: () => void }) {
  const { t } = useI18n();
  const s = useQuery({ queryKey: ['hq-billing-summary'], queryFn: async () => (await api<{ data: BillingSummary }>('/platform/billing/summary')).data });
  if (!s.data) return <Skeleton className="h-64" />;
  const d = s.data;
  const tile = (label: string, value: string, tone = 'text-ink') => <Card className="p-4"><div className={cx('text-[22px] font-bold tabular-nums', tone)}>{value}</div><div className="text-[12px] font-semibold text-muted">{label}</div></Card>;
  const max = Math.max(1, ...d.seats_by_programme.map((p) => p.seats));
  return (
    <div className="space-y-5">
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        {tile(t('bill.paidMonth'), formatZar(d.paid_this_month_cents), 'text-[#0B7A5C] dark:text-[#5FD3B1]')}
        {tile(t('bill.invoicedMonth'), formatZar(d.invoiced_this_month_cents))}
        {tile(t('bill.mrr'), formatZar(d.monthly_recurring_cents))}
        {tile(t('bill.outstanding'), formatZar(d.outstanding_cents))}
        {tile(t('bill.overdue'), formatZar(d.overdue_cents), d.overdue_cents > 0 ? 'text-[#B42328] dark:text-[#FF9A9E]' : 'text-ink')}
        {tile(t('bill.seats'), String(d.active_seats))}
      </div>
      {d.blocked_providers.length > 0 && (
        <Card className="border-[#B42328]/30 p-4">
          <div className="mb-2 flex items-center gap-2 text-[14px] font-bold text-[#B42328] dark:text-[#FF9A9E]"><AlertTriangle className="size-4" />{t('bill.blocked')}</div>
          <ul className="flex flex-wrap gap-2">{d.blocked_providers.map((p) => <li key={p.id}><Link to={`/hq/providers/${p.id}`} className="rounded-full bg-surface-2 px-3 py-1 text-[13px] font-semibold text-ink hover:text-brand">{p.name}</Link></li>)}</ul>
          <Button size="sm" variant="ghost" className="mt-2" onClick={onInvoices}>{t('bill.seeOverdue')}</Button>
        </Card>
      )}
      <Card className="p-5">
        <h2 className="mb-3 text-[15px] font-bold text-ink">{t('bill.seatsByProgramme')}</h2>
        {d.seats_by_programme.length === 0 ? <p className="text-[14px] text-muted">{t('bill.noSeats')}</p> : (
          <ul className="space-y-2.5">
            {d.seats_by_programme.map((p) => (
              <li key={p.prefix} className="grid grid-cols-[3.5rem_1fr_3.5rem] items-center gap-3 text-[13px]">
                <span className="font-mono font-bold text-brand">{p.prefix}</span>
                <div className="h-2.5 overflow-hidden rounded-full bg-surface-2" title={p.title}><div className="h-full rounded-full bg-brand" style={{ width: `${(p.seats / max) * 100}%` }} /></div>
                <span className="text-right tabular-nums text-ink">{p.seats}</span>
              </li>
            ))}
          </ul>
        )}
      </Card>
    </div>
  );
}

function Invoices({ manage }: { manage: boolean }) {
  const { t, locale } = useI18n();
  const [status, setStatus] = useState<Status>('all');
  const [q, setQ] = useState('');
  const [open, setOpen] = useState<string | null>(null);
  const params = new URLSearchParams({ ...(status !== 'all' ? { status } : {}), ...(q ? { q } : {}) }).toString();
  const list = useQuery({ queryKey: ['hq-invoices', params], queryFn: async () => (await api<{ data: InvoiceRow[] }>(`/platform/billing/invoices${params ? `?${params}` : ''}`)).data });
  const date = (d: string) => new Date(d).toLocaleDateString(locale, { day: 'numeric', month: 'short', year: 'numeric' });
  return (
    <>
      <div className="mb-4 flex flex-col gap-3 md:flex-row md:items-center">
        <div className="relative max-w-xs flex-1">
          <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted" aria-hidden />
          <input value={q} onChange={(e) => setQ(e.target.value)} placeholder={t('bill.searchNumber')} aria-label={t('bill.searchNumber')} className={cx(inputClass, 'h-10 pl-9 text-[14px]')} />
        </div>
        <div role="radiogroup" aria-label={t('prov.col.status')} className="inline-flex flex-wrap rounded-xl bg-surface-2 p-1">
          {(['all', 'issued', 'overdue', 'paid', 'credited'] as Status[]).map((s) => <button key={s} role="radio" aria-checked={status === s} onClick={() => setStatus(s)} className={cx('h-8 rounded-lg px-3 text-[13px] font-semibold transition', status === s ? 'bg-surface text-ink shadow-sm' : 'text-muted hover:text-ink')}>{t(`bill.filter.${s}` as MessageKey)}</button>)}
        </div>
      </div>
      <InvoiceTable rows={list.data} loading={list.isPending} date={date} onOpen={setOpen} showProvider />
      <InvoiceDrawer id={open} prefix="/platform/billing" manage={manage} onClose={() => setOpen(null)} />
    </>
  );
}

type SettingsForm = Omit<BillingSettingsData, 'complete' | 'vat_rate' | 'due_days'> & { vat_rate: string; due_days: string };

function Settings() {
  const { t } = useI18n();
  const toast = useToast();
  const qc = useQueryClient();
  const query = useQuery({ queryKey: ['hq-billing-settings'], queryFn: async () => (await api<{ data: BillingSettingsData }>('/platform/billing/settings')).data });
  if (!query.data) return <Skeleton className="h-96" />;
  return <SettingsBody key={JSON.stringify(query.data)} data={query.data} onSaved={(d) => { qc.setQueryData(['hq-billing-settings'], d); toast(t('acad.saved')); }} />;
}

function SettingsBody({ data, onSaved }: { data: BillingSettingsData; onSaved: (d: BillingSettingsData) => void }) {
  const { t } = useI18n();
  const toast = useToast();
  const [f, setF] = useState<SettingsForm>({ ...data, vat_rate: String(data.vat_rate), due_days: String(data.due_days), address: { ...data.address }, bank: { ...data.bank } });
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const save = useMutation({
    mutationFn: () => api<{ data: BillingSettingsData }>('/platform/billing/settings', { method: 'PATCH', body: { ...f, vat_rate: Number(f.vat_rate), due_days: Number(f.due_days) } }),
    onSuccess: (res) => { setErrors({}); onSaved(res.data); },
    onError: (e) => { if (e instanceof ApiError) setErrors(e.errors); toast(e instanceof ApiError ? e.message : t('common.error'), 'error'); },
  });
  const text = (k: 'seller_name' | 'registration_number' | 'vat_number' | 'email' | 'phone' | 'invoice_prefix' | 'credit_note_prefix', label: MessageKey) => (
    <Field label={t(label)} value={f[k] ?? ''} onChange={(e) => setF({ ...f, [k]: e.target.value || null })} error={errors[k]?.[0]} />
  );
  const addr = (k: 'line1' | 'line2' | 'city' | 'province' | 'postal_code') => <Field label={t(`acad.f.address.${k}` as MessageKey)} value={f.address[k] ?? ''} onChange={(e) => setF({ ...f, address: { ...f.address, [k]: e.target.value || null } })} error={errors[`address.${k}`]?.[0]} />;
  const bank = (k: 'bank' | 'account_name' | 'account_number' | 'branch_code' | 'account_type') => <Field label={t(`bill.bank.${k}` as MessageKey)} value={f.bank[k] ?? ''} onChange={(e) => setF({ ...f, bank: { ...f.bank, [k]: e.target.value || null } })} error={errors[`bank.${k}`]?.[0]} />;
  const section = (title: string, children: React.ReactNode, note?: string) => <Card className="p-5"><h2 className="text-[15px] font-bold text-ink">{title}</h2>{note && <p className="text-[13px] text-muted">{note}</p>}<div className="mt-4 grid gap-4 sm:grid-cols-2">{children}</div></Card>;

  return (
    <form className="max-w-4xl space-y-5" onSubmit={(e) => { e.preventDefault(); save.mutate(); }}>
      {!data.complete && <p role="status" className="flex items-start gap-2 rounded-xl bg-[#FDF4E1] px-4 py-3 text-[14px] font-medium text-[#8A5A00] dark:bg-[#8A5A00]/25 dark:text-[#F5C46B]"><AlertTriangle className="mt-0.5 size-4 shrink-0" />{t('bill.incomplete')}</p>}
      {section(t('bill.seller'), <>{text('seller_name', 'bill.f.seller_name')}{text('registration_number', 'acad.f.registration_number')}{text('vat_number', 'acad.f.vat_number')}{text('email', 'acad.f.contact_email')}{text('phone', 'acad.f.contact_phone')}</>, t('bill.sellerNote'))}
      {section(t('acad.address'), <>{addr('line1')}{addr('line2')}{addr('city')}{addr('province')}{addr('postal_code')}</>)}
      {section(t('bill.eft'), <>{bank('bank')}{bank('account_name')}{bank('account_number')}{bank('branch_code')}{bank('account_type')}</>, t('bill.eftNote'))}
      {section(t('bill.numbering'), <>
        <Field label={t('bill.f.vat_rate')} inputMode="decimal" value={f.vat_rate} onChange={(e) => setF({ ...f, vat_rate: e.target.value })} error={errors.vat_rate?.[0]} />
        <Field label={t('bill.f.due_days')} type="number" min={0} max={90} value={f.due_days} onChange={(e) => setF({ ...f, due_days: e.target.value })} error={errors.due_days?.[0]} />
        {text('invoice_prefix', 'bill.f.invoice_prefix')}{text('credit_note_prefix', 'bill.f.credit_note_prefix')}
      </>, t('bill.numberingNote'))}
      <div className="flex justify-end"><Button type="submit" loading={save.isPending}>{t('common.save')}</Button></div>
    </form>
  );
}
