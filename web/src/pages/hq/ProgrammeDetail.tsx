import { useState, type ReactNode } from 'react';
import { useParams } from 'react-router';
import { useQuery } from '@tanstack/react-query';
import { CheckCircle2, CircleAlert, FileText, GitCompareArrows, Lock, Plus, Send, Trash2, Undo2, Upload, X } from 'lucide-react';
import type { DutyAreaItem, EvidenceType, ProgrammeStudioData, ProgrammeVersionContent, VersionChange } from '@simuwork/core';
import { useSignedIn } from '../../app/access';
import { NotFound } from '../../app/states';
import { Badge, Button, Card, cx, Drawer, Editable, inputClass, Skeleton, useToast } from '../../components/ui';
import { api, ApiError } from '../../lib/api';
import { useI18n } from '../../lib/i18n';
import { can } from '../../lib/session';
import { relativeTime } from '../../lib/time';
import type { MessageKey } from '@simuwork/core/locales/en';
import { CoverageTab } from './CoverageTab';
import { programmeKey, useStudioMutation } from './programmeApi';

const EVIDENCE: EvidenceType[] = ['document', 'record', 'photo', 'observation', 'sign_off', 'feedback'];
type Tab = 'outcomes' | 'coverage' | 'overview' | 'history';

export default function ProgrammeDetail() {
  const { code = '' } = useParams();
  const session = useSignedIn();
  const { t } = useI18n();
  const toast = useToast();
  const studio = useStudioMutation(code);
  const [tab, setTab] = useState<Tab>('outcomes');
  const [viewPublished, setViewPublished] = useState(false);
  const [dialog, setDialog] = useState<'submit' | 'approve' | 'sendBack' | null>(null);

  const data = useQuery({ queryKey: programmeKey(code), queryFn: async () => (await api<{ data: ProgrammeStudioData }>(`/platform/programmes/${code}`)).data, retry: false });
  const publishedNumber = data.data?.versions.find((v) => v.status === 'published')?.version;
  const published = useQuery({
    queryKey: ['hq-programme-version', code, publishedNumber],
    queryFn: async () => (await api<{ data: ProgrammeVersionContent }>(`/platform/programmes/${code}/versions/${publishedNumber}`)).data,
    enabled: viewPublished && !!publishedNumber,
  });

  if (data.isError) return <NotFound />;
  if (data.isPending || !data.data?.current) return <div className="space-y-4"><Skeleton className="h-10 w-80" /><Skeleton className="h-24" /><Skeleton className="h-96" /></div>;

  const current = data.data.current;
  const isOpen = current.status === 'draft' || current.status === 'in_review';
  const shown = viewPublished && published.data ? published.data : current;
  const author = can(session, 'programmes.manage') || can(session, 'content.author');
  const editable = !viewPublished && current.status === 'draft' && author;
  const approved = current.status === 'in_review' && !!current.review?.reviewed_at;

  /** Calls the Studio API; shows a toast either way and resolves to true on success (never rejects). */
  const run: Run = (method, path, body, done) =>
    studio.mutateAsync({ method, path, body }).then(
      () => {
        if (done) toast(done);
        return true;
      },
      (e: unknown) => {
        toast(e instanceof ApiError ? Object.values(e.errors)[0]?.[0] ?? e.message : t('common.error'), 'error');
        return false;
      },
    );

  const actions: ReactNode[] = [];
  if (!isOpen && author) actions.push(<Button key="start" icon={<FileText className="size-4" />} loading={studio.isPending} onClick={() => run('POST', '/draft', undefined, t('prog.started', { n: (publishedNumber ?? 0) + 1 }))}>{t('prog.action.start')}</Button>);
  if (current.status === 'draft' && author) {
    actions.push(<Button key="discard" variant="ghost" icon={<Trash2 className="size-4" />} onClick={() => run('DELETE', '/draft', undefined, t('prog.discarded'))}>{t('prog.action.discard')}</Button>);
    actions.push(<Button key="submit" icon={<Send className="size-4" />} onClick={() => setDialog('submit')}>{t('prog.action.submit')}</Button>);
  }
  if (current.status === 'in_review' && can(session, 'content.review')) {
    actions.push(<Button key="back" variant="secondary" icon={<Undo2 className="size-4" />} onClick={() => setDialog('sendBack')}>{t('prog.action.sendBack')}</Button>);
    if (!approved) actions.push(<Button key="approve" icon={<CheckCircle2 className="size-4" />} onClick={() => setDialog('approve')}>{t('prog.action.approve')}</Button>);
  }
  if (approved && can(session, 'content.publish')) {
    actions.push(<Button key="publish" icon={<Upload className="size-4" />} loading={studio.isPending} onClick={() => run('POST', '/draft/publish', undefined, t('prog.published', { n: current.version }))}>{t('prog.action.publish', { n: current.version })}</Button>);
  }

  return (
    <div className="sw-in">
      <div className="mb-6 flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        <div className="min-w-0">
          <div className="flex flex-wrap items-center gap-2">
            <span className="rounded-lg bg-brand-soft px-2 py-1 font-mono text-[12px] font-bold text-brand">{current.programme.prefix}</span>
            {publishedNumber && <Badge tone="success">{t('prog.status.published')} v{publishedNumber}</Badge>}
            {isOpen && <Badge tone={current.status === 'in_review' ? 'warning' : 'brand'}>{t(current.status === 'in_review' ? 'prog.status.in_review' : 'prog.status.draft', { n: current.version })}</Badge>}
          </div>
          <h1 className="mt-2 text-[26px] font-bold tracking-tight text-ink">{current.programme.title}</h1>
          <p className="mt-1 max-w-3xl text-[15px] text-muted">{shown.summary}</p>
        </div>
        {actions.length > 0 && <div className="flex shrink-0 flex-wrap gap-2">{actions}</div>}
      </div>

      <Banner current={current} viewingPublished={viewPublished || !isOpen} />

      <div className="mb-5 flex flex-wrap items-center justify-between gap-3">
        <div role="tablist" className="inline-flex rounded-xl bg-surface-2 p-1">
          {(['outcomes', 'coverage', 'overview', 'history'] as Tab[]).map((k) => (
            <button key={k} role="tab" aria-selected={tab === k} onClick={() => setTab(k)} className={cx('h-9 rounded-lg px-4 text-[13px] font-semibold transition', tab === k ? 'bg-surface text-ink shadow-sm' : 'text-muted hover:text-ink')}>
              {t(`prog.tab.${k}` as MessageKey)}
            </button>
          ))}
        </div>
        {isOpen && publishedNumber && tab !== 'history' && tab !== 'coverage' && (
          <div className="flex items-center gap-2 text-[13px] text-muted">
            {t('prog.viewing')}
            <div className="inline-flex rounded-lg border border-line p-0.5">
              {[false, true].map((pub) => (
                <button key={String(pub)} onClick={() => setViewPublished(pub)} aria-pressed={viewPublished === pub} className={cx('rounded-md px-2.5 py-1 text-[12px] font-semibold', viewPublished === pub ? 'bg-brand text-white' : 'text-muted')}>
                  {pub ? t('prog.view.published', { n: publishedNumber }) : t('prog.view.draft', { n: current.version })}
                </button>
              ))}
            </div>
          </div>
        )}
      </div>

      {tab === 'outcomes' && <OutcomesTab content={shown} editable={editable} run={run} />}
      {tab === 'coverage' && <CoverageTab code={code} content={current} editable={author} />}
      {tab === 'overview' && <OverviewTab content={shown} editable={editable} run={run} />}
      {tab === 'history' && <HistoryTab code={code} data={data.data} />}

      <ReviewDialog
        mode={dialog}
        onClose={() => setDialog(null)}
        onSubmit={async (text) => {
          const ok =
            dialog === 'submit' ? await run('POST', '/draft/submit', { change_notes: text }, t('prog.submitted'))
            : dialog === 'approve' ? await run('POST', '/draft/approve', { comment: text || null }, t('prog.approved'))
            : await run('POST', '/draft/request-changes', { comment: text }, t('prog.sentBack'));
          if (ok) setDialog(null);
          return ok;
        }}
        initial={dialog === 'submit' ? current.change_notes ?? '' : ''}
      />
    </div>
  );
}

