import { useState, type ReactNode } from 'react';
import { AlertTriangle, CheckCircle2, ShieldAlert } from 'lucide-react';
import type { AccreditationState, ProviderAddress, ProviderDetails } from '@simuwork/core';
import type { MessageKey } from '@simuwork/core/locales/en';
import { Button, Card, cx, Field } from './ui';
import { useI18n } from '../lib/i18n';

const HQ_FIELDS = ['name', 'legal_name', 'registration_number', 'vat_number', 'accreditation_number', 'accreditation_expires_at'] as const;
const CONTACT_FIELDS = ['contact_email', 'contact_phone', 'billing_email'] as const;
const ADDRESS_FIELDS = ['line1', 'line2', 'city', 'province', 'postal_code'] as const;
type Values = Record<(typeof HQ_FIELDS)[number] | (typeof CONTACT_FIELDS)[number], string> & { address: Required<Record<keyof ProviderAddress, string>> } & { terms_mode: string; terms_days: string };

const toValues = (p: ProviderDetails): Values => ({
  name: p.name, legal_name: p.legal_name ?? '', registration_number: p.registration_number ?? '', vat_number: p.vat_number ?? '',
  accreditation_number: p.accreditation_number ?? '', accreditation_expires_at: p.accreditation_expires_at ?? '',
  contact_email: p.contact_email ?? '', contact_phone: p.contact_phone ?? '', billing_email: p.billing_email ?? '',
  address: { line1: p.address.line1 ?? '', line2: p.address.line2 ?? '', city: p.address.city ?? '', province: p.address.province ?? '', postal_code: p.address.postal_code ?? '' },
  terms_mode: p.payment_terms.mode, terms_days: String(p.payment_terms.days),
});

/**
 * Company, accreditation, contact and billing details of a provider.
 * HQ edits everything (`scope="hq"`); the provider edits only contact, billing and address (`scope="provider"`).
 */
