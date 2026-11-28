import { useState, type FormEvent } from 'react';
import { Link, useNavigate } from 'react-router';
import { useMutation, useQuery } from '@tanstack/react-query';
import { CalendarDays, GraduationCap, MapPin, Plus, Users } from 'lucide-react';
import type { CohortDetail, CohortItem, CohortOptions, QctoType } from '@simuwork/core';
import type { MessageKey } from '@simuwork/core/locales/en';
import { Badge, Button, Card, cx, Drawer, EmptyState, Field, inputClass, PageHeader, Skeleton, useToast } from '../../components/ui';
import { api, ApiError } from '../../lib/api';
import { useI18n } from '../../lib/i18n';
import { can, useSession } from '../../lib/session';

/** Provider: cohorts (classes of learners on one programme). */
export default function Cohorts() {
  const { t, locale } = useI18n();
  const { data: session } = useSession();
  const [archived, setArchived] = useState(false);
  const [creating, setCreating] = useState(false);
  const list = useQuery({ queryKey: ['cohorts', archived], queryFn: () => api<{ data: CohortItem[] }>(`/cohorts${archived ? '?archived=1' : ''}`) });
  const date = (d: string) => new Date(d).toLocaleDateString(locale, { day: 'numeric', month: 'short', year: 'numeric' });

  return (
    <div className="sw-in">
      <PageHeader title={t('coh.title')} lead={t('coh.lead')}
        actions={can(session, 'classes.manage') && <Button icon={<Plus className="size-4" />} onClick={() => setCreating(true)}>{t('coh.new')}</Button>} />
      <div role="tablist" className="mb-5 inline-flex rounded-xl bg-surface-2 p-1">
        {[false, true].map((a) => (
          <button key={String(a)} role="tab" aria-selected={archived === a} onClick={() => setArchived(a)} className={cx('h-9 rounded-lg px-4 text-[13px] font-semibold transition', archived === a ? 'bg-surface text-ink shadow-sm' : 'text-muted hover:text-ink')}>{t(a ? 'coh.archived' : 'coh.current')}</button>
        ))}
      </div>
      {!list.data ? (
        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">{Array.from({ length: 3 }, (_, i) => <Skeleton key={i} className="h-40 rounded-2xl" />)}</div>
      ) : list.data.data.length === 0 ? (
        <Card><EmptyState icon={<GraduationCap className="size-6" />} title={t(archived ? 'coh.noneArchived' : 'coh.none')} body={archived ? undefined : t('coh.noneBody')} /></Card>
      ) : (
        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
          {list.data.data.map((c) => (
            <Link key={c.id} to={`/admin/cohorts/${c.id}`} className="group rounded-2xl border border-line bg-surface p-5 shadow-xs transition hover:-translate-y-0.5 hover:border-brand/40 hover:shadow-md">
              <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                  <p className="truncate text-[12px] font-semibold uppercase tracking-wide text-muted">{c.programme.title} · v{c.version}</p>
                  <h2 className="mt-1 text-[17px] font-bold text-ink group-hover:text-brand">{c.name}</h2>
                </div>
                <Badge tone={c.join_open ? 'success' : 'neutral'}>{t(c.join_open ? 'coh.joinOpen' : 'coh.joinClosed')}</Badge>
              </div>
              <dl className="mt-4 space-y-1.5 text-[13px] text-muted">
                <div className="flex items-center gap-2"><CalendarDays className="size-4" />{date(c.starts_on)}{c.ends_on ? ` – ${date(c.ends_on)}` : ''}</div>
                <div className="flex items-center gap-2"><MapPin className="size-4" />{c.centre.name}</div>
                <div className="flex items-center gap-2"><Users className="size-4" />{t('coh.nLearners', { n: c.learners })}</div>
              </dl>
            </Link>
          ))}
        </div>
      )}
      <NewCohort open={creating} onClose={() => setCreating(false)} />
    </div>
  );
}

