import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Archive, Copy, Package as PackageIcon, Plus, Rocket, Trash2, X } from 'lucide-react';
import { quotePackage, type PackageItem, type PriceBand, type ProgrammeSummary } from '@simuwork/core';
import type { MessageKey } from '@simuwork/core/locales/en';
import { useSignedIn } from '../../app/access';
import { PackageCard, QuoteLines } from '../../components/billing/PackageParts';
import { Button, Card, cx, Drawer, EmptyState, Field, inputClass, PageHeader, Skeleton, useToast } from '../../components/ui';
import { api, ApiError } from '../../lib/api';
import { useI18n } from '../../lib/i18n';
import { can } from '../../lib/session';

const KEY = ['hq-packages'] as const;
type Filter = 'published' | 'draft' | 'retired';
const VAT = 15;

/** HQ price list: packages per programme, with price bands, publish and retire. */
export default function Packages() {
  const { t } = useI18n();
  const session = useSignedIn();
  const [filter, setFilter] = useState<Filter>('published');
  const [open, setOpen] = useState<PackageItem | 'new' | null>(null);
  const list = useQuery({ queryKey: KEY, queryFn: async () => (await api<{ data: PackageItem[] }>('/platform/packages')).data });
  const manage = can(session, 'packages.manage');
  const shown = (list.data ?? []).filter((p) => p.status === filter);
  const count = (s: Filter) => (list.data ?? []).filter((p) => p.status === s).length;

  return (
    <div className="sw-in">
      <PageHeader title={t('pkg.title')} lead={t('pkg.lead')} actions={manage ? <Button icon={<Plus className="size-4" />} onClick={() => setOpen('new')}>{t('pkg.new')}</Button> : undefined} />
      <div role="tablist" className="mb-5 inline-flex rounded-xl bg-surface-2 p-1">
        {(['published', 'draft', 'retired'] as Filter[]).map((f) => (
          <button key={f} role="tab" aria-selected={filter === f} onClick={() => setFilter(f)} className={cx('h-9 rounded-lg px-4 text-[13px] font-semibold transition', filter === f ? 'bg-surface text-ink shadow-sm' : 'text-muted hover:text-ink')}>
            {t(`pkg.status.${f}` as MessageKey)} <span className="tabular-nums opacity-60">{count(f)}</span>
          </button>
        ))}
      </div>
      {list.isPending ? (
        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">{Array.from({ length: 3 }, (_, i) => <Skeleton key={i} className="h-48 rounded-2xl" />)}</div>
      ) : shown.length === 0 ? (
        <Card><EmptyState icon={<PackageIcon className="size-6" />} title={t('pkg.none')} /></Card>
      ) : (
        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
          {shown.map((p) => <PackageCard key={p.id} pkg={p} onOpen={() => setOpen(p)} />)}
        </div>
      )}
      <Editor pkg={open} manage={manage} onClose={() => setOpen(null)} onSaved={(p) => { setOpen(p); setFilter(p.status); }} />
    </div>
  );
}

type Form = { name: string; description: string; programme: string; includes_simulators: boolean; includes_courses: boolean; billing: 'term' | 'monthly'; term_months: string; min_seats: string; bands: { from: string; price: string }[] };
const toForm = (p: PackageItem | null): Form => ({
  name: p?.name ?? '', description: p?.description ?? '', programme: p?.programme.code ?? '', includes_simulators: p?.includes_simulators ?? true, includes_courses: p?.includes_courses ?? true,
  billing: p?.billing ?? 'term', term_months: String(p?.term_months ?? 12), min_seats: String(p?.min_seats ?? 10),
  bands: p?.prices.length ? p.prices.map((b) => ({ from: String(b.from_seats), price: (b.unit_price_cents / 100).toFixed(2) })) : [{ from: '10', price: '' }],
});

function Editor({ pkg, manage, onClose, onSaved }: { pkg: PackageItem | 'new' | null; manage: boolean; onClose: () => void; onSaved: (p: PackageItem) => void }) {
  const { t } = useI18n();
  const current = pkg === 'new' ? null : pkg;
  const key = current ? `${current.id}:${current.status}` : 'new';
  return (
    <Drawer open={pkg !== null} onClose={onClose} width="max-w-2xl" title={current ? current.name : t('pkg.new')} subtitle={current ? t(`pkg.status.${current.status}` as MessageKey) : t('pkg.newLead')}>
      {pkg !== null && <EditorBody key={key} pkg={current} editable={manage && (!current || current.status === 'draft')} manage={manage} onClose={onClose} onSaved={onSaved} />}
    </Drawer>
  );
}

