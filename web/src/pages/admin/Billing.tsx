import { useState } from 'react';
import { useSearchParams } from 'react-router';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { CheckCircle2, Loader2, XCircle } from 'lucide-react';
import { formatZar, type InvoiceRow } from '@simuwork/core';
import { InvoiceDrawer } from '../../components/billing/InvoiceDrawer';
import { InvoiceTable } from '../../components/billing/InvoiceTable';
import { PageHeader } from '../../components/ui';
import { api } from '../../lib/api';
import { useI18n } from '../../lib/i18n';

/** Provider: invoices, with PDFs and how to pay. */
export default function Billing() {
  const { t, locale } = useI18n();
  const [open, setOpen] = useState<string | null>(null);
  const invoices = useQuery({ queryKey: ['invoices'], queryFn: async () => (await api<{ data: InvoiceRow[] }>('/invoices')).data });
  const owed = (invoices.data ?? []).filter((i) => i.status === 'issued').reduce((s, i) => s + i.balance_cents, 0);
  const date = (d: string) => new Date(d).toLocaleDateString(locale, { day: 'numeric', month: 'short', year: 'numeric' });

  return (
    <div className="sw-in">
      <PageHeader title={t('inv.listTitle')} lead={owed > 0 ? t('inv.owedTotal', { amount: formatZar(owed) }) : t('inv.allPaid')} />
      <PaymentReturn />
      <InvoiceTable rows={invoices.data} loading={invoices.isPending} date={date} onOpen={setOpen} />
      <InvoiceDrawer id={open} prefix="" onClose={() => setOpen(null)} />
    </div>
  );
}

/** After the gateway sends the provider back (?attempt=…): confirm the payment and say what happened. */
function PaymentReturn() {
  const { t } = useI18n();
  const qc = useQueryClient();
  const [params, setParams] = useSearchParams();
  const id = params.get('attempt');
  const [startedAt] = useState(() => Date.now());
  const attempt = useQuery({
    queryKey: ['payment-attempt', id],
    enabled: id !== null,
    queryFn: async () => {
      const data = (await api<{ data: { status: 'started' | 'paid' | 'failed' } }>(`/payment-attempts/${id}`)).data;
      if (data.status === 'paid') {
        void qc.invalidateQueries({ queryKey: ['invoices'] });
        void qc.invalidateQueries({ queryKey: ['licences'] });
      }
      return data;
    },
    refetchInterval: (q) => (q.state.data?.status === 'started' && q.state.dataUpdatedAt - startedAt < 30_000 ? 2000 : false),
  });
  const status = attempt.data?.status;
  const slow = status === 'started' && attempt.dataUpdatedAt - startedAt >= 30_000;
  if (!id) return null;
  const done = () => setParams((p) => { p.delete('attempt'); return p; }, { replace: true });
  const [Icon, cls, text] = status === 'paid'
    ? [CheckCircle2, 'bg-[#E6F6F1] text-[#0B7A5C] dark:bg-[#0B7A5C]/20 dark:text-[#5FD3B1]', t('pay.received')]
    : status === 'failed' || slow
      ? [XCircle, 'bg-[#FDECEC] text-[#B42328] dark:bg-[#B42328]/20 dark:text-[#FF9A9E]', t(status === 'failed' ? 'pay.failed' : 'pay.slow')]
      : [Loader2, 'bg-surface-2 text-ink', t('pay.checking')];
  return (
    <p role="status" className={`mb-5 flex items-center gap-2 rounded-xl px-4 py-3 text-[14px] font-medium ${cls}`}>
      <Icon className={`size-4 shrink-0 ${Icon === Loader2 ? 'animate-spin' : ''}`} />
      <span className="flex-1">{text}</span>
      {(status !== 'started' || slow) && <button type="button" onClick={done} className="text-[13px] underline">{t('common.close')}</button>}
    </p>
  );
}
