import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Download, FileMinus2, Landmark } from 'lucide-react';
import { formatZar, type InvoiceDetail, type InvoiceRow } from '@simuwork/core';
import { Badge, Button, Card, cx, Drawer, Field, inputClass, Skeleton, useToast } from '../ui';
import { api, ApiError, downloadFile } from '../../lib/api';
import { useI18n } from '../../lib/i18n';
import { PayOnline } from './PayOnline';

export function InvoiceStatusBadge({ invoice }: { invoice: Pick<InvoiceRow, 'status' | 'overdue_days' | 'paid_cents'> }) {
  const { t } = useI18n();
  if (invoice.status === 'paid') return <Badge tone="success">{t('inv.status.paid')}</Badge>;
  if (invoice.status === 'credited') return <Badge tone="neutral">{t('inv.status.credited')}</Badge>;
  if (invoice.overdue_days > 0) return <Badge tone="danger">{t('inv.overdue', { n: invoice.overdue_days })}</Badge>;
  return <Badge tone="warning">{invoice.paid_cents > 0 ? t('inv.status.part') : t('inv.status.issued')}</Badge>;
}

/**
 * One invoice: lines, totals, payments, EFT details and PDF downloads. With `prefix="/platform/billing"` and
 * `manage`, HQ can also record an EFT payment or credit the invoice.
 */
export function InvoiceDrawer({ id, prefix, manage = false, onClose }: { id: string | null; prefix: '' | '/platform/billing'; manage?: boolean; onClose: () => void }) {
  const { t, locale } = useI18n();
  const toast = useToast();
  const qc = useQueryClient();
  const key = ['invoice', prefix, id] as const;
  const query = useQuery({ queryKey: key, enabled: id !== null, queryFn: async () => (await api<{ data: InvoiceDetail }>(`${prefix}/invoices/${id}`)).data });
  const inv = query.data;
  const date = (d: string) => new Date(d).toLocaleDateString(locale, { day: 'numeric', month: 'short', year: 'numeric' });
  const done = (data: InvoiceDetail, message: string) => {
    qc.setQueryData(key, data);
    void qc.invalidateQueries({ queryKey: ['invoices'] });
    void qc.invalidateQueries({ queryKey: ['hq-invoices'] });
    void qc.invalidateQueries({ queryKey: ['hq-billing-summary'] });
    void qc.invalidateQueries({ queryKey: ['licences'] });
    toast(message);
  };
  const download = (credit: boolean) => inv && void downloadFile(`${prefix}/invoices/${inv.id}/${credit ? 'credit-note.pdf' : 'pdf'}`, `${credit ? inv.credit_note?.number : inv.number}.pdf`).catch(() => toast(t('common.error'), 'error'));

  return (
    <Drawer open={id !== null} onClose={onClose} width="max-w-xl" title={inv ? t('inv.title', { number: inv.number }) : t('common.loading')} subtitle={inv ? `${inv.buyer.trading_name ?? inv.buyer.name} · ${date(inv.issue_date)}` : ''}>
      {!inv ? <Skeleton className="h-80" /> : (
        <div className="space-y-5">
          <div className="flex flex-wrap items-center justify-between gap-3">
            <InvoiceStatusBadge invoice={inv} />
            <div className="flex gap-2">
              <Button size="sm" variant="secondary" icon={<Download className="size-4" />} onClick={() => download(false)}>{t('inv.pdf')}</Button>
              {inv.credit_note && <Button size="sm" variant="secondary" icon={<FileMinus2 className="size-4" />} onClick={() => download(true)}>{inv.credit_note.number}</Button>}
            </div>
          </div>

          <Card className="p-0">
            <ul className="divide-y divide-line">
              {inv.lines.map((l, i) => (
                <li key={i} className="flex justify-between gap-4 px-4 py-3 text-[14px]">
                  <span className="text-ink">{l.description}<span className="block text-[12px] text-muted">{l.quantity} × {formatZar(l.unit_cents)}</span></span>
                  <span className="shrink-0 tabular-nums text-ink">{formatZar(l.amount_cents)}</span>
                </li>
              ))}
            </ul>
            <div className="space-y-1 border-t border-line px-4 py-3 text-[14px]">
              <Row label={t('inv.subtotal')} value={formatZar(inv.subtotal_cents)} />
              <Row label={t('pkg.q.vat', { rate: inv.vat_rate })} value={formatZar(inv.vat_cents)} />
              <Row label={t('pkg.q.total')} value={formatZar(inv.total_cents)} strong />
              {inv.paid_cents > 0 && <Row label={t('inv.paid')} value={`− ${formatZar(inv.paid_cents)}`} />}
              {inv.status === 'issued' && <Row label={t('inv.owed', { date: date(inv.due_date) })} value={formatZar(inv.balance_cents)} strong />}
            </div>
          </Card>

          {inv.status === 'issued' && prefix === '' && <PayOnline invoiceId={inv.id} />}

          {inv.status === 'issued' && inv.seller.bank.account_number && (
            <Card className="bg-surface-2/50 p-4 text-[14px]">
              <div className="mb-1 flex items-center gap-2 font-semibold text-ink"><Landmark className="size-4" />{t('inv.payEft')}</div>
              <p className="text-muted">{t('inv.payEftRef', { number: inv.number })}</p>
              <dl className="mt-2 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1">
                <dt className="text-muted">{t('inv.bank.name')}</dt><dd className="text-ink">{inv.seller.bank.account_name ?? inv.seller.name}</dd>
                <dt className="text-muted">{t('inv.bank.bank')}</dt><dd className="text-ink">{inv.seller.bank.bank}</dd>
                <dt className="text-muted">{t('inv.bank.account')}</dt><dd className="font-mono text-ink">{inv.seller.bank.account_number}</dd>
                <dt className="text-muted">{t('inv.bank.branch')}</dt><dd className="font-mono text-ink">{inv.seller.bank.branch_code}</dd>
              </dl>
            </Card>
          )}

          {inv.payments.length > 0 && (
            <div>
              <h3 className="mb-2 text-[12px] font-bold uppercase tracking-[0.1em] text-muted">{t('inv.payments')}</h3>
              <ul className="space-y-1.5 text-[14px]">
                {inv.payments.map((p) => (
                  <li key={p.id} className="flex justify-between"><span className="text-ink">{date(p.paid_on)} · {p.gateway ?? t('inv.eft')}{p.reference ? ` · ${p.reference}` : ''}</span><span className="tabular-nums">{formatZar(p.amount_cents)}</span></li>
                ))}
              </ul>
            </div>
          )}
          {inv.credit_note && <p className="rounded-xl bg-surface-2 px-4 py-3 text-[14px] text-muted">{t('inv.creditedBy', { number: inv.credit_note.number, reason: inv.credit_note.reason })}</p>}

          {manage && inv.status === 'issued' && <HqActions invoice={inv} onDone={done} />}
        </div>
      )}
    </Drawer>
  );
}