function NewCohort({ open, onClose }: { open: boolean; onClose: () => void }) {
  const { t } = useI18n();
  const toast = useToast();
  const navigate = useNavigate();
  const options = useQuery({ queryKey: ['cohort-options'], queryFn: () => api<{ data: CohortOptions }>('/cohorts/options'), enabled: open });
  const blank = { name: '', programme_id: '', centre_id: '', starts_on: '', ends_on: '', qcto_type: 'qualification' as QctoType };
  const [form, setForm] = useState(blank);
  const [staff, setStaff] = useState<string[]>([]);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const set = (k: keyof typeof blank) => (v: string) => setForm((f) => ({ ...f, [k]: v }));
  const o = options.data?.data;
  const centre = form.centre_id || o?.centres[0]?.id || '';
  const close = () => { setForm(blank); setStaff([]); setErrors({}); onClose(); };

  const save = useMutation({
    mutationFn: () => api<{ data: CohortDetail }>('/cohorts', { method: 'POST', body: { ...form, centre_id: centre, ends_on: form.ends_on || null, facilitator_ids: staff } }),
    onSuccess: (r) => { toast(t('coh.created')); close(); void navigate(`/admin/cohorts/${r.data.id}`); },
    onError: (e) => {
      if (e instanceof ApiError && Object.keys(e.errors).length) setErrors(Object.fromEntries(Object.entries(e.errors).map(([k, v]) => [k, v[0] ?? ''])));
      else toast(e instanceof ApiError ? e.message : t('common.error'), 'error');
    },
  });
  const submit = (e: FormEvent) => { e.preventDefault(); setErrors({}); save.mutate(); };
  const select = (id: string, label: string, value: string, onChange: (v: string) => void, items: { id: string; label: string }[], error?: string) => (
    <div>
      <label htmlFor={id} className="mb-1.5 block text-[13px] font-semibold text-ink">{label}</label>
      <select id={id} value={value} onChange={(e) => onChange(e.target.value)} className={cx(inputClass, error && 'border-[#C92A2F]')}>{items.map((i) => <option key={i.id} value={i.id}>{i.label}</option>)}</select>
      {error && <p className="mt-1.5 text-[13px] font-medium text-[#C92A2F]" role="alert">{error}</p>}
    </div>
  );

  return (
    <Drawer open={open} onClose={close} title={t('coh.new')} subtitle={t('coh.newLead')}
      footer={<div className="flex justify-end gap-2"><Button variant="secondary" onClick={close}>{t('common.cancel')}</Button><Button type="submit" form="new-cohort" loading={save.isPending}>{t('coh.create')}</Button></div>}>
      {!o ? <Skeleton className="h-64 rounded-xl" /> : o.programmes.length === 0 ? (
        <EmptyState icon={<GraduationCap className="size-6" />} title={t('coh.noSeats')} body={t('coh.noSeatsBody')} action={<Link to="/admin/licences" className="text-[14px] font-semibold text-brand hover:underline">{t('coh.toShop')}</Link>} />
      ) : (
        <form id="new-cohort" onSubmit={submit} className="space-y-4" noValidate>
          <Field label={t('coh.f.name')} placeholder={t('coh.f.namePh')} value={form.name} onChange={(e) => set('name')(e.target.value)} error={errors.name} />
          {select('coh-prog', t('coh.f.programme'), form.programme_id, set('programme_id'), [{ id: '', label: t('coh.f.pick') }, ...o.programmes.map((p) => ({ id: p.id, label: t('coh.f.programmeOpt', { title: p.title, n: p.free_seats }) }))], errors.programme_id)}
          {select('coh-centre', t('coh.f.centre'), centre, set('centre_id'), o.centres.map((c) => ({ id: c.id, label: c.name })), errors.centre_id)}
          <div className="grid gap-4 sm:grid-cols-2">
            <Field type="date" label={t('coh.f.start')} value={form.starts_on} onChange={(e) => set('starts_on')(e.target.value)} error={errors.starts_on} />
            <Field type="date" label={t('coh.f.end')} value={form.ends_on} onChange={(e) => set('ends_on')(e.target.value)} error={errors.ends_on} />
          </div>
          {select('coh-qcto', t('coh.f.qcto'), form.qcto_type, (v) => set('qcto_type')(v), (['qualification', 'skills_programme', 'none'] as const).map((k) => ({ id: k, label: t(`coh.qcto.${k}` as MessageKey) })))}
          <fieldset>
            <legend className="mb-1.5 text-[13px] font-semibold text-ink">{t('coh.f.facilitators')}</legend>
            <div className="max-h-40 space-y-1 overflow-y-auto rounded-xl border border-line p-2">
              {o.staff.map((s) => (
                <label key={s.id} className="flex items-center gap-2.5 rounded-lg px-2 py-1.5 text-[14px] hover:bg-surface-2">
                  <input type="checkbox" className="size-4 accent-[var(--brand)]" checked={staff.includes(s.id)} onChange={(e) => setStaff((x) => (e.target.checked ? [...x, s.id] : x.filter((i) => i !== s.id)))} />{s.name}
                </label>
              ))}
            </div>
            {errors.facilitator_ids && <p className="mt-1.5 text-[13px] font-medium text-[#C92A2F]" role="alert">{errors.facilitator_ids}</p>}
          </fieldset>
          <p className="text-[12px] text-muted">{t('coh.pinNote')}</p>
        </form>
      )}
    </Drawer>
  );
}
