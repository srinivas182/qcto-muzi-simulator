import { useState, type ReactNode } from 'react';
import { Link, useParams } from 'react-router';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, CheckCircle2, CircleDashed, ClipboardCheck, Info, MinusCircle, Plus, Sparkles, X } from 'lucide-react';
import type { DutyAreaItem, EvidenceType, ProgrammeVersionContent, ProviderCheck, ProviderLayer } from '@simuwork/core';
import type { MessageKey } from '@simuwork/core/locales/en';
import { useSignedIn } from '../../app/access';
import { NotFound } from '../../app/states';
import { Badge, Button, Card, cx, Editable, inputClass, Skeleton, useToast } from '../../components/ui';
import { api, ApiError } from '../../lib/api';
import { useI18n } from '../../lib/i18n';
import { can } from '../../lib/session';

const EVIDENCE: EvidenceType[] = ['document', 'record', 'photo', 'observation', 'sign_off', 'feedback'];
type Tab = 'outcomes' | 'checklist' | 'about';
type Data = { data: ProgrammeVersionContent; provider: ProviderLayer };
type Run = (method: 'POST' | 'PATCH' | 'PUT' | 'DELETE', path: string, body?: unknown, done?: string) => Promise<boolean>;

/** A published programme as a provider sees it, with its own checklist answers and additions. */
export default function ProgrammeView() {
  const { code = '' } = useParams();
  const session = useSignedIn();
  const { t } = useI18n();
  const toast = useToast();
  const qc = useQueryClient();
  const [tab, setTab] = useState<Tab>('outcomes');
  const key = ['programme', code] as const;

  const query = useQuery({ queryKey: key, queryFn: () => api<Data>(`/programmes/${code}`), retry: false });
  const mutate = useMutation({
    mutationFn: ({ method, path, body }: { method: 'POST' | 'PATCH' | 'PUT' | 'DELETE'; path: string; body?: unknown }) =>
      api<{ provider: ProviderLayer }>(`/programmes/${code}${path}`, { method, body }),
    onSuccess: (res) => qc.setQueryData<Data>(key, (old) => (old ? { ...old, provider: res.provider } : old)),
  });
  const run: Run = (method, path, body, done) =>
    mutate.mutateAsync({ method, path, body }).then(
      () => { if (done) toast(done); return true; },
      (e: unknown) => { toast(e instanceof ApiError ? Object.values(e.errors)[0]?.[0] ?? e.message : t('common.error'), 'error'); return false; },
    );

  if (query.isError) return <NotFound />;
  if (!query.data) return <div className="space-y-4"><Skeleton className="h-10 w-80" /><Skeleton className="h-24" /><Skeleton className="h-96" /></div>;

  const { data: content, provider } = query.data;
  const manage = can(session, 'catalogue.manage');
  const editable = manage && provider.additions.enabled;
  const total = provider.checks.length;

  return (
    <div className="sw-in">
      <Link to="/admin/programmes" className="mb-3 inline-flex items-center gap-1.5 text-[13px] font-semibold text-muted hover:text-ink"><ArrowLeft className="size-4" />{t('cat.back')}</Link>
      <div className="mb-6 flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        <div className="min-w-0">
          <div className="flex flex-wrap items-center gap-2">
            <span className="rounded-lg bg-brand-soft px-2 py-1 font-mono text-[12px] font-bold text-brand">{content.programme.prefix}</span>
            <Badge tone="success">{t('prog.version', { n: content.version })}</Badge>
          </div>
          <h1 className="mt-2 text-[26px] font-bold tracking-tight text-ink">{content.programme.title}</h1>
          <p className="mt-1 max-w-3xl text-[15px] text-muted">{content.summary}</p>
        </div>
        {total > 0 && (
          <button onClick={() => setTab('checklist')} className={cx('flex shrink-0 items-center gap-2 rounded-xl border px-3.5 py-2.5 text-left text-[13px] font-semibold transition hover:border-brand/40', provider.checks_done === total ? 'border-[#0B7A5C]/30 bg-[#E6F6F1] text-[#0B7A5C] dark:bg-[#0B7A5C]/20 dark:text-[#5FD3B1]' : 'border-[#C98A00]/30 bg-[#FDF4E1] text-[#8A5A00] dark:bg-[#8A5A00]/25 dark:text-[#F5C46B]')}>
            <ClipboardCheck className="size-4" />
            {t('cat.checksProgress', { done: provider.checks_done, total })}
          </button>
        )}
      </div>

      <div role="tablist" className="mb-5 inline-flex rounded-xl bg-surface-2 p-1">
        {(['outcomes', 'checklist', 'about'] as Tab[]).map((k) => (
          <button key={k} role="tab" aria-selected={tab === k} onClick={() => setTab(k)} className={cx('h-9 rounded-lg px-4 text-[13px] font-semibold transition', tab === k ? 'bg-surface text-ink shadow-sm' : 'text-muted hover:text-ink')}>
            {t(`cat.tab.${k}` as MessageKey)}
          </button>
        ))}
      </div>

      {tab === 'outcomes' && (
        <div className="space-y-5">
          {manage && !provider.additions.enabled && (
            <p className="flex items-start gap-2 rounded-xl bg-surface-2 px-4 py-3 text-[14px] text-muted"><Info className="mt-0.5 size-4 shrink-0" />{t('cat.additionsOff')}</p>
          )}
          {editable && (
            <p className="flex items-start gap-2 rounded-xl bg-brand-soft px-4 py-3 text-[14px] font-medium text-brand"><Sparkles className="mt-0.5 size-4 shrink-0" />{t('cat.additionsOn')}</p>
          )}
          {content.duty_areas.map((area) => <AreaCard key={area.number} area={area} provider={provider} editable={editable} run={run} />)}
        </div>
      )}
      {tab === 'checklist' && <Checklist checks={provider.checks} manage={manage} run={run} />}
      {tab === 'about' && <About content={content} />}
    </div>
  );
}

