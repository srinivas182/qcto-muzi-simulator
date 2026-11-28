import { Receipt } from 'lucide-react';
import { formatZar, type InvoiceRow } from '@simuwork/core';
import { InvoiceStatusBadge } from './InvoiceDrawer';
import { Card, EmptyState, Skeleton } from '../ui';
import { useI18n } from '../../lib/i18n';

/** Invoices as a table; click a row to open it. */
export function InvoiceTable({ rows, loading, date, onOpen, showProvider = false }: { rows: InvoiceRow[] | undefined; loading: boolean; date: (d: string) => string; onOpen: (id: string) => void; showProvider?: boolean }) {
  const { t } = useI18n();
  return (
    <Card className="overflow-hidden p-0">
      {loading ? <div className="space-y-2 p-5">{Array.from({ length: 4 }, (_, i) => <Skeleton key={i} className="h-12" />)}</div>
        : !rows?.length ? <EmptyState icon={<Receipt className="size-6" />} title={t('inv.none')} />
        : (
          <table className="w-full text-left text-[14px]">
            <thead className="border-b border-line bg-surface-2/60 text-[12px] font-bold uppercase tracking-[0.08em] text-muted">
              <tr>
                <th className="px-5 py-3">{t('inv.col.number')}</th>
                {showProvider && <th className="hidden px-5 py-3 md:table-cell">{t('prov.col.provider')}</th>}
                <th className="hidden px-5 py-3 lg:table-cell">{t('inv.col.what')}</th>
                <th className="hidden px-5 py-3 sm:table-cell">{t('inv.col.due')}</th>
                <th className="px-5 py-3 text-right">{t('pkg.q.total')}</th>
                <th className="px-5 py-3 text-right">{t('prov.col.status')}</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-line">
              {rows.map((i) => (
                <tr key={i.id} className="cursor-pointer transition hover:bg-surface-2/40" onClick={() => onOpen(i.id)}>
                  <td className="px-5 py-3"><button type="button" className="font-mono font-semibold text-ink hover:text-brand" onClick={(e) => { e.stopPropagation(); onOpen(i.id); }}>{i.number}</button><div className="text-[12px] text-muted">{date(i.issue_date)}</div></td>
                  {showProvider && <td className="hidden px-5 py-3 text-ink md:table-cell">{i.buyer_name}</td>}
                  <td className="hidden max-w-xs truncate px-5 py-3 text-muted lg:table-cell">{i.description}</td>
                  <td className="hidden px-5 py-3 text-muted sm:table-cell">{date(i.due_date)}</td>
                  <td className="px-5 py-3 text-right tabular-nums text-ink">{formatZar(i.total_cents)}</td>
                  <td className="px-5 py-3 text-right"><InvoiceStatusBadge invoice={i} /></td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
    </Card>
  );
}
