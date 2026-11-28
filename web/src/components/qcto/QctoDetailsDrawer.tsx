import { useState, type ReactNode } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Lock } from 'lucide-react';
import { QCTO_CODES, type LearnerItem, type QctoAddress, type QctoDetails, type QctoList } from '@simuwork/core';
import { Button, cx, Drawer, Field, inputClass, Skeleton, useToast } from '../ui';
import { api, ApiError } from '../../lib/api';
import { useI18n } from '../../lib/i18n';
import { useQctoLabel } from '../../lib/qctoLabels';

type Form = Record<'title' | 'middle_name' | 'alt_id_type' | 'nationality_code' | 'citizen_status' | 'immigrant_status' | 'socioeconomic_code' | 'province_code'
  | 'stats_area_code' | 'flc_code' | 'flc_sor_number' | 'equity' | 'disability_status' | 'disability_rating', string> & { home: QctoAddress; postal: QctoAddress; samePostal: boolean };
const blankAddress: QctoAddress = { line1: '', line2: '', line3: '', postal_code: '' };

const toForm = (d: QctoDetails): Form => ({
  title: d.title ?? '', middle_name: d.middle_name ?? '', alt_id_type: d.alt_id_type ?? '', nationality_code: d.nationality_code ?? '', citizen_status: d.citizen_status ?? '',
  immigrant_status: d.immigrant_status ?? '', socioeconomic_code: d.socioeconomic_code ?? '', province_code: d.province_code ?? '', stats_area_code: d.stats_area_code ?? '',
  flc_code: d.flc_code ?? '', flc_sor_number: d.flc_sor_number ?? '', equity: d.equity ?? '', disability_status: d.disability_status ?? '', disability_rating: d.disability_rating ?? '',
  home: { ...blankAddress, ...d.home_address, line3: d.home_address?.line3 ?? '' }, postal: { ...blankAddress, ...d.postal_address, line3: d.postal_address?.line3 ?? '' }, samePostal: d.postal_address === null,
});

/** Staff: a learner's details for QCTO's enrolment file. */
export function QctoDetailsDrawer({ learner, onClose }: { learner: LearnerItem; onClose: () => void }) {
  const { t } = useI18n();
  const details = useQuery({ queryKey: ['learner-qcto', learner.id], queryFn: () => api<{ data: QctoDetails }>(`/learners/${learner.id}/qcto`) });
  return (
    <Drawer open onClose={onClose} width="max-w-2xl" title={t('qd.title', { name: learner.name })} subtitle={t('qd.lead')}>
      {details.data ? <QctoForm learner={learner} details={details.data.data} onClose={onClose} /> : <Skeleton className="h-96 rounded-xl" />}
    </Drawer>
  );
}