export function ProviderDetailsForm({ provider, scope, editable, onSave, errors }: {
  provider: ProviderDetails;
  scope: 'hq' | 'provider';
  editable: boolean;
  onSave: (changes: Record<string, unknown>) => Promise<boolean>;
  errors?: Record<string, string[]>;
}) {
  const { t } = useI18n();
  const [values, setValues] = useState<Values>(() => toValues(provider));
  const [saving, setSaving] = useState(false);
  const initial = toValues(provider);
  const canEdit = (field: string) => editable && (scope === 'hq' || (CONTACT_FIELDS as readonly string[]).includes(field) || field.startsWith('address'));

  const changes: Record<string, unknown> = {};
  for (const f of [...HQ_FIELDS, ...CONTACT_FIELDS]) {
    if (values[f] !== initial[f] && canEdit(f)) changes[f] = values[f] === '' ? (f === 'name' ? initial.name : null) : values[f];
  }
  if (canEdit('address') && ADDRESS_FIELDS.some((k) => values.address[k] !== initial.address[k])) {
    changes.address = Object.fromEntries(ADDRESS_FIELDS.map((k) => [k, values.address[k] || null]));
  }
  if (scope === 'hq' && editable && (values.terms_mode !== initial.terms_mode || values.terms_days !== initial.terms_days)) {
    changes.payment_terms = { mode: values.terms_mode, days: values.terms_mode === 'on_account' ? Number(values.terms_days) || 0 : 0 };
  }
  const dirty = Object.keys(changes).length > 0;

  const input = (field: (typeof HQ_FIELDS)[number] | (typeof CONTACT_FIELDS)[number], type = 'text') => (
    <Field
      label={t(`acad.f.${field}` as MessageKey)}
      type={type}
      value={values[field]}
      readOnly={!canEdit(field)}
      className={cx(!canEdit(field) && '[&_input]:bg-surface-2/60 [&_input]:text-muted')}
      error={errors?.[field]?.[0]}
      onChange={(e) => setValues({ ...values, [field]: e.target.value })}
    />
  );

  return (
    <form
      className="space-y-5"
      onSubmit={(e) => {
        e.preventDefault();
        setSaving(true);
        void onSave(changes).then(() => setSaving(false));
      }}
    >
      <Section title={t('acad.company')} note={scope === 'provider' ? t('acad.askHq') : undefined}>
        {input('name')}
        {input('legal_name')}
        {input('registration_number')}
        {input('vat_number')}
      </Section>
      <Section title={t('acad.accreditation')} aside={<AccreditationBadge state={provider.accreditation} />}>
        {input('accreditation_number')}
        {input('accreditation_expires_at', 'date')}
      </Section>
      <Section title={t('acad.contact')}>
        {input('contact_email', 'email')}
        {input('contact_phone', 'tel')}
        {input('billing_email', 'email')}
      </Section>
      <Section title={t('acad.address')}>
        {ADDRESS_FIELDS.map((k) => (
          <Field
            key={k}
            label={t(`acad.f.address.${k}` as MessageKey)}
            value={values.address[k]}
            readOnly={!canEdit('address')}
            error={errors?.[`address.${k}`]?.[0]}
            onChange={(e) => setValues({ ...values, address: { ...values.address, [k]: e.target.value } })}
          />
        ))}
      </Section>
      {scope === 'hq' && (
        <Section title={t('acad.terms')}>
          <div>
            <label htmlFor="terms-mode" className="mb-1.5 block text-[13px] font-semibold text-ink">{t('acad.f.terms_mode')}</label>
            <select id="terms-mode" disabled={!editable} value={values.terms_mode} onChange={(e) => setValues({ ...values, terms_mode: e.target.value })} className="h-11 w-full rounded-xl border border-line bg-surface px-3.5 text-[15px] text-ink">
              <option value="pay_first">{t('acad.terms.pay_first')}</option>
              <option value="on_account">{t('acad.terms.on_account')}</option>
            </select>
          </div>
          {values.terms_mode === 'on_account' && (
            <Field label={t('acad.f.terms_days')} type="number" min={0} max={90} value={values.terms_days} readOnly={!editable} onChange={(e) => setValues({ ...values, terms_days: e.target.value })} />
          )}
        </Section>
      )}
      {editable && dirty && (
        <div className="sw-in sticky bottom-4 z-10 flex items-center justify-between gap-3 rounded-2xl border border-line bg-surface/95 p-3 pl-5 shadow-lg backdrop-blur">
          <span className="text-[13px] font-semibold text-ink">{t('acad.unsaved')}</span>
          <div className="flex gap-2">
            <Button type="button" variant="ghost" onClick={() => setValues(initial)}>{t('common.cancel')}</Button>
            <Button type="submit" loading={saving}>{t('common.save')}</Button>
          </div>
        </div>
      )}
    </form>
  );
}

function Section({ title, note, aside, children }: { title: string; note?: string; aside?: ReactNode; children: ReactNode }) {
  return (
    <Card className="p-5">
      <div className="mb-4 flex flex-wrap items-center justify-between gap-2">
        <div>
          <h2 className="text-[15px] font-bold text-ink">{title}</h2>
          {note && <p className="text-[13px] text-muted">{note}</p>}
        </div>
        {aside}
      </div>
      <div className="grid gap-4 sm:grid-cols-2">{children}</div>
    </Card>
  );
}

export function AccreditationBadge({ state }: { state: AccreditationState }) {
  const { t } = useI18n();
  const map = {
    ok: { cls: 'bg-[#E6F6F1] text-[#0B7A5C] dark:bg-[#0B7A5C]/20 dark:text-[#5FD3B1]', Icon: CheckCircle2 },
    expiring: { cls: 'bg-[#FDF4E1] text-[#8A5A00] dark:bg-[#8A5A00]/25 dark:text-[#F5C46B]', Icon: AlertTriangle },
    expired: { cls: 'bg-[#FDECEC] text-[#B42328] dark:bg-[#B42328]/20 dark:text-[#FF9A9E]', Icon: ShieldAlert },
    missing: { cls: 'bg-surface-2 text-muted', Icon: AlertTriangle },
  }[state.state];
  const label = state.state === 'expiring' ? t('acad.acc.expiring', { n: state.days_left ?? 0 }) : t(`acad.acc.${state.state}` as MessageKey);
  return <span className={cx('inline-flex h-7 items-center gap-1.5 rounded-full px-2.5 text-[12px] font-semibold', map.cls)}><map.Icon className="size-3.5" aria-hidden />{label}</span>;
}