type Run = (method: 'POST' | 'PATCH' | 'DELETE', path: string, body?: unknown, done?: string) => Promise<boolean>;

function Banner({ current, viewingPublished }: { current: ProgrammeVersionContent; viewingPublished: boolean }) {
  const { t, locale } = useI18n();
  const r = current.review;
  let tone = 'bg-surface-2 text-muted';
  let icon = <Lock className="size-4 shrink-0" />;
  let text: string | null = null;

  if (current.status === 'in_review' && r?.reviewed_at) {
    tone = 'bg-[#E6F6F1] text-[#0B7A5C] dark:bg-[#0B7A5C]/20 dark:text-[#5FD3B1]';
    icon = <CheckCircle2 className="size-4 shrink-0" />;
    text = t('prog.banner.approved', { name: r.reviewed_by ?? '' });
  } else if (current.status === 'in_review') {
    tone = 'bg-[#FDF4E1] text-[#8A5A00] dark:bg-[#8A5A00]/25 dark:text-[#F5C46B]';
    text = t('prog.banner.inReview', { name: r?.submitted_by ?? '', when: r?.submitted_at ? relativeTime(r.submitted_at, locale) : '' });
  } else if (current.status === 'draft' && r?.comment) {
    tone = 'bg-[#FDECEC] text-[#B42328] dark:bg-[#B42328]/20 dark:text-[#FF9A9E]';
    icon = <CircleAlert className="size-4 shrink-0" />;
    text = t('prog.banner.sentBack', { name: r.reviewed_by ?? '', comment: r.comment });
  } else if (viewingPublished) {
    text = t('prog.banner.readOnly');
  }
  if (!text) return null;
  return <div role="status" className={cx('mb-5 flex items-start gap-2.5 rounded-xl px-4 py-3 text-[14px] font-medium', tone)}>{icon}<span>{text}</span></div>;
}

