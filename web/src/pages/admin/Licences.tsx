import { useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Package as PackageIcon, Search } from 'lucide-react';
import { formatZar, quotePackage, type PackageItem } from '@simuwork/core';
import { PackageCard, QuoteLines } from '../../components/billing/PackageParts';
import { Button, Card, cx, Drawer, EmptyState, inputClass, PageHeader, Skeleton } from '../../components/ui';
import { api } from '../../lib/api';
import { useI18n } from '../../lib/i18n';

type Shop = { data: PackageItem[]; vat_rate: number };

/** Provider: the shop (packages on sale, with a live price calculator). Licences and orders arrive in 3.3. */
export default function Licences() {
  const { t } = useI18n();
  const [q, setQ] = useState('');
  const [chosen, setChosen] = useState<PackageItem | null>(null);
  const shop = useQuery({ queryKey: ['shop'], queryFn: () => api<Shop>('/shop') });
  const shown = useMemo(() => (shop.data?.data ?? []).filter((p) => !q || `${p.name} ${p.programme.title} ${p.programme.prefix}`.toLowerCase().includes(q.toLowerCase())), [shop.data, q]);

  return (
    <div className="sw-in">
      <PageHeader title={t('shop.title')} lead={t('shop.lead')} />
      <div className="relative mb-6 max-w-sm">
        <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted" aria-hidden />
        <input value={q} onChange={(e) => setQ(e.target.value)} placeholder={t('shop.search')} aria-label={t('shop.search')} className={cx(inputClass, 'h-10 pl-9 text-[14px]')} />
      </div>
      {shop.isPending ? (
        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">{Array.from({ length: 3 }, (_, i) => <Skeleton key={i} className="h-48 rounded-2xl" />)}</div>
      ) : shown.length === 0 ? (
        <Card><EmptyState icon={<PackageIcon className="size-6" />} title={t('shop.none')} /></Card>
      ) : (
        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
          {shown.map((p) => <PackageCard key={p.id} pkg={p} action={<Button size="sm" className="shrink-0 whitespace-nowrap" onClick={() => setChosen(p)}>{t('shop.price')}</Button>} />)}
        </div>
      )}
      <Calculator pkg={chosen} vatRate={shop.data?.vat_rate ?? 15} onClose={() => setChosen(null)} />
    </div>
  );
}

function Calculator({ pkg, vatRate, onClose }: { pkg: PackageItem | null; vatRate: number; onClose: () => void }) {
  const { t } = useI18n();
  const [seats, setSeats] = useState('');
  const n = Number(seats || pkg?.min_seats || 0);
  const quote = pkg ? quotePackage(pkg, n, vatRate) : null;
  const next = pkg?.prices.find((b) => b.from_seats > n);

  return (
    <Drawer open={pkg !== null} onClose={() => { setSeats(''); onClose(); }} title={pkg?.name ?? ''} subtitle={pkg ? `${pkg.programme.prefix} · ${pkg.programme.title}` : ''}>
      {pkg && (
        <div className="space-y-5">
          {pkg.description && <p className="text-[14px] text-muted">{pkg.description}</p>}
          <div>
            <label htmlFor="shop-seats" className="mb-1.5 block text-[13px] font-semibold text-ink">{t('shop.seats')}</label>
            <div className="w-40"><input id="shop-seats" type="number" min={pkg.min_seats} value={seats || String(pkg.min_seats)} onChange={(e) => setSeats(e.target.value)} className={inputClass} /></div>
            <p className="mt-1.5 text-[13px] text-muted">{t('pkg.minSeats', { n: pkg.min_seats })}{pkg.billing === 'term' ? ` · ${t('pkg.termOf', { n: pkg.term_months ?? 0 })}` : ` · ${t('pkg.monthly')}`}</p>
          </div>
          <Card className="p-4">
            {quote ? <QuoteLines quote={quote} billing={pkg.billing} /> : <p className="text-[14px] text-muted">{t('shop.tooFew', { n: pkg.min_seats })}</p>}
            {quote && next && <p className="mt-3 rounded-lg bg-brand-soft px-3 py-2 text-[13px] font-medium text-brand">{t('shop.nextBand', { n: next.from_seats - n, seats: next.from_seats })}</p>}
          </Card>
          <div>
            <h3 className="mb-2 text-[12px] font-bold uppercase tracking-[0.1em] text-muted">{t('shop.bands')}</h3>
            <ul className="divide-y divide-line rounded-xl border border-line">
              {pkg.prices.map((b, i) => (
                <li key={b.from_seats} className={cx('flex justify-between px-3 py-2 text-[14px]', quote?.band_from === b.from_seats && 'bg-brand-soft/60 font-semibold')}>
                  <span>{pkg.prices[i + 1] ? t('shop.bandRange', { from: b.from_seats, to: pkg.prices[i + 1]!.from_seats - 1 }) : t('shop.bandFrom', { from: b.from_seats })}</span>
                  <span className="tabular-nums">{formatZar(b.unit_price_cents)}</span>
                </li>
              ))}
            </ul>
          </div>
          <p className="text-[13px] text-muted">{t('shop.orderingSoon')}</p>
        </div>
      )}
    </Drawer>
  );
}
