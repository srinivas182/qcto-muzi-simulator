import { useMemo, useState } from 'react';
import { Link } from 'react-router';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { AlertTriangle, CalendarClock, KeyRound, Package as PackageIcon, Plus, Search, ShoppingCart } from 'lucide-react';
import { formatZar, quotePackage, type InvoiceRow, type LicenceAccount, type LicencePoolItem, type PackageItem } from '@simuwork/core';
import type { MessageKey } from '@simuwork/core/locales/en';
import { PackageCard, QuoteLines } from '../../components/billing/PackageParts';
import { Badge, Button, Card, cx, Drawer, EmptyState, inputClass, PageHeader, Skeleton, useToast } from '../../components/ui';
import { api, ApiError } from '../../lib/api';
import { useI18n } from '../../lib/i18n';

type Shop = { data: PackageItem[]; vat_rate: number };
type Licences = { data: LicencePoolItem[]; past: LicencePoolItem[]; account: LicenceAccount };
type Tab = 'licences' | 'shop';

/** Provider: its licences (seats per programme) and the shop to buy more. */
export default function Licences() {
  const { t } = useI18n();
  const [tab, setTab] = useState<Tab>('licences');
  const licences = useQuery({ queryKey: ['licences'], queryFn: () => api<Licences>('/licences') });
  const account = licences.data?.account;

  return (
    <div className="sw-in">
      <PageHeader title={t('shop.title')} lead={t('shop.lead')} actions={<Button icon={<ShoppingCart className="size-4" />} onClick={() => setTab('shop')}>{t('lic.buy')}</Button>} />
      {account?.blocked && (
        <p role="alert" className="mb-5 flex items-start gap-2 rounded-xl bg-[#FDECEC] px-4 py-3 text-[14px] font-medium text-[#B42328] dark:bg-[#B42328]/20 dark:text-[#FF9A9E]">
          <AlertTriangle className="mt-0.5 size-4 shrink-0" />{t('lic.blocked')} <Link to="/admin/billing" className="underline">{t('lic.seeInvoices')}</Link>
        </p>
      )}
      {!account?.blocked && (account?.overdue_cents ?? 0) > 0 && (
        <p role="status" className="mb-5 flex items-start gap-2 rounded-xl bg-[#FDF4E1] px-4 py-3 text-[14px] font-medium text-[#8A5A00] dark:bg-[#8A5A00]/25 dark:text-[#F5C46B]">
          <AlertTriangle className="mt-0.5 size-4 shrink-0" />{t('lic.overdue', { amount: formatZar(account!.overdue_cents) })} <Link to="/admin/billing" className="underline">{t('lic.seeInvoices')}</Link>
        </p>
      )}
      <div role="tablist" className="mb-5 inline-flex rounded-xl bg-surface-2 p-1">
        {(['licences', 'shop'] as Tab[]).map((k) => (
          <button key={k} role="tab" aria-selected={tab === k} onClick={() => setTab(k)} className={cx('h-9 rounded-lg px-4 text-[13px] font-semibold transition', tab === k ? 'bg-surface text-ink shadow-sm' : 'text-muted hover:text-ink')}>{t(`lic.tab.${k}` as MessageKey)}</button>
        ))}
      </div>
      {tab === 'licences' ? <Pools data={licences.data} onShop={() => setTab('shop')} /> : <ShopTab blocked={account?.blocked ?? false} onBought={() => setTab('licences')} />}
    </div>
  );
}

function Pools({ data, onShop }: { data: Licences | undefined; onShop: () => void }) {
  const { t } = useI18n();
  const [topUp, setTopUp] = useState<LicencePoolItem | null>(null);
  if (!data) return <div className="grid gap-4 md:grid-cols-2">{Array.from({ length: 2 }, (_, i) => <Skeleton key={i} className="h-48 rounded-2xl" />)}</div>;
  if (data.data.length === 0) return <Card><EmptyState icon={<KeyRound className="size-6" />} title={t('lic.none')} action={<Button onClick={onShop}>{t('lic.browse')}</Button>} /></Card>;
  return (
    <>
      <div className="grid gap-4 lg:grid-cols-2">{data.data.map((p) => <PoolCard key={p.id} pool={p} blocked={data.account.blocked} onTopUp={() => setTopUp(p)} />)}</div>
      {data.past.length > 0 && (
        <details className="mt-8">
          <summary className="cursor-pointer text-[13px] font-semibold text-muted">{t('lic.past', { n: data.past.length })}</summary>
          <ul className="mt-3 divide-y divide-line rounded-2xl border border-line">
            {data.past.map((p) => <li key={p.id} className="flex justify-between px-4 py-3 text-[14px]"><span>{p.package.name}</span><span className="text-muted">{t(`lic.status.${p.status}` as MessageKey)}{p.ends_on ? ` · ${p.ends_on}` : ''}</span></li>)}
          </ul>
        </details>
      )}
      <TopUp pool={topUp} onClose={() => setTopUp(null)} />
    </>
  );
}