function OutcomesTab({ content, editable, run }: { content: ProgrammeVersionContent; editable: boolean; run: Run }) {
  const { t } = useI18n();
  const [newArea, setNewArea] = useState('');
  return (
    <div className="space-y-5">
      {content.duty_areas.map((area) => <DutyAreaCard key={area.number} area={area} editable={editable} run={run} />)}
      {editable && (
        <Card className="flex flex-col gap-2 border-dashed p-4 sm:flex-row">
          <input value={newArea} onChange={(e) => setNewArea(e.target.value)} placeholder={t('prog.newDutyArea')} aria-label={t('prog.newDutyArea')} className={cx(inputClass, 'h-10 flex-1 text-[14px]')} />
          <Button icon={<Plus className="size-4" />} disabled={newArea.trim().length < 3} onClick={() => run('POST', '/draft/duty-areas', { title: newArea.trim() }).then((ok) => ok && setNewArea(''))}>{t('prog.addDutyArea')}</Button>
        </Card>
      )}
    </div>
  );
}

function DutyAreaCard({ area, editable, run }: { area: DutyAreaItem; editable: boolean; run: Run }) {
  const { t } = useI18n();
  const [outcome, setOutcome] = useState('');
  const [evidence, setEvidence] = useState('');
  const [type, setType] = useState<EvidenceType>('document');

  return (
    <Card className="overflow-hidden p-0">
      <div className="flex items-start gap-3 border-b border-line bg-surface-2/50 px-5 py-4">
        <span className="flex size-8 shrink-0 items-center justify-center rounded-full bg-brand text-[13px] font-bold text-white">{area.number}</span>
        <div className="min-w-0 flex-1">
          <div className="text-[11px] font-bold uppercase tracking-[0.12em] text-muted">{t('prog.dutyArea', { n: area.number })}</div>
          <Editable value={area.title} editable={editable} label={t('prog.dutyArea', { n: area.number })} minLength={3} className="text-[17px] font-bold text-ink" onSave={(v) => run('PATCH', `/draft/duty-areas/${area.number}`, { title: v })} />
        </div>
        {editable && <IconButton label={t('prog.remove')} onClick={() => run('DELETE', `/draft/duty-areas/${area.number}`)} />}
      </div>

      <div className="grid gap-0 lg:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)]">
        <section className="px-5 py-4">
          <h3 className="mb-2 text-[12px] font-bold uppercase tracking-[0.1em] text-muted">{t('prog.outcomes')}</h3>
          <ul className="space-y-3">
            {area.outcomes.map((o) => (
              <li key={o.code} className="flex gap-3">
                <span className="mt-1 h-fit shrink-0 rounded-md bg-surface-2 px-1.5 py-0.5 font-mono text-[11px] font-bold text-muted">{o.code}</span>
                <div className="min-w-0 flex-1">
                  <Editable value={o.statement} editable={editable} multiline minLength={10} label={o.code} className="text-[14px] text-ink" onSave={(v) => run('PATCH', `/draft/outcomes/${o.code}`, { statement: v })} />
                  {(editable || o.plain_words) && (
                    <Editable value={o.plain_words ?? ''} editable={editable} placeholder={t('prog.plainWords')} label={t('prog.plainWords')} className="text-[13px] text-brand" onSave={(v) => run('PATCH', `/draft/outcomes/${o.code}`, { plain_words: v || null })} />
                  )}
                </div>
                {editable && <IconButton label={`${t('prog.remove')} ${o.code}`} onClick={() => run('DELETE', `/draft/outcomes/${o.code}`)} />}
              </li>
            ))}
          </ul>
          {editable && (
            <div className="mt-3 flex gap-2">
              <input value={outcome} onChange={(e) => setOutcome(e.target.value)} placeholder={t('prog.newOutcome')} aria-label={t('prog.addOutcome')} className={cx(inputClass, 'h-9 flex-1 text-[13px]')} />
              <Button size="sm" variant="secondary" icon={<Plus className="size-4" />} disabled={outcome.trim().length < 10} onClick={() => run('POST', `/draft/duty-areas/${area.number}/outcomes`, { statement: outcome.trim() }).then((ok) => ok && setOutcome(''))}>{t('prog.addOutcome')}</Button>
            </div>
          )}
        </section>

        <section className="border-t border-line px-5 py-4 lg:border-l lg:border-t-0">
          <h3 className="mb-2 text-[12px] font-bold uppercase tracking-[0.1em] text-muted">{t('prog.evidence')}</h3>
          <ul className="space-y-2.5">
            {area.evidence.map((e) => (
              <li key={e.id} className="rounded-xl border border-line p-2.5">
                <div className="flex items-center gap-2">
                  {editable ? (
                    <select value={e.type} aria-label={t('prog.evidence')} onChange={(ev) => void run('PATCH', `/draft/evidence/${e.id}`, { type: ev.target.value })} className="rounded-md border border-line bg-surface px-1.5 py-0.5 text-[11px] font-bold text-brand">
                      {EVIDENCE.map((x) => <option key={x} value={x}>{t(`prog.ev.${x}` as MessageKey)}</option>)}
                    </select>
                  ) : (
                    <Badge tone="brand">{t(`prog.ev.${e.type}` as MessageKey)}</Badge>
                  )}
                  {e.min_count > 1 && <span className="text-[11px] text-muted">{t('prog.minCount', { n: e.min_count })}</span>}
                  <span className="flex-1" />
                  {editable && <IconButton label={t('prog.remove')} onClick={() => run('DELETE', `/draft/evidence/${e.id}`)} />}
                </div>
                <Editable value={e.description} editable={editable} multiline minLength={5} label={t('prog.evidence')} className="mt-1 text-[13px] text-ink" onSave={(v) => run('PATCH', `/draft/evidence/${e.id}`, { description: v })} />
              </li>
            ))}
          </ul>
          {editable && (
            <div className="mt-3 space-y-2">
              <input value={evidence} onChange={(e) => setEvidence(e.target.value)} placeholder={t('prog.newEvidence')} aria-label={t('prog.addEvidence')} className={cx(inputClass, 'h-9 text-[13px]')} />
              <div className="flex gap-2">
                <select value={type} onChange={(e) => setType(e.target.value as EvidenceType)} aria-label={t('prog.evidence')} className={cx(inputClass, 'h-9 flex-1 text-[13px]')}>
                  {EVIDENCE.map((x) => <option key={x} value={x}>{t(`prog.ev.${x}` as MessageKey)}</option>)}
                </select>
                <Button size="sm" variant="secondary" icon={<Plus className="size-4" />} disabled={evidence.trim().length < 5} onClick={() => run('POST', `/draft/duty-areas/${area.number}/evidence`, { description: evidence.trim(), type }).then((ok) => ok && setEvidence(''))}>{t('prog.addEvidence')}</Button>
              </div>
            </div>
          )}
        </section>
      </div>
    </Card>
  );
}