function AreaCard({ area, provider, editable, run }: { area: DutyAreaItem; provider: ProviderLayer; editable: boolean; run: Run }) {
  const { t } = useI18n();
  const [outcome, setOutcome] = useState('');
  const [evidence, setEvidence] = useState('');
  const [type, setType] = useState<EvidenceType>('document');
  const ownOutcomes = provider.additions.outcomes.filter((o) => o.duty_area === area.number);
  const ownEvidence = provider.additions.evidence.filter((e) => e.duty_area === area.number);

  return (
    <Card className="overflow-hidden p-0">
      <div className="flex items-start gap-3 border-b border-line bg-surface-2/50 px-5 py-4">
        <span className="flex size-8 shrink-0 items-center justify-center rounded-full bg-brand text-[13px] font-bold text-white">{area.number}</span>
        <div className="min-w-0 flex-1">
          <div className="text-[11px] font-bold uppercase tracking-[0.12em] text-muted">{t('prog.dutyArea', { n: area.number })}</div>
          <h2 className="text-[17px] font-bold text-ink">{area.title}</h2>
        </div>
      </div>
      <div className="grid lg:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)]">
        <section className="px-5 py-4">
          <h3 className="mb-2 text-[12px] font-bold uppercase tracking-[0.1em] text-muted">{t('prog.outcomes')}</h3>
          <ul className="space-y-3">
            {area.outcomes.map((o) => (
              <li key={o.code} className="flex gap-3">
                <Code>{o.code}</Code>
                <div className="min-w-0 flex-1">
                  <p className="text-[14px] text-ink">{o.statement}</p>
                  {o.plain_words && <p className="text-[13px] text-brand">{o.plain_words}</p>}
                </div>
              </li>
            ))}
            {ownOutcomes.map((o) => (
              <li key={o.id} className="flex gap-3 rounded-xl border border-dashed border-brand/40 bg-brand-soft/40 p-2.5">
                <Code own>{o.code}</Code>
                <div className="min-w-0 flex-1">
                  <Own />
                  <Editable value={o.statement} editable={editable} multiline minLength={10} label={o.code} className="text-[14px] text-ink" onSave={(v) => run('PATCH', `/additions/outcomes/${o.id}`, { statement: v })} />
                  {(editable || o.plain_words) && <Editable value={o.plain_words ?? ''} editable={editable} placeholder={t('prog.plainWords')} label={t('prog.plainWords')} className="text-[13px] text-brand" onSave={(v) => run('PATCH', `/additions/outcomes/${o.id}`, { plain_words: v || null })} />}
                </div>
                {editable && <RemoveButton label={`${t('prog.remove')} ${o.code}`} onClick={() => run('DELETE', `/additions/outcomes/${o.id}`, undefined, t('cat.removed'))} />}
              </li>
            ))}
          </ul>
          {editable && (
            <div className="mt-3 flex gap-2">
              <input value={outcome} onChange={(e) => setOutcome(e.target.value)} placeholder={t('cat.newOutcome')} aria-label={t('cat.addOutcome', { n: area.number })} className={cx(inputClass, 'h-9 flex-1 text-[13px]')} />
              <Button size="sm" variant="secondary" icon={<Plus className="size-4" />} disabled={outcome.trim().length < 10} onClick={() => run('POST', '/additions/outcomes', { duty_area: area.number, statement: outcome.trim() }, t('cat.added')).then((ok) => ok && setOutcome(''))}>{t('prog.addOutcome')}</Button>
            </div>
          )}
        </section>
        <section className="border-t border-line px-5 py-4 lg:border-l lg:border-t-0">
          <h3 className="mb-2 text-[12px] font-bold uppercase tracking-[0.1em] text-muted">{t('prog.evidence')}</h3>
          <ul className="space-y-2.5">
            {area.evidence.map((e) => (
              <li key={e.id} className="rounded-xl border border-line p-2.5">
                <div className="flex items-center gap-2">
                  <Badge tone="brand">{t(`prog.ev.${e.type}` as MessageKey)}</Badge>
                  {e.min_count > 1 && <span className="text-[11px] text-muted">{t('prog.minCount', { n: e.min_count })}</span>}
                </div>
                <p className="mt-1 text-[13px] text-ink">{e.description}</p>
              </li>
            ))}
            {ownEvidence.map((e) => (
              <li key={e.id} className="rounded-xl border border-dashed border-brand/40 bg-brand-soft/40 p-2.5">
                <div className="flex items-center gap-2">
                  <Badge tone="brand">{t(`prog.ev.${e.type}` as MessageKey)}</Badge>
                  <Own />
                  <span className="flex-1" />
                  {editable && <RemoveButton label={t('prog.remove')} onClick={() => run('DELETE', `/additions/evidence/${e.id}`, undefined, t('cat.removed'))} />}
                </div>
                <Editable value={e.description} editable={editable} multiline minLength={5} label={t('prog.evidence')} className="mt-1 text-[13px] text-ink" onSave={(v) => run('PATCH', `/additions/evidence/${e.id}`, { description: v })} />
              </li>
            ))}
          </ul>
          {editable && (
            <div className="mt-3 space-y-2">
              <input value={evidence} onChange={(e) => setEvidence(e.target.value)} placeholder={t('prog.newEvidence')} aria-label={t('cat.addEvidence', { n: area.number })} className={cx(inputClass, 'h-9 text-[13px]')} />
              <div className="flex gap-2">
                <select value={type} onChange={(e) => setType(e.target.value as EvidenceType)} aria-label={t('prog.evidence')} className={cx(inputClass, 'h-9 flex-1 text-[13px]')}>
                  {EVIDENCE.map((x) => <option key={x} value={x}>{t(`prog.ev.${x}` as MessageKey)}</option>)}
                </select>
                <Button size="sm" variant="secondary" icon={<Plus className="size-4" />} disabled={evidence.trim().length < 5} onClick={() => run('POST', '/additions/evidence', { duty_area: area.number, description: evidence.trim(), type }, t('cat.added')).then((ok) => ok && setEvidence(''))}>{t('prog.addEvidence')}</Button>
              </div>
            </div>
          )}
        </section>
      </div>
    </Card>
  );
}

