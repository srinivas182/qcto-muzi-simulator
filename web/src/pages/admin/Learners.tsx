import { useMemo, useState, type FormEvent, type ReactNode } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { CheckCircle2, Copy, Eye, FileUp, MessageCircle, Plus, Search, Send, UserMinus, UserPlus, Users } from 'lucide-react';
import { checkSaId, HOME_LANGUAGES, type ImportReport, type LearnerItem, type LearnerStatus, type QctoDetails } from '@simuwork/core';
import type { MessageKey } from '@simuwork/core/locales/en';
import { Badge, Button, Card, cx, Drawer, EmptyState, Field, inputClass, PageHeader, Skeleton, Switch, useToast } from '../../components/ui';
import { readLearnerFile, TEMPLATE_CSV, type ImportRow } from '../../components/learners/importFile';
import { QctoDetailsDrawer } from '../../components/qcto/QctoDetailsDrawer';
import { api, ApiError } from '../../lib/api';
import { useI18n } from '../../lib/i18n';
import { can, useSession } from '../../lib/session';

type List = { data: LearnerItem[]; counts: Partial<Record<LearnerStatus, number>> };
type Filter = 'all' | LearnerStatus;
const FILTERS: Filter[] = ['all', 'invited', 'active', 'inactive'];
const TONE = { invited: 'warning', active: 'success', inactive: 'neutral' } as const;

/** +27825550101 → 082 555 0101 (how South Africans write mobile numbers). */
const localPhone = (p: string | null) => (p && /^\+27\d{9}$/.test(p) ? `0${p.slice(3, 5)} ${p.slice(5, 8)} ${p.slice(8)}` : p);
const firstError = (e: unknown, fallback: string) => (e instanceof ApiError ? Object.values(e.errors)[0]?.[0] ?? e.message : fallback);