function IconButton({ label, onClick }: { label: string; onClick: () => void }) {
  return (
    <button type="button" aria-label={label} title={label} onClick={onClick} className="h-fit rounded-lg p-1.5 text-muted hover:bg-[#FDECEC] hover:text-[#B42328] dark:hover:bg-[#B42328]/20">
      <X className="size-4" />
    </button>
  );
}

function OverviewTab({ content, editable, run }: { content: ProgrammeVersionContent; editable: boolean; run: Run }) {
  const { t } = useI18n();
  const save = (field: string, value: unknown) => run('PATCH', '/draft', { [field]: value });
  return (
    <div className="grid gap-5 lg:grid-cols-2">
      <Card className="p-5 lg:col-span-2">
        <Label>{t('prog.summary')}</Label>
        <Editable value={content.summary} editable={editable} multiline minLength={10} label={t('prog.summary')} className="text-[15px] text-ink" onSave={(v) => save('summary', v)} />
        <Label className="mt-4">{t('prog.purpose')}</Label>
        <Editable value={content.purpose} editable={editable} multiline minLength={10} label={t('prog.purpose')} className="text-[15px] leading-relaxed text-ink" onSave={(v) => save('purpose', v)} />
      </Card>
      <ListCard title={t('prog.occupations')} items={content.occupations} editable={editable} onSave={(v) => save('occupations', v)} chips />
      <ListCard title={t('prog.tools')} items={content.tools} editable={editable} onSave={(v) => save('tools', v)} />
      <ListCard title={t('prog.requirements')} items={content.workplace_requirements} editable={editable} onSave={(v) => save('workplace_requirements', v)} />
      <ListCard title={t('prog.toConfirm')} items={content.to_confirm} editable={editable} onSave={(v) => save('to_confirm', v)} />
      <Card className="p-5 lg:col-span-2">
        <Label>{t('prog.qualifications')}</Label>
        <ul className="divide-y divide-line">
          {content.qualifications.map((q, i) => (
            <li key={i} className="flex flex-col gap-1 py-3 sm:flex-row sm:items-start sm:gap-4">
              <Badge tone={q.kind === 'occupational' ? 'success' : q.kind === 'legacy' ? 'warning' : 'neutral'} className="w-fit shrink-0">{t(`prog.q.${q.kind}` as MessageKey)}</Badge>
              <div className="min-w-0 flex-1">
                {q.title && <div className="text-[14px] font-semibold text-ink">{q.title}</div>}
                <div className="text-[13px] text-muted">
                  {[q.saqa_id && `SAQA ${q.saqa_id}`, q.nqf_level && t('prog.nqf', { n: q.nqf_level }), q.credits && t('prog.credits', { n: q.credits })].filter(Boolean).join(' · ')}
                </div>
                {(!q.title || q.kind === 'note') && <p className="mt-1 text-[13px] text-muted">{q.source_text}</p>}
              </div>
            </li>
          ))}
        </ul>
        {content.source?.document && <p className="mt-3 text-[12px] text-muted">{t('prog.source', { document: content.source.document, page: content.source.page ?? '' })}</p>}
      </Card>
    </div>
  );
}