function Checklist({ checks, manage, run }: { checks: ProviderCheck[]; manage: boolean; run: Run }) {
  const { t } = useI18n();
  if (checks.length === 0) return <Card className="p-5 text-[14px] text-muted">{t('cat.checksNone')}</Card>;
  return (
    <div className="space-y-4">
      <p className="max-w-3xl text-[14px] text-muted">{t('cat.checksLead')}</p>
      {checks.map((c) => <CheckItem key={c.key} check={c} manage={manage} run={run} />)}
    </div>
  );
}

function CheckItem({ check, manage, run }: { check: ProviderCheck; manage: boolean; run: Run }) {
  const { t, locale } = useI18n();
  const [note, setNote] = useState(check.note ?? '');
  const [choice, setChoice] = useState<ProviderCheck['status']>(check.status);
  const dirty = choice !== check.status || (note.trim() || null) !== check.note;
  const needsNote = choice === 'not_applicable' && note.trim() === '';
  const Icon = check.status === 'confirmed' ? CheckCircle2 : check.status === 'not_applicable' ? MinusCircle : CircleDashed;
  const tone = check.status === 'confirmed' ? 'text-[#0B7A5C] dark:text-[#5FD3B1]' : check.status === 'not_applicable' ? 'text-muted' : 'text-[#C98A00]';

  return (
    <Card className="p-5">
      <div className="flex items-start gap-3">
        <Icon className={cx('mt-0.5 size-5 shrink-0', tone)} aria-hidden />
        <div className="min-w-0 flex-1">
          <p className="text-[15px] font-semibold text-ink">{check.text}</p>
          {check.status && check.answered_by && (
            <p className="mt-0.5 text-[12px] text-muted">
              {t(`cat.check.${check.status}` as MessageKey)} · {t('cat.answeredBy', { name: check.answered_by, date: new Date(check.answered_at ?? '').toLocaleDateString(locale, { day: 'numeric', month: 'short', year: 'numeric' }) })}
            </p>
          )}
          {!manage && check.note && <p className="mt-2 rounded-lg bg-surface-2 px-3 py-2 text-[14px] text-ink">{check.note}</p>}
          {manage && (
            <div className="mt-3 space-y-3">
              <div role="radiogroup" aria-label={check.text} className="flex flex-wrap gap-2">
                {(['confirmed', 'not_applicable'] as const).map((s) => (
                  <button key={s} role="radio" aria-checked={choice === s} onClick={() => setChoice(choice === s ? null : s)} className={cx('h-9 rounded-lg border px-3.5 text-[13px] font-semibold transition', choice === s ? 'border-brand bg-brand text-white' : 'border-line text-ink hover:border-brand/40')}>
                    {t(`cat.check.${s}` as MessageKey)}
                  </button>
                ))}
              </div>
              <div>
                <label htmlFor={`note-${check.key}`} className="mb-1 block text-[13px] font-semibold text-ink">{t(choice === 'not_applicable' ? 'cat.noteWhy' : 'cat.note')}</label>
                <textarea id={`note-${check.key}`} value={note} onChange={(e) => setNote(e.target.value)} rows={2} placeholder={t('cat.notePlaceholder')} className={cx(inputClass, 'h-auto py-2.5 text-[14px]')} />
              </div>
              <div className="flex gap-2">
                <Button size="sm" disabled={!dirty || needsNote} onClick={() => void run('PUT', `/checks/${check.key}`, { status: choice, note: note.trim() || null }, t('cat.checkSaved'))}>{t('common.save')}</Button>
                {dirty && <Button size="sm" variant="ghost" onClick={() => { setChoice(check.status); setNote(check.note ?? ''); }}>{t('common.cancel')}</Button>}
              </div>
            </div>
          )}
        </div>
      </div>
    </Card>
  );
}