function Row({ label, value, strong }: { label: string; value: string; strong?: boolean }) {
  return <div className={cx('flex justify-between', strong ? 'font-bold text-ink' : 'text-muted')}><span>{label}</span><span className="tabular-nums">{value}</span></div>;
}

function HqActions({ invoice, onDone }: { invoice: InvoiceDetail; onDone: (d: InvoiceDetail, m: string) => void }) {
  const { t } = useI18n();
  const toast = useToast();
  const [mode, setMode] = useState<'pay' | 'credit' | null>(null);
  const [amount, setAmount] = useState((invoice.balance_cents / 100).toFixed(2));
  const [reference, setReference] = useState('');
  const [paidOn, setPaidOn] = useState(() => new Date().toISOString().slice(0, 10));
  const [reason, setReason] = useState('');
  const pay = useMutation({
    mutationFn: () => api<{ data: InvoiceDetail }>(`/platform/billing/invoices/${invoice.id}/payments`, { method: 'POST', body: { amount_cents: Math.round(Number(amount) * 100), reference, paid_on: paidOn } }),
    onSuccess: (res) => { onDone(res.data, t('inv.paymentRecorded')); setMode(null); },
    onError: (e) => toast(e instanceof ApiError ? Object.values(e.errors)[0]?.[0] ?? e.message : t('common.error'), 'error'),
  });
  const credit = useMutation({
    mutationFn: () => api<{ data: InvoiceDetail }>(`/platform/billing/invoices/${invoice.id}/credit`, { method: 'POST', body: { reason } }),
    onSuccess: (res) => { onDone(res.data, t('inv.credited')); setMode(null); },
    onError: (e) => toast(e instanceof ApiError ? Object.values(e.errors)[0]?.[0] ?? e.message : t('common.error'), 'error'),
  });

  return (
    <div className="border-t border-line pt-4">
      {mode === null && (
        <div className="flex flex-wrap gap-2">
          <Button onClick={() => setMode('pay')}>{t('inv.recordEft')}</Button>
          {invoice.paid_cents === 0 && <Button variant="secondary" onClick={() => setMode('credit')}>{t('inv.credit')}</Button>}
        </div>
      )}
      {mode === 'pay' && (
        <div className="space-y-3">
          <div className="grid gap-3 sm:grid-cols-2">
            <Field label={t('inv.f.amount')} inputMode="decimal" value={amount} onChange={(e) => setAmount(e.target.value)} />
            <Field label={t('inv.f.paidOn')} type="date" value={paidOn} onChange={(e) => setPaidOn(e.target.value)} />
          </div>
          <Field label={t('inv.f.reference')} value={reference} onChange={(e) => setReference(e.target.value)} hint={t('inv.f.referenceHint')} />
          <div className="flex gap-2"><Button loading={pay.isPending} disabled={!reference || !(Number(amount) > 0)} onClick={() => pay.mutate()}>{t('inv.recordEft')}</Button><Button variant="ghost" onClick={() => setMode(null)}>{t('common.cancel')}</Button></div>
        </div>
      )}
      {mode === 'credit' && (
        <div className="space-y-3">
          <p className="text-[14px] text-muted">{t('inv.creditHelp')}</p>
          <label htmlFor="credit-reason" className="block text-[13px] font-semibold text-ink">{t('prov.reason')}</label>
          <textarea id="credit-reason" rows={2} value={reason} onChange={(e) => setReason(e.target.value)} className={cx(inputClass, 'h-auto py-2.5')} />
          <div className="flex gap-2"><Button loading={credit.isPending} disabled={reason.trim().length < 5} onClick={() => credit.mutate()}>{t('inv.credit')}</Button><Button variant="ghost" onClick={() => setMode(null)}>{t('common.cancel')}</Button></div>
        </div>
      )}
    </div>
  );
}