function Label({ children, className }: { children: ReactNode; className?: string }) {
  return <h3 className={cx('mb-1.5 text-[12px] font-bold uppercase tracking-[0.1em] text-muted', className)}>{children}</h3>;
}

function ListCard({ title, items, editable, onSave, chips }: { title: string; items: string[]; editable: boolean; onSave: (v: string[]) => Promise<boolean>; chips?: boolean }) {
  const { t } = useI18n();
  const [adding, setAdding] = useState('');
  return (
    <Card className="p-5">
      <Label>{title}</Label>
      <ul className={chips ? 'flex flex-wrap gap-2' : 'space-y-1.5'}>
        {items.map((item, i) => (
          <li key={i} className={cx('group flex items-start gap-2 text-[14px] text-ink', chips && 'rounded-full bg-surface-2 py-1 pl-3 pr-1.5')}>
            {!chips && <span aria-hidden className="mt-2 size-1.5 shrink-0 rounded-full bg-brand" />}
            <span className="flex-1">{item}</span>
            {editable && (
              <button aria-label={`${t('prog.remove')} ${item}`} onClick={() => void onSave(items.filter((_, k) => k !== i))} className="rounded-full p-0.5 text-muted hover:text-[#B42328]">
                <X className="size-3.5" />
              </button>
            )}
          </li>
        ))}
      </ul>
      {editable && (
        <form className="mt-3 flex gap-2" onSubmit={(e) => { e.preventDefault(); if (adding.trim().length >= 2) void onSave([...items, adding.trim()]).then((ok) => ok && setAdding('')); }}>
          <input value={adding} onChange={(e) => setAdding(e.target.value)} aria-label={`${t('prog.addItem')}: ${title}`} className={cx(inputClass, 'h-9 flex-1 text-[13px]')} />
          <Button type="submit" size="sm" variant="secondary" icon={<Plus className="size-4" />} disabled={adding.trim().length < 2}>{t('prog.addItem')}</Button>
        </form>
      )}
    </Card>
  );
}