/** Provider: the learner register — add one, import many, invite, and manage. */
export default function Learners() {
  const { t } = useI18n();
  const { data: session } = useSession();
  const manage = can(session, 'learners.manage');
  const [q, setQ] = useState('');
  const [filter, setFilter] = useState<Filter>('all');
  const [adding, setAdding] = useState(false);
  const [importing, setImporting] = useState(false);
  const [openId, setOpenId] = useState<string | null>(null);
  const list = useQuery({ queryKey: ['learners'], queryFn: () => api<List>('/learners') });

  const shown = useMemo(() => {
    const needle = q.trim().toLowerCase();
    const digits = needle.replace(/\D/g, '');
    return (list.data?.data ?? []).filter((l) => (filter === 'all' || l.status === filter) && (needle === ''
      || l.name.toLowerCase().includes(needle) || (l.email ?? '').includes(needle)
      || (digits.length >= 3 && ((l.phone ?? '').includes(digits) || l.id_masked.endsWith(digits)))));
  }, [list.data, q, filter]);
  const total = list.data?.data.length ?? 0;
  const open = list.data?.data.find((l) => l.id === openId) ?? null;

  return (
    <div className="sw-in">
      <PageHeader
        title={t('lrn.title')}
        lead={t('lrn.lead')}
        actions={manage && (
          <div className="flex gap-2">
            <Button variant="secondary" icon={<FileUp className="size-4" />} onClick={() => setImporting(true)}>{t('lrn.import')}</Button>
            <Button icon={<Plus className="size-4" />} onClick={() => setAdding(true)}>{t('lrn.add')}</Button>
          </div>
        )}
      />
      <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div role="tablist" className="inline-flex rounded-xl bg-surface-2 p-1">
          {FILTERS.map((k) => (
            <button key={k} role="tab" aria-selected={filter === k} onClick={() => setFilter(k)} className={cx('h-9 whitespace-nowrap rounded-lg px-3.5 text-[13px] font-semibold transition', filter === k ? 'bg-surface text-ink shadow-sm' : 'text-muted hover:text-ink')}>
              {t(`lrn.filter.${k}` as MessageKey)} <span className="ml-1 text-muted">{k === 'all' ? total : list.data?.counts[k] ?? 0}</span>
            </button>
          ))}
        </div>
        <label className="relative sm:w-72">
          <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted" />
          <span className="sr-only">{t('lrn.search')}</span>
          <input value={q} onChange={(e) => setQ(e.target.value)} placeholder={t('lrn.search')} className={cx(inputClass, 'pl-9')} />
        </label>
      </div>

      {!list.data ? (
        <div className="space-y-2">{Array.from({ length: 5 }, (_, i) => <Skeleton key={i} className="h-14 rounded-xl" />)}</div>
      ) : total === 0 ? (
        <Card><EmptyState icon={<Users className="size-6" />} title={t('lrn.none')} body={t('lrn.noneBody')} action={manage && <Button onClick={() => setImporting(true)}>{t('lrn.import')}</Button>} /></Card>
      ) : (
        <Card className="overflow-hidden p-0">
          <table className="w-full text-left text-[14px]">
            <thead className="border-b border-line bg-surface-2 text-[12px] font-semibold uppercase tracking-wide text-muted">
              <tr><th className="px-4 py-3">{t('lrn.col.name')}</th><th className="hidden px-4 py-3 md:table-cell">{t('lrn.col.id')}</th><th className="hidden px-4 py-3 sm:table-cell">{t('lrn.col.contact')}</th><th className="px-4 py-3">{t('lrn.col.status')}</th></tr>
            </thead>
            <tbody className="divide-y divide-line">
              {shown.map((l) => (
                <tr key={l.id} onClick={() => setOpenId(l.id)} className="cursor-pointer hover:bg-surface-2">
                  <td className="px-4 py-3"><button className="text-left font-semibold text-ink hover:underline">{l.name}</button></td>
                  <td className="hidden px-4 py-3 font-mono text-[13px] text-muted md:table-cell">{l.id_masked}</td>
                  <td className="hidden px-4 py-3 text-muted sm:table-cell">{localPhone(l.phone) ?? l.email}</td>
                  <td className="px-4 py-3"><Badge tone={TONE[l.status]}>{t(`lrn.status.${l.status}` as MessageKey)}</Badge></td>
                </tr>
              ))}
            </tbody>
          </table>
          {shown.length === 0 && <p className="px-4 py-8 text-center text-[14px] text-muted">{t('lrn.noMatch')}</p>}
        </Card>
      )}

      <AddLearner open={adding} onClose={() => setAdding(false)} />
      <ImportLearners open={importing} onClose={() => setImporting(false)} />
      <LearnerDrawer learner={open} manage={manage} reveal={can(session, 'learners.reveal_id')} onClose={() => setOpenId(null)} />
    </div>
  );
}