function QctoForm({ learner, details, onClose }: { learner: LearnerItem; details: QctoDetails; onClose: () => void }) {
  const { t } = useI18n();
  const toast = useToast();
  const qc = useQueryClient();
  const [form, setForm] = useState<Form>(() => toForm(details));
  const [errors, setErrors] = useState<Record<string, string>>({});
  const codeLabel = useQctoLabel();
  const set = (k: keyof Form) => (v: string) => setForm((f) => ({ ...f, [k]: v }));
  const setAddr = (which: 'home' | 'postal', k: keyof QctoAddress) => (v: string) => setForm((f) => ({ ...f, [which]: { ...f[which], [k]: v } }));

  const save = useMutation({
    mutationFn: () => {
      const { home, postal, samePostal, ...rest } = form;
      const body: Record<string, unknown> = Object.fromEntries(Object.entries(rest).map(([k, v]) => [k, v === '' ? null : v]));
      body.home_address = home.line1 || home.line2 || home.postal_code ? home : null;
      body.postal_address = samePostal ? null : postal;
      if (learner.id_type === 'sa_id') delete body.alt_id_type;
      return api<{ data: QctoDetails }>(`/learners/${learner.id}/qcto`, { method: 'PATCH', body });
    },
    onSuccess: (r) => {
      qc.setQueryData(['learner-qcto', learner.id], r);
      void qc.invalidateQueries({ queryKey: ['qcto-check'] });
      toast(r.data.missing.length ? t('qd.savedMissing', { n: r.data.missing.length }) : t('qd.savedComplete'));
      onClose();
    },
    onError: (e) => {
      if (e instanceof ApiError && Object.keys(e.errors).length) setErrors(Object.fromEntries(Object.entries(e.errors).map(([k, v]) => [k.split('.')[0] ?? k, v[0] ?? ''])));
      else toast(e instanceof ApiError ? e.message : t('common.error'), 'error');
    },
  });

  const select = (field: keyof Form, list: QctoList, label: string, plain = false) => (
    <div>
      <label htmlFor={`qd-${field}`} className="mb-1.5 block text-[13px] font-semibold text-ink">{label}</label>
      <select id={`qd-${field}`} value={form[field] as string} onChange={(e) => set(field)(e.target.value)} className={cx(inputClass, errors[field] && 'border-[#C92A2F]')}>
        <option value="">{t('qd.choose')}</option>
        {QCTO_CODES[list].map((c) => <option key={c} value={c}>{plain ? c : codeLabel(list, c)}</option>)}
      </select>
      {errors[field] && <p role="alert" className="mt-1.5 text-[13px] font-medium text-[#C92A2F]">{errors[field]}</p>}
    </div>
  );
  const address = (which: 'home' | 'postal') => (
    <div className="grid gap-3 sm:grid-cols-2">
      <Field label={t('qd.line1')} value={form[which].line1} onChange={(e) => setAddr(which, 'line1')(e.target.value)} placeholder="12 Vilakazi Street" error={errors[`${which}_address`]} />
      <Field label={t('qd.line2')} value={form[which].line2} onChange={(e) => setAddr(which, 'line2')(e.target.value)} placeholder="Orlando West" />
      <Field label={t('qd.line3')} value={form[which].line3 ?? ''} onChange={(e) => setAddr(which, 'line3')(e.target.value)} placeholder="Soweto" />
      <Field label={t('qd.postalCode')} value={form[which].postal_code} inputMode="numeric" maxLength={4} onChange={(e) => setAddr(which, 'postal_code')(e.target.value.replace(/\D/g, ''))} placeholder="0181" />
    </div>
  );

  return (
    <form onSubmit={(e) => { e.preventDefault(); setErrors({}); save.mutate(); }} className="space-y-6" noValidate>
      <Group title={t('qd.personal')}>
        <div className="grid gap-3 sm:grid-cols-2">
          {select('title', 'titles', t('qd.f.title'), true)}
          <Field label={t('qd.f.middle')} value={form.middle_name} onChange={(e) => set('middle_name')(e.target.value)} />
        </div>
      </Group>
      <Group title={t('qd.citizenship')}>
        <div className="grid gap-3 sm:grid-cols-2">
          {learner.id_type !== 'sa_id' && select('alt_id_type', 'alt_id_types', t('qd.f.altId'))}
          {select('nationality_code', 'nationality', t('qd.f.nationality'))}
          {select('citizen_status', 'citizen', t('qd.f.citizen'))}
          {select('immigrant_status', 'immigrant', t('qd.f.immigrant'))}
        </div>
      </Group>
      <Group title={t('qd.home')}>
        {address('home')}
        <div className="mt-3 grid gap-3 sm:grid-cols-2">
          {select('province_code', 'province', t('qd.f.province'))}
          <Field label={t('qd.f.statsArea')} value={form.stats_area_code} onChange={(e) => set('stats_area_code')(e.target.value.toUpperCase())} hint={t('qd.statsHint')} error={errors.stats_area_code} />
        </div>
        <label className="mt-4 flex items-center gap-2.5 text-[14px] text-ink">
          <input type="checkbox" className="size-4 accent-[var(--brand)]" checked={form.samePostal} onChange={(e) => setForm((f) => ({ ...f, samePostal: e.target.checked }))} />{t('qd.samePostal')}
        </label>
        {!form.samePostal && <div className="mt-3">{address('postal')}</div>}
      </Group>
      <Group title={t('qd.education')}>
        <div className="grid gap-3 sm:grid-cols-2">
          {select('socioeconomic_code', 'socioeconomic', t('qd.f.socio'))}
          {select('flc_code', 'flc', t('qd.f.flc'))}
          <Field label={t('qd.f.flcNumber')} value={form.flc_sor_number} onChange={(e) => set('flc_sor_number')(e.target.value)} hint={t('qd.flcHint')} />
        </div>
      </Group>
      <Group title={t('qd.special')} icon={<Lock className="size-4" />} note={t('qd.specialNote')}>
        <div className="grid gap-3 sm:grid-cols-2">
          {select('equity', 'equity', t('qd.f.equity'))}
          {select('disability_status', 'disability', t('qd.f.disability'))}
          {form.disability_status !== '' && form.disability_status !== 'N' && select('disability_rating', 'disability_rating', t('qd.f.rating'))}
        </div>
      </Group>
      <p className="text-[12px] text-muted">{details.popi_agreed_at ? t('qd.popiYes') : t('qd.popiNo')}</p>
      <div className="flex justify-end gap-2 border-t border-line pt-4">
        <Button variant="secondary" onClick={onClose}>{t('common.cancel')}</Button>
        <Button type="submit" loading={save.isPending}>{t('qd.save')}</Button>
      </div>
    </form>
  );
}

function Group({ title, icon, note, children }: { title: string; icon?: ReactNode; note?: string; children: ReactNode }) {
  return (
    <fieldset>
      <legend className="mb-3 flex items-center gap-2 text-[14px] font-bold text-ink">{icon}{title}</legend>
      {note && <p className="-mt-1 mb-3 rounded-xl bg-surface-2 px-3 py-2 text-[12px] text-muted">{note}</p>}
      {children}
    </fieldset>
  );
}