function HistoryTab({ code, data }: { code: string; data: ProgrammeStudioData }) {
  const { t, locale } = useI18n();
  const numbers = data.versions.map((v) => v.version).sort((a, b) => a - b);
  const [to, setTo] = useState(numbers[numbers.length - 1] ?? 1);
  const [from, setFrom] = useState(numbers[numbers.length - 2] ?? numbers[0] ?? 1);
  const diff = useQuery({
    queryKey: ['hq-programme-compare', code, from, to],
    queryFn: async () => (await api<{ data: { changes: VersionChange[] } }>(`/platform/programmes/${code}/compare?from=${from}&to=${to}`)).data.changes,
    enabled: from !== to,
  });
  const tone = { added: 'text-[#0B7A5C] dark:text-[#5FD3B1]', removed: 'text-[#B42328] dark:text-[#FF9A9E]', changed: 'text-[#8A5A00] dark:text-[#F5C46B]' } as const;

  return (
    <div className="grid gap-5 lg:grid-cols-[280px_minmax(0,1fr)]">
      <Card className="p-5">
        <ol className="relative space-y-5 border-l border-line pl-5">
          {data.versions.map((v) => (
            <li key={v.version} className="relative">
              <span aria-hidden className={cx('absolute -left-[26px] top-1 size-3 rounded-full border-2 border-surface', v.status === 'published' ? 'bg-[#0B7A5C]' : v.status === 'retired' ? 'bg-line' : 'bg-brand')} />
              <div className="text-[14px] font-bold text-ink">v{v.version} · {t(`prog.status.${v.status}` as MessageKey, { n: v.version })}</div>
              <div className="text-[12px] text-muted">{new Date(v.published_at ?? v.created_at ?? '').toLocaleDateString(locale, { day: 'numeric', month: 'short', year: 'numeric' })}</div>
              {v.change_notes && <p className="mt-1 text-[13px] text-muted">{v.change_notes}</p>}
            </li>
          ))}
        </ol>
      </Card>
      <Card className="p-5">
        <div className="mb-4 flex flex-wrap items-center gap-2 text-[14px] text-ink">
          <GitCompareArrows className="size-4 text-brand" />
          {t('prog.history.compare')}
          <select value={from} onChange={(e) => setFrom(Number(e.target.value))} aria-label={t('prog.history.compare')} className="h-9 rounded-lg border border-line bg-surface px-2.5 text-[13px] font-semibold text-ink focus:border-brand focus:outline-none">
            {numbers.map((n) => <option key={n} value={n}>v{n}</option>)}
          </select>
          {t('prog.history.with')}
          <select value={to} onChange={(e) => setTo(Number(e.target.value))} aria-label={t('prog.history.with')} className="h-9 rounded-lg border border-line bg-surface px-2.5 text-[13px] font-semibold text-ink focus:border-brand focus:outline-none">
            {numbers.map((n) => <option key={n} value={n}>v{n}</option>)}
          </select>
        </div>
        {from === to || diff.data?.length === 0 ? (
          <p className="text-[14px] text-muted">{t('prog.history.none')}</p>
        ) : diff.isPending ? (
          <Skeleton className="h-40" />
        ) : (
          <ul className="divide-y divide-line">
            {diff.data?.map((c, i) => (
              <li key={i} className="py-3">
                <div className="flex items-center gap-2 text-[12px] font-bold uppercase tracking-[0.08em]">
                  <span className={tone[c.change]}>{t(`prog.change.${c.change}` as MessageKey)}</span>
                  <span className="text-muted">· {t(`prog.area.${c.area}` as MessageKey)} · {c.item}</span>
                </div>
                {c.before && <p className={cx('mt-1 text-[14px]', c.change === 'changed' ? 'text-muted line-through decoration-[#B42328]/40' : 'text-ink')}>{c.before}</p>}
                {c.after && <p className="mt-1 text-[14px] text-ink">{c.after}</p>}
              </li>
            ))}
          </ul>
        )}
      </Card>
    </div>
  );
}