function EditorBody({ pkg, editable, manage, onClose, onSaved }: { pkg: PackageItem | null; editable: boolean; manage: boolean; onClose: () => void; onSaved: (p: PackageItem) => void }) {
  const { t } = useI18n();
  const toast = useToast();
  const qc = useQueryClient();
  const [f, setF] = useState<Form>(() => toForm(pkg));
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [seats, setSeats] = useState('25');
  const programmes = useQuery({ queryKey: ['hq-programmes'], queryFn: async () => (await api<{ data: ProgrammeSummary[] }>('/platform/programmes')).data });
  const bands: PriceBand[] = f.bands.filter((b) => Number(b.from) >= 1 && Number(b.price) > 0).map((b) => ({ from_seats: Number(b.from), unit_price_cents: Math.round(Number(b.price) * 100) }));
  const preview = useMemo(() => quotePackage({ prices: bands, min_seats: Number(f.min_seats) || 1, billing: f.billing, term_months: Number(f.term_months) || null }, Number(seats), VAT), [bands, f.min_seats, f.billing, f.term_months, seats]);

  const run = useMutation({
    mutationFn: async (action: 'save' | 'publish' | 'retire' | 'duplicate' | 'delete') => {
      if (action === 'retire' || action === 'duplicate') return (await api<{ data: PackageItem }>(`/platform/packages/${pkg!.code}/${action}`, { method: 'POST' })).data;
      if (action === 'delete') { await api(`/platform/packages/${pkg!.code}`, { method: 'DELETE' }); return null; }
      const body = { name: f.name, description: f.description || null, programme: f.programme, includes_simulators: f.includes_simulators, includes_courses: f.includes_courses, billing: f.billing, term_months: f.billing === 'term' ? Number(f.term_months) : null, min_seats: Number(f.min_seats) };
      let saved = (await api<{ data: PackageItem }>(pkg ? `/platform/packages/${pkg.code}` : '/platform/packages', { method: pkg ? 'PATCH' : 'POST', body })).data;
      saved = (await api<{ data: PackageItem }>(`/platform/packages/${saved.code}/prices`, { method: 'PUT', body: { prices: bands } })).data;
      if (action === 'publish') saved = (await api<{ data: PackageItem }>(`/platform/packages/${saved.code}/publish`, { method: 'POST' })).data;
      return saved;
    },
    onSuccess: (saved, action) => {
      void qc.invalidateQueries({ queryKey: KEY });
      toast(t(`pkg.done.${action}` as MessageKey));
      if (saved) onSaved(saved); else onClose();
    },
    onError: (e) => { if (e instanceof ApiError) setErrors(e.errors); toast(e instanceof ApiError ? e.message : t('common.error'), 'error'); },
  });
  const set = <K extends keyof Form>(k: K, v: Form[K]) => setF({ ...f, [k]: v });

  return (
    <div className="space-y-5">
      <Field label={t('pkg.f.name')} value={f.name} readOnly={!editable} onChange={(e) => set('name', e.target.value)} error={errors.name?.[0]} />
      <div>
        <label htmlFor="pkg-programme" className="mb-1.5 block text-[13px] font-semibold text-ink">{t('pkg.f.programme')}</label>
        <select id="pkg-programme" disabled={!editable} value={f.programme} onChange={(e) => set('programme', e.target.value)} className={inputClass}>
          <option value="">{t('pkg.f.choose')}</option>
          {programmes.data?.map((p) => <option key={p.code} value={p.code}>{p.prefix} · {p.title}</option>)}
        </select>
        {errors.programme?.[0] && <p className="mt-1.5 text-[13px] text-[#C92A2F]">{errors.programme[0]}</p>}
      </div>
      <div>
        <label htmlFor="pkg-desc" className="mb-1.5 block text-[13px] font-semibold text-ink">{t('pkg.f.description')}</label>
        <textarea id="pkg-desc" rows={2} readOnly={!editable} value={f.description} onChange={(e) => set('description', e.target.value)} className={cx(inputClass, 'h-auto py-2.5')} />
      </div>
      <fieldset className="flex flex-wrap gap-4">
        <legend className="mb-1.5 text-[13px] font-semibold text-ink">{t('pkg.f.includes')}</legend>
        {(['includes_simulators', 'includes_courses'] as const).map((k) => (
          <label key={k} className="flex items-center gap-2 text-[14px] text-ink">
            <input type="checkbox" disabled={!editable} checked={f[k]} onChange={(e) => set(k, e.target.checked)} className="size-4 accent-[var(--brand-primary)]" />
            {t(k === 'includes_simulators' ? 'cov.channel.simulator' : 'cov.channel.course')}
          </label>
        ))}
        {errors.includes_simulators?.[0] && <p className="w-full text-[13px] text-[#C92A2F]">{errors.includes_simulators[0]}</p>}
      </fieldset>
      <div className="grid gap-4 sm:grid-cols-3">
        <div>
          <label htmlFor="pkg-billing" className="mb-1.5 block text-[13px] font-semibold text-ink">{t('pkg.f.billing')}</label>
          <select id="pkg-billing" disabled={!editable} value={f.billing} onChange={(e) => set('billing', e.target.value as Form['billing'])} className={inputClass}>
            <option value="term">{t('pkg.billing.term')}</option>
            <option value="monthly">{t('pkg.billing.monthly')}</option>
          </select>
        </div>
        {f.billing === 'term' && <Field label={t('pkg.f.termMonths')} type="number" min={1} max={60} readOnly={!editable} value={f.term_months} onChange={(e) => set('term_months', e.target.value)} error={errors.term_months?.[0]} />}
        <Field label={t('pkg.f.minSeats')} type="number" min={1} readOnly={!editable} value={f.min_seats} onChange={(e) => set('min_seats', e.target.value)} error={errors.min_seats?.[0]} />
      </div>

      <div>
        <h3 className="mb-1 text-[13px] font-semibold text-ink">{t('pkg.f.prices')}</h3>
        <p className="mb-2 text-[13px] text-muted">{t(f.billing === 'term' ? 'pkg.pricesHintTerm' : 'pkg.pricesHintMonthly')}</p>
        <div className="space-y-2">
          {f.bands.map((b, i) => (
            <div key={i} className="flex items-center gap-2">
              <span className="w-12 text-[13px] text-muted">{t('pkg.from')}</span>
              <div className="w-24 shrink-0"><input aria-label={t('pkg.f.fromSeats', { n: i + 1 })} type="number" min={1} readOnly={!editable} value={b.from} onChange={(e) => set('bands', f.bands.map((x, j) => (j === i ? { ...x, from: e.target.value } : x)))} className={cx(inputClass, 'h-10')} /></div>
              <span className="shrink-0 text-[13px] text-muted">{t('pkg.seatsAt')}</span>
              <div className="relative min-w-0 flex-1">
                <span className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-[14px] text-muted">R</span>
                <input aria-label={t('pkg.f.unitPrice', { n: i + 1 })} inputMode="decimal" readOnly={!editable} value={b.price} onChange={(e) => set('bands', f.bands.map((x, j) => (j === i ? { ...x, price: e.target.value } : x)))} className={cx(inputClass, 'h-10 pl-7')} />
              </div>
              {editable && f.bands.length > 1 && <button type="button" aria-label={t('prog.remove')} onClick={() => set('bands', f.bands.filter((_, j) => j !== i))} className="rounded-lg p-2 text-muted hover:bg-surface-2"><X className="size-4" /></button>}
            </div>
          ))}
          {editable && f.bands.length < 10 && <Button size="sm" variant="ghost" icon={<Plus className="size-4" />} onClick={() => set('bands', [...f.bands, { from: '', price: '' }])}>{t('pkg.addBand')}</Button>}
          {errors.prices?.[0] && <p className="text-[13px] text-[#C92A2F]">{errors.prices[0]}</p>}
        </div>
      </div>

      <Card className="bg-surface-2/50 p-4">
        <div className="flex items-center gap-3">
          <label htmlFor="pkg-preview" className="text-[13px] font-semibold text-ink">{t('pkg.preview')}</label>
          <div className="w-24"><input id="pkg-preview" type="number" min={1} value={seats} onChange={(e) => setSeats(e.target.value)} className={cx(inputClass, 'h-9')} /></div>
          <span className="text-[13px] text-muted">{t('pkg.seats')}</span>
        </div>
        {preview ? <QuoteLines quote={preview} billing={f.billing} /> : <p className="mt-2 text-[13px] text-muted">{t('pkg.noPrice')}</p>}
      </Card>

      {manage && (
        <div className="flex flex-wrap justify-end gap-2 border-t border-line pt-4">
          {pkg?.status === 'draft' && <Button variant="ghost" icon={<Trash2 className="size-4" />} onClick={() => run.mutate('delete')}>{t('pkg.delete')}</Button>}
          {pkg && pkg.status !== 'draft' && <Button variant="secondary" icon={<Copy className="size-4" />} loading={run.isPending} onClick={() => run.mutate('duplicate')}>{t('pkg.duplicate')}</Button>}
          {pkg?.status === 'published' && <Button variant="secondary" icon={<Archive className="size-4" />} onClick={() => run.mutate('retire')}>{t('pkg.retire')}</Button>}
          {editable && (
            <>
              <Button variant="secondary" loading={run.isPending && run.variables === 'save'} disabled={!f.name || !f.programme} onClick={() => run.mutate('save')}>{t('pkg.saveDraft')}</Button>
              <Button icon={<Rocket className="size-4" />} loading={run.isPending && run.variables === 'publish'} disabled={!f.name || !f.programme || !preview} onClick={() => run.mutate('publish')}>{t('pkg.publish')}</Button>
            </>
          )}
        </div>
      )}
      {pkg?.status === 'published' && <p className="text-[13px] text-muted">{t('pkg.frozen')}</p>}
    </div>
  );
}
