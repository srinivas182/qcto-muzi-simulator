import type { ReactNode } from 'react';
import { Box, PlayCircle } from 'lucide-react';
import { formatZar, quotePackage, type PackageItem } from '@simuwork/core';
import { Badge, Card, cx } from '../ui';
import { useI18n } from '../../lib/i18n';

const VAT = 15;

export function PackageCard({ pkg, onOpen, action }: { pkg: PackageItem; onOpen?: () => void; action?: ReactNode }) {
  const { t } = useI18n();
  const from = pkg.prices.length ? quotePackage(pkg, Math.max(pkg.min_seats, ...pkg.prices.map((b) => b.from_seats)), VAT) : null;
  return (
    <Card className={cx('flex h-full flex-col p-5', onOpen && 'cursor-pointer transition hover:-translate-y-0.5 hover:border-brand/40 hover:shadow-lg')} onClick={onOpen}>
      <div className="flex items-center gap-2">
        <span className="rounded-lg bg-brand-soft px-2 py-1 font-mono text-[12px] font-bold text-brand">{pkg.programme.prefix}</span>
        <Includes pkg={pkg} />
      </div>
      <h3 className="mt-3 text-[16px] font-bold leading-snug text-ink">{pkg.name}</h3>
      <p className="text-[13px] text-muted">{pkg.programme.title}</p>
      {pkg.description && <p className="mt-2 line-clamp-2 text-[14px] text-muted">{pkg.description}</p>}
      <div className="mt-auto flex items-end justify-between gap-3 pt-5">
        <div>
          <div className="text-[12px] font-semibold text-muted">{pkg.billing === 'term' ? t('pkg.termOf', { n: pkg.term_months ?? 0 }) : t('pkg.monthly')} · {t('pkg.minSeats', { n: pkg.min_seats })}</div>
          {from && <div className="text-[15px] font-bold text-ink">{t('pkg.fromPerMonth', { price: formatZar(from.per_seat_per_month_cents) })}</div>}
        </div>
        {action}
      </div>
    </Card>
  );
}

export function Includes({ pkg }: { pkg: PackageItem }) {
  const { t } = useI18n();
  return (
    <div className="flex gap-1.5">
      {pkg.includes_simulators && <Badge tone="brand"><Box className="mr-1 inline size-3" />{t('cov.channel.simulator')}</Badge>}
      {pkg.includes_courses && <Badge tone="brand"><PlayCircle className="mr-1 inline size-3" />{t('cov.channel.course')}</Badge>}
    </div>
  );
}

export function QuoteLines({ quote, billing }: { quote: NonNullable<ReturnType<typeof quotePackage>>; billing: 'term' | 'monthly' }) {
  const { t } = useI18n();
  const row = (label: string, value: string, strong = false) => (
    <div className={cx('flex justify-between text-[14px]', strong ? 'border-t border-line pt-2 font-bold text-ink' : 'text-muted')}><span>{label}</span><span className="tabular-nums">{value}</span></div>
  );
  return (
    <div className="mt-3 space-y-1.5" aria-live="polite">
      {row(t('pkg.q.unit', { seats: quote.seats, price: formatZar(quote.unit_price_cents) }), formatZar(quote.subtotal_cents))}
      {row(t('pkg.q.vat', { rate: quote.vat_rate }), formatZar(quote.vat_cents))}
      {row(t(billing === 'term' ? 'pkg.q.total' : 'pkg.q.totalMonthly'), formatZar(quote.total_cents), true)}
      <p className="pt-1 text-[12px] text-muted">{t('pkg.q.perLearner', { price: formatZar(quote.per_seat_per_month_cents) })}</p>
    </div>
  );
}