function ReviewDialog({ mode, onClose, onSubmit, initial }: { mode: 'submit' | 'approve' | 'sendBack' | null; onClose: () => void; onSubmit: (text: string) => Promise<boolean>; initial: string }) {
  const { t } = useI18n();
  const [text, setText] = useState('');
  const [busy, setBusy] = useState(false);
  const titles = { submit: t('prog.action.submit'), approve: t('prog.action.approve'), sendBack: t('prog.action.sendBack') };
  const required = mode !== 'approve';
  const label = mode === 'submit' ? t('prog.changeNotes') : mode === 'sendBack' ? t('prog.reviewComment') : t('prog.reviewCommentOptional');
  const value = text || (mode === 'submit' ? initial : '');

  return (
    <Drawer
      open={mode !== null}
      onClose={() => { setText(''); onClose(); }}
      title={mode ? titles[mode] : ''}
      footer={
        <>
          <Button variant="secondary" onClick={() => { setText(''); onClose(); }}>{t('common.cancel')}</Button>
          <Button loading={busy} disabled={required && value.trim().length < 10} onClick={async () => { setBusy(true); if (await onSubmit(value.trim())) setText(''); setBusy(false); }}>
            {mode ? titles[mode] : ''}
          </Button>
        </>
      }
    >
      <label className="mb-1.5 block text-[13px] font-semibold text-ink" htmlFor="review-text">{label}</label>
      <textarea id="review-text" value={value} onChange={(e) => setText(e.target.value)} rows={6} className={cx(inputClass, 'h-auto py-3')} />
      {mode === 'submit' && <p className="mt-1.5 text-[13px] text-muted">{t('prog.changeNotesHint')}</p>}
    </Drawer>
  );
}
