import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
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
      <InvoiceTable rows={invoices.data} loading={invoices.isPending} date={date} onOpen={setOpen} />
      <InvoiceDrawer id={open} prefix="" onClose={() => setOpen(null)} />
    </div>
  );
}