function AddLearner({ open, onClose }: { open: boolean; onClose: () => void }) {
  const { t } = useI18n();
  const toast = useToast();
  const qc = useQueryClient();
  const blank = { first_names: '', surname: '', id_type: 'sa_id' as 'sa_id' | 'passport', id_number: '', date_of_birth: '', phone: '', email: '', home_language: '' };
  const [form, setForm] = useState(blank);
  const [invite, setInvite] = useState(true);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const set = (k: keyof typeof blank) => (v: string) => setForm((f) => ({ ...f, [k]: v }));
  const id = form.id_number.replace(/\s/g, '');
  const facts = form.id_type === 'sa_id' && id.length === 13 ? checkSaId(id) : null;
  const idError = form.id_type === 'sa_id' && id.length === 13 && !facts ? t('lrn.idInvalid') : errors.id_number;
  const close = () => { setForm(blank); setErrors({}); onClose(); };

  const save = useMutation({
    mutationFn: () => api<{ data: LearnerItem }>('/learners', { method: 'POST', body: { ...form, date_of_birth: form.date_of_birth || null, home_language: form.home_language || null, invite } }),
    onSuccess: (r) => { void qc.invalidateQueries({ queryKey: ['learners'] }); toast(t('lrn.added', { name: r.data.name })); close(); },
    onError: (e) => {
      if (e instanceof ApiError && Object.keys(e.errors).length) setErrors(Object.fromEntries(Object.entries(e.errors).map(([k, v]) => [k, v[0] ?? ''])));
      else toast(firstError(e, t('common.error')), 'error');
    },
  });
  const submit = (e: FormEvent) => { e.preventDefault(); setErrors({}); save.mutate(); };

  return (
    <Drawer open={open} onClose={close} title={t('lrn.add')} subtitle={t('lrn.addLead')}
      footer={<div className="flex justify-end gap-2"><Button variant="secondary" onClick={close}>{t('common.cancel')}</Button><Button type="submit" form="add-learner" loading={save.isPending} icon={<UserPlus className="size-4" />}>{t('lrn.save')}</Button></div>}>
      <form id="add-learner" onSubmit={submit} className="space-y-4" noValidate>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label={t('lrn.f.first')} value={form.first_names} onChange={(e) => set('first_names')(e.target.value)} error={errors.first_names} autoComplete="off" required />
          <Field label={t('lrn.f.surname')} value={form.surname} onChange={(e) => set('surname')(e.target.value)} error={errors.surname} autoComplete="off" required />
        </div>
        <div>
          <div role="radiogroup" aria-label={t('lrn.f.idType')} className="mb-2 inline-flex rounded-xl bg-surface-2 p-1">
            {(['sa_id', 'passport'] as const).map((k) => (
              <button type="button" key={k} role="radio" aria-checked={form.id_type === k} onClick={() => set('id_type')(k)} className={cx('h-8 rounded-lg px-3 text-[13px] font-semibold', form.id_type === k ? 'bg-surface text-ink shadow-sm' : 'text-muted')}>{t(`lrn.idType.${k}` as MessageKey)}</button>
            ))}
          </div>
          <Field label={t(form.id_type === 'sa_id' ? 'lrn.f.saId' : 'lrn.f.passport')} value={form.id_number} inputMode={form.id_type === 'sa_id' ? 'numeric' : 'text'} maxLength={form.id_type === 'sa_id' ? 13 : 20}
            onChange={(e) => set('id_number')(form.id_type === 'sa_id' ? e.target.value.replace(/\D/g, '') : e.target.value.toUpperCase())} error={idError} autoComplete="off"
            hint={facts ? <span className="inline-flex items-center gap-1 text-[#1E7A46] dark:text-[#6FD49A]"><CheckCircle2 className="size-3.5" />{t('lrn.idOk', { dob: facts.date_of_birth, gender: t(`lrn.gender.${facts.gender}` as MessageKey) })}</span> : t('lrn.idHint')} />
          {form.id_type === 'passport' && <Field className="mt-4" type="date" label={t('lrn.f.dob')} value={form.date_of_birth} onChange={(e) => set('date_of_birth')(e.target.value)} error={errors.date_of_birth} />}
        </div>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label={t('lrn.f.phone')} type="tel" inputMode="tel" placeholder="082 123 4567" value={form.phone} onChange={(e) => set('phone')(e.target.value)} error={errors.phone} />
          <Field label={t('lrn.f.email')} type="email" value={form.email} onChange={(e) => set('email')(e.target.value)} error={errors.email} />
        </div>
        <div>
          <label htmlFor="lrn-lang" className="mb-1.5 block text-[13px] font-semibold text-ink">{t('lrn.f.lang')}</label>
          <select id="lrn-lang" value={form.home_language} onChange={(e) => set('home_language')(e.target.value)} className={inputClass}>
            <option value="">{t('lrn.f.langNone')}</option>
            {HOME_LANGUAGES.map((l) => <option key={l} value={l}>{t(`lrn.lang.${l}` as MessageKey)}</option>)}
          </select>
        </div>
        <label className="flex items-center gap-3 text-[14px] font-medium text-ink"><Switch checked={invite} onChange={setInvite} label={t('lrn.inviteNow')} />{t('lrn.inviteNow')}</label>
        <p className="text-[12px] text-muted">{t('lrn.popia')}</p>
      </form>
    </Drawer>
  );
}