function PoolCard({ pool, blocked, onTopUp }: { pool: LicencePoolItem; blocked: boolean; onTopUp: () => void }) {
  const { t, locale } = useI18n();
  const toast = useToast();
  const qc = useQueryClient();
  const [next, setNext] = useState(String(pool.seats_next_month ?? pool.seats));
  const date = (d: string) => new Date(d).toLocaleDateString(locale, { day: 'numeric', month: 'short', year: 'numeric' });
  const mutate = useMutation({
    mutationFn: (body: { seats_next_month?: number; cancel?: boolean }) => api(`/licences/${pool.id}${body.cancel === undefined ? '' : '/cancel'}`, { method: body.cancel === undefined ? 'PATCH' : 'POST', body }),
    onSuccess: () => { void qc.invalidateQueries({ queryKey: ['licences'] }); toast(t('acad.saved')); },
    onError: (e) => toast(e instanceof ApiError ? Object.values(e.errors)[0]?.[0] ?? e.message : t('common.error'), 'error'),
  });
  const pct = pool.seats ? Math.round((pool.seats_used / pool.seats) * 100) : 0;
  const tone = pool.status === 'pending' ? 'warning' : pool.cancel_at_period_end ? 'neutral' : 'success';

  return (
    <Card className="flex flex-col p-5">
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <span className="rounded-lg bg-brand-soft px-2 py-1 font-mono text-[12px] font-bold text-brand">{pool.programme.prefix}</span>
          <h3 className="mt-2 text-[16px] font-bold text-ink">{pool.package.name}</h3>
          <p className="text-[13px] text-muted">{pool.programme.title}</p>
        </div>
        <Badge tone={tone}>{pool.cancel_at_period_end ? t('lic.ending') : t(`lic.status.${pool.status}` as MessageKey)}</Badge>
      </div>

      {pool.status === 'pending' ? (
        <p className="mt-4 rounded-xl bg-[#FDF4E1] px-3 py-2.5 text-[13px] text-[#8A5A00] dark:bg-[#8A5A00]/25 dark:text-[#F5C46B]">{t('lic.pendingHelp', { n: pool.seats_next_month ?? 0 })} <Link to="/admin/billing" className="font-semibold underline">{t('lic.seeInvoices')}</Link></p>
      ) : (
        <>
          <div className="mt-4">
            <div className="mb-1 flex justify-between text-[13px]"><span className="font-semibold text-ink">{t('lic.used', { used: pool.seats_used, total: pool.seats })}</span><span className="text-muted">{t('lic.free', { n: pool.seats_free })}</span></div>
            <div className="h-2 overflow-hidden rounded-full bg-surface-2" role="progressbar" aria-valuenow={pct} aria-valuemin={0} aria-valuemax={100} aria-label={t('lic.usedLabel')}><div className="h-full rounded-full bg-brand transition-all" style={{ width: `${pct}%` }} /></div>
          </div>
          <p className="mt-3 flex items-center gap-1.5 text-[13px] text-muted">
            <CalendarClock className="size-4" />
            {pool.billing === 'term' && pool.ends_on ? t('lic.termEnds', { date: date(pool.ends_on), n: pool.days_left ?? 0 }) : pool.cancel_at_period_end && pool.ends_on ? t('lic.monthlyEnds', { date: date(pool.ends_on) }) : t('lic.monthlyRenews', { price: formatZar(pool.unit_price_cents) })}
          </p>
        </>
      )}

      {pool.status === 'active' && (
        <div className="mt-auto flex flex-wrap items-end gap-2 pt-5">
          {!pool.cancel_at_period_end && <Button size="sm" icon={<Plus className="size-4" />} disabled={blocked} onClick={onTopUp}>{t('lic.addSeats')}</Button>}
          {pool.billing === 'monthly' && !pool.cancel_at_period_end && (
            <div className="flex items-end gap-2">
              <div className="w-24"><label htmlFor={`next-${pool.id}`} className="mb-1 block text-[12px] font-semibold text-muted">{t('lic.nextMonth')}</label><input id={`next-${pool.id}`} type="number" min={pool.package.min_seats} value={next} onChange={(e) => setNext(e.target.value)} className={cx(inputClass, 'h-9')} /></div>
              {Number(next) !== (pool.seats_next_month ?? pool.seats) && <Button size="sm" variant="secondary" loading={mutate.isPending} onClick={() => mutate.mutate({ seats_next_month: Number(next) })}>{t('common.save')}</Button>}
            </div>
          )}
          {pool.billing === 'monthly' && <Button size="sm" variant="ghost" onClick={() => mutate.mutate({ cancel: !pool.cancel_at_period_end })}>{t(pool.cancel_at_period_end ? 'lic.resume' : 'lic.cancel')}</Button>}
        </div>
      )}
    </Card>
  );
}