function About({ content }: { content: ProgrammeVersionContent }) {
  const { t } = useI18n();
  return (
    <div className="grid gap-5 lg:grid-cols-2">
      <Card className="p-5 lg:col-span-2">
        <Label>{t('prog.purpose')}</Label>
        <p className="text-[15px] leading-relaxed text-ink">{content.purpose}</p>
      </Card>
      <Card className="p-5">
        <Label>{t('prog.occupations')}</Label>
        <ul className="flex flex-wrap gap-2">{content.occupations.map((o) => <li key={o} className="rounded-full bg-surface-2 px-3 py-1 text-[14px] text-ink">{o}</li>)}</ul>
      </Card>
      <List title={t('prog.tools')} items={content.tools} />
      <List title={t('prog.requirements')} items={content.workplace_requirements} />
      <Card className="p-5">
        <Label>{t('prog.qualifications')}</Label>
        <ul className="divide-y divide-line">
          {content.qualifications.map((q, i) => (
            <li key={i} className="py-2.5 first:pt-0">
              <Badge tone={q.kind === 'occupational' ? 'success' : q.kind === 'legacy' ? 'warning' : 'neutral'}>{t(`prog.q.${q.kind}` as MessageKey)}</Badge>
              {q.title && <div className="mt-1 text-[14px] font-semibold text-ink">{q.title}</div>}
              <div className="text-[13px] text-muted">{[q.saqa_id && `SAQA ${q.saqa_id}`, q.nqf_level && t('prog.nqf', { n: q.nqf_level }), q.credits && t('prog.credits', { n: q.credits })].filter(Boolean).join(' · ')}</div>
              {(!q.title || q.kind === 'note') && <p className="mt-1 text-[13px] text-muted">{q.source_text}</p>}
            </li>
          ))}
        </ul>
      </Card>
    </div>
  );
}

function List({ title, items }: { title: string; items: string[] }) {
  return (
    <Card className="p-5">
      <Label>{title}</Label>
      <ul className="space-y-1.5">{items.map((item) => <li key={item} className="flex items-start gap-2 text-[14px] text-ink"><span aria-hidden className="mt-2 size-1.5 shrink-0 rounded-full bg-brand" />{item}</li>)}</ul>
    </Card>
  );
}

function Label({ children }: { children: ReactNode }) {
  return <h3 className="mb-1.5 text-[12px] font-bold uppercase tracking-[0.1em] text-muted">{children}</h3>;
}

function Code({ children, own }: { children: ReactNode; own?: boolean }) {
  return <span className={cx('mt-1 h-fit shrink-0 rounded-md px-1.5 py-0.5 font-mono text-[11px] font-bold', own ? 'bg-brand text-white' : 'bg-surface-2 text-muted')}>{children}</span>;
}

function Own() {
  const { t } = useI18n();
  return <span className="text-[11px] font-bold uppercase tracking-[0.08em] text-brand">{t('cat.yourAddition')}</span>;
}

function RemoveButton({ label, onClick }: { label: string; onClick: () => void }) {
  return (
    <button type="button" aria-label={label} title={label} onClick={onClick} className="h-fit rounded-lg p-1.5 text-muted hover:bg-[#FDECEC] hover:text-[#B42328] dark:hover:bg-[#B42328]/20">
      <X className="size-4" />
    </button>
  );
}