function LearnerDrawer({ learner, manage, reveal, onClose }: { learner: LearnerItem | null; manage: boolean; reveal: boolean; onClose: () => void }) {
  const { t, locale } = useI18n();
  const toast = useToast();
  const qc = useQueryClient();
  const [shownId, setShownId] = useState<{ id: string; number: string } | null>(null);
  const [link, setLink] = useState<{ id: string; url: string; channel: string } | null>(null);
  const done = () => void qc.invalidateQueries({ queryKey: ['learners'] });

  const invite = useMutation({
    mutationFn: (id: string) => api<{ data: { url: string; channel: string } }>(`/learners/${id}/invite`, { method: 'POST' }),
    onSuccess: (r, id) => { setLink({ id, ...r.data }); toast(t(`lrn.sent.${r.data.channel}` as MessageKey)); },
    onError: (e) => toast(firstError(e, t('common.error')), 'error'),
  });
  const status = useMutation({
    mutationFn: ({ id, active }: { id: string; active: boolean }) => api(`/learners/${id}/status`, { method: 'POST', body: { active } }),
    onSuccess: done,
    onError: (e) => toast(firstError(e, t('common.error')), 'error'),
  });
  const revealId = useMutation({
    mutationFn: (id: string) => api<{ data: { id_number: string } }>(`/learners/${id}/reveal-id`, { method: 'POST' }),
    onSuccess: (r, id) => setShownId({ id, number: r.data.id_number }),
    onError: (e) => toast(firstError(e, t('common.error')), 'error'),
  });

  if (!learner) return null;
  const myLink = link?.id === learner.id ? link : null;
  const number = shownId?.id === learner.id ? shownId.number : null;
  const wa = myLink ? `https://wa.me/${(learner.phone ?? '').replace(/\D/g, '')}?text=${encodeURIComponent(t('lrn.waText', { url: myLink.url }))}` : '';
  const row = (label: string, value: ReactNode) => <div className="flex justify-between gap-4 py-2.5"><dt className="text-muted">{label}</dt><dd className="text-right font-medium text-ink">{value ?? '—'}</dd></div>;

  return (
    <Drawer open onClose={onClose} title={learner.name} subtitle={<Badge tone={TONE[learner.status]}>{t(`lrn.status.${learner.status}` as MessageKey)}</Badge>}>
      <dl className="divide-y divide-line text-[14px]">
        {row(t(learner.id_type === 'sa_id' ? 'lrn.f.saId' : 'lrn.f.passport'), (
          <span className="inline-flex items-center gap-2 font-mono">{number ?? learner.id_masked}
            {reveal && !number && <button onClick={() => revealId.mutate(learner.id)} className="inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 font-sans text-[12px] font-semibold text-brand hover:bg-surface-2"><Eye className="size-3.5" />{t('lrn.reveal')}</button>}
          </span>
        ))}
        {row(t('lrn.f.dob'), learner.date_of_birth)}
        {row(t('lrn.f.gender'), learner.gender && t(`lrn.gender.${learner.gender}` as MessageKey))}
        {row(t('lrn.f.phone'), localPhone(learner.phone))}
        {row(t('lrn.f.email'), learner.email)}
        {row(t('lrn.f.lang'), learner.home_language && t(`lrn.lang.${learner.home_language}` as MessageKey))}
        {row(t('lrn.f.joined'), learner.joined_at && new Date(learner.joined_at).toLocaleDateString(locale, { day: 'numeric', month: 'short', year: 'numeric' }))}
      </dl>
      {number && <p className="mt-2 text-[12px] text-muted">{t('lrn.revealNote')}</p>}
      <QctoSummary learner={learner} manage={manage} />

      {manage && (
        <div className="mt-6 space-y-3">
          {learner.status === 'invited' && (
            <Button className="w-full" icon={<Send className="size-4" />} loading={invite.isPending} onClick={() => invite.mutate(learner.id)}>{t('lrn.resend')}</Button>
          )}
          {myLink && (
            <div className="rounded-xl border border-line bg-surface-2 p-3">
              <p className="mb-2 break-all font-mono text-[12px] text-muted">{myLink.url}</p>
              <div className="flex gap-2">
                <Button variant="secondary" size="sm" icon={<Copy className="size-4" />} onClick={() => void navigator.clipboard?.writeText(myLink.url).then(() => toast(t('lrn.copied')))}>{t('lrn.copy')}</Button>
                {learner.phone && <a href={wa} target="_blank" rel="noreferrer" className="inline-flex h-9 items-center gap-1.5 rounded-xl border border-line bg-surface px-3 text-[13px] font-semibold text-ink hover:bg-surface-2"><MessageCircle className="size-4" />WhatsApp</a>}
              </div>
            </div>
          )}
          {learner.status === 'inactive'
            ? <Button variant="secondary" className="w-full" loading={status.isPending} onClick={() => status.mutate({ id: learner.id, active: true })}>{t('lrn.activate')}</Button>
            : <Button variant="secondary" className="w-full" icon={<UserMinus className="size-4" />} loading={status.isPending} onClick={() => status.mutate({ id: learner.id, active: false })}>{t('lrn.deactivate')}</Button>}
        </div>
      )}
    </Drawer>
  );
}