function TopUp({ pool, onClose }: { pool: LicencePoolItem | null; onClose: () => void }) {
  const { t } = useI18n();
  const toast = useToast();
  const qc = useQueryClient();
  const [seats, setSeats] = useState('5');
  const order = useMutation({
    mutationFn: () => api<{ invoice: InvoiceRow }>('/orders', { method: 'POST', body: { pool: pool!.id, seats: Number(seats) } }),
    onSuccess: (res) => { void qc.invalidateQueries({ queryKey: ['licences'] }); void qc.invalidateQueries({ queryKey: ['invoices'] }); toast(t('lic.ordered', { number: res.invoice.number, amount: formatZar(res.invoice.total_cents) })); onClose(); },
    onError: (e) => toast(e instanceof ApiError ? Object.values(e.errors)[0]?.[0] ?? e.message : t('common.error'), 'error'),
  });
  return (
    <Drawer open={pool !== null} onClose={onClose} title={t('lic.addSeats')} subtitle={pool?.package.name}
      footer={<><Button variant="ghost" onClick={onClose}>{t('common.cancel')}</Button><Button loading={order.isPending} disabled={!(Number(seats) >= 1)} onClick={() => order.mutate()}>{t('lic.orderSeats')}</Button></>}>
      {pool && (
        <div className="space-y-4">
          <div className="w-40"><label htmlFor="topup-seats" className="mb-1.5 block text-[13px] font-semibold text-ink">{t('lic.extraSeats')}</label><input id="topup-seats" type="number" min={1} value={seats} onChange={(e) => setSeats(e.target.value)} className={inputClass} /></div>
          <p className="text-[14px] text-muted">{pool.billing === 'term' ? t('lic.topUpTerm', { price: formatZar(pool.unit_price_cents) }) : t('lic.topUpMonthly', { price: formatZar(pool.unit_price_cents) })}</p>
        </div>
      )}
    </Drawer>
  );
}

function ShopTab({ blocked, onBought }: { blocked: boolean; onBought: () => void }) {
  const { t } = useI18n();
  const [q, setQ] = useState('');
  const [chosen, setChosen] = useState<PackageItem | null>(null);
  const shop = useQuery({ queryKey: ['shop'], queryFn: () => api<Shop>('/shop') });
  const shown = useMemo(() => (shop.data?.data ?? []).filter((p) => !q || `${p.name} ${p.programme.title} ${p.programme.prefix}`.toLowerCase().includes(q.toLowerCase())), [shop.data, q]);
  return (
    <>
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
      <Calculator pkg={chosen} vatRate={shop.data?.vat_rate ?? 15} blocked={blocked} onClose={() => setChosen(null)} onBought={onBought} />
    </>
  );
}

function Calculator({ pkg, vatRate, blocked, onClose, onBought }: { pkg: PackageItem | null; vatRate: number; blocked: boolean; onClose: () => void; onBought: () => void }) {
  const { t } = useI18n();
  const toast = useToast();
  const qc = useQueryClient();
  const [seats, setSeats] = useState('');
  const n = Number(seats || pkg?.min_seats || 0);
  const quote = pkg ? quotePackage(pkg, n, vatRate) : null;
  const next = pkg?.prices.find((b) => b.from_seats > n);
  const close = () => { setSeats(''); onClose(); };
  const buy = useMutation({
    mutationFn: () => api<{ invoice: InvoiceRow }>('/orders', { method: 'POST', body: { package: pkg!.code, seats: n } }),
    onSuccess: (res) => {
      void qc.invalidateQueries({ queryKey: ['licences'] });
      void qc.invalidateQueries({ queryKey: ['invoices'] });
      toast(t('lic.ordered', { number: res.invoice.number, amount: formatZar(res.invoice.total_cents) }));
      close();
      onBought();
    },
    onError: (e) => toast(e instanceof ApiError ? Object.values(e.errors)[0]?.[0] ?? e.message : t('common.error'), 'error'),
  });

  return (
    <Drawer open={pkg !== null} onClose={close} title={pkg?.name ?? ''} subtitle={pkg ? `${pkg.programme.prefix} · ${pkg.programme.title}` : ''}
      footer={pkg && <><Button variant="ghost" onClick={close}>{t('common.cancel')}</Button><Button icon={<ShoppingCart className="size-4" />} loading={buy.isPending} disabled={!quote || blocked} onClick={() => buy.mutate()}>{quote ? t('lic.buyFor', { amount: formatZar(quote.total_cents) }) : t('lic.buy')}</Button></>}>
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
          {pkg.billing === 'monthly' && <p className="text-[13px] text-muted">{t('lic.monthlyFirst')}</p>}
          <p className="text-[13px] text-muted">{t('lic.howPay')}</p>
        </div>
      )}
    </Drawer>
  );
}