function ImportLearners({ open, onClose }: { open: boolean; onClose: () => void }) {
  const { t } = useI18n();
  const toast = useToast();
  const qc = useQueryClient();
  const [rows, setRows] = useState<ImportRow[] | null>(null);
  const [fileName, setFileName] = useState('');
  const [report, setReport] = useState<ImportReport | null>(null);
  const [invite, setInvite] = useState(true);
  const close = () => { setRows(null); setReport(null); setFileName(''); onClose(); };

  const run = useMutation({
    mutationFn: ({ dry, list }: { dry: boolean; list: ImportRow[] }) => api<{ data: ImportReport }>('/learners/import', { method: 'POST', body: { rows: list, dry_run: dry, invite } }),
    onSuccess: (r, { dry }) => {
      if (dry) { setReport(r.data); return; }
      void qc.invalidateQueries({ queryKey: ['learners'] });
      toast(t('lrn.imported', { n: r.data.created }));
      close();
    },
    onError: (e) => toast(firstError(e, t('common.error')), 'error'),
  });

  const pick = async (file: File | undefined) => {
    if (!file) return;
    setReport(null);
    try {
      const parsed = await readLearnerFile(file);
      if (parsed.missing.length) { toast(t('lrn.missingCols', { cols: parsed.missing.map((m) => t(`lrn.col.${m}` as MessageKey)).join(', ') }), 'error'); return; }
      if (parsed.rows.length === 0) { toast(t('lrn.emptyFile'), 'error'); return; }
      setFileName(file.name);
      setRows(parsed.rows);
      run.mutate({ dry: true, list: parsed.rows });
    } catch {
      toast(t('lrn.badFile'), 'error');
    }
  };
  const template = `data:text/csv;charset=utf-8,${encodeURIComponent(TEMPLATE_CSV)}`;
  const failed = report?.rows.filter((r) => Object.keys(r.errors).length) ?? [];

  return (
    <Drawer open={open} onClose={close} width="max-w-2xl" title={t('lrn.import')} subtitle={t('lrn.importLead')}
      footer={report && (
        <div className="flex items-center justify-between gap-3">
          <label className="flex items-center gap-3 text-[14px] font-medium text-ink"><Switch checked={invite} onChange={setInvite} label={t('lrn.inviteNow')} />{t('lrn.inviteNow')}</label>
          <Button disabled={report.ready === 0} loading={run.isPending} onClick={() => rows && run.mutate({ dry: false, list: rows })}>{t('lrn.importN', { n: report.ready })}</Button>
        </div>
      )}>
      <label className="flex cursor-pointer flex-col items-center justify-center gap-2 rounded-2xl border-2 border-dashed border-line px-6 py-10 text-center hover:border-brand"
        onDragOver={(e) => e.preventDefault()} onDrop={(e) => { e.preventDefault(); void pick(e.dataTransfer.files[0]); }}>
        <FileUp className="size-7 text-muted" />
        <span className="text-[15px] font-semibold text-ink">{fileName || t('lrn.drop')}</span>
        <span className="text-[13px] text-muted">{t('lrn.dropHint')}</span>
        <input type="file" accept=".csv,.xlsx,text/csv" className="sr-only" aria-label={t('lrn.drop')} onChange={(e) => void pick(e.target.files?.[0])} />
      </label>
      <a href={template} download="learners-template.csv" className="mt-3 inline-block text-[13px] font-semibold text-brand hover:underline">{t('lrn.template')}</a>

      {run.isPending && !report && <Skeleton className="mt-6 h-24 rounded-xl" />}
      {report && rows && (
        <div className="mt-6">
          <div className="grid grid-cols-2 gap-3">
            <div className="rounded-xl bg-[#E6F4EC] px-4 py-3 dark:bg-[#1E7A46]/20"><p className="text-2xl font-bold text-[#1E7A46] dark:text-[#6FD49A]">{report.ready}</p><p className="text-[13px] text-ink">{t('lrn.ready')}</p></div>
            <div className={cx('rounded-xl px-4 py-3', report.failed ? 'bg-[#FDECEC] dark:bg-[#B42328]/20' : 'bg-surface-2')}><p className={cx('text-2xl font-bold', report.failed ? 'text-[#B42328] dark:text-[#FF9A9E]' : 'text-muted')}>{report.failed}</p><p className="text-[13px] text-ink">{t('lrn.toFix')}</p></div>
          </div>
          {failed.length > 0 && (
            <div className="mt-4 max-h-80 overflow-y-auto rounded-xl border border-line">
              <table className="w-full text-left text-[13px]">
                <thead className="sticky top-0 bg-surface-2 text-muted"><tr><th className="px-3 py-2">{t('lrn.row')}</th><th className="px-3 py-2">{t('lrn.col.name')}</th><th className="px-3 py-2">{t('lrn.problem')}</th></tr></thead>
                <tbody className="divide-y divide-line">
                  {failed.map((r) => <tr key={r.row}><td className="px-3 py-2 text-muted">{r.row + 1}</td><td className="px-3 py-2 font-medium">{r.name || '—'}</td><td className="px-3 py-2 text-[#B42328] dark:text-[#FF9A9E]">{Object.values(r.errors).join(' ')}</td></tr>)}
                </tbody>
              </table>
            </div>
          )}
          {failed.length > 0 && <p className="mt-2 text-[12px] text-muted">{t('lrn.fixHint')}</p>}
        </div>
      )}
    </Drawer>
  );
}

function QctoSummary({ learner, manage }: { learner: LearnerItem; manage: boolean }) {
  const { t } = useI18n();
  const [editing, setEditing] = useState(false);
  const details = useQuery({ queryKey: ['learner-qcto', learner.id], queryFn: () => api<{ data: QctoDetails }>(`/learners/${learner.id}/qcto`) });
  const missing = details.data?.data.missing;
  return (
    <div className="mt-5 rounded-xl border border-line p-4">
      <div className="flex items-center justify-between gap-3">
        <h3 className="text-[14px] font-bold text-ink">{t('qd.section')}</h3>
        {missing && <Badge tone={missing.length ? 'warning' : 'success'}>{missing.length ? t('qd.nMissing', { n: missing.length }) : t('qd.complete')}</Badge>}
      </div>
      {missing && missing.length > 0 && <p className="mt-2 text-[13px] text-muted">{missing.map((m) => t(`qd.m.${m}` as MessageKey)).join(' · ')}</p>}
      {manage && <Button className="mt-3" size="sm" variant="secondary" onClick={() => setEditing(true)}>{t('qd.edit')}</Button>}
      {editing && <QctoDetailsDrawer learner={learner} onClose={() => setEditing(false)} />}
    </div>
  );
}
