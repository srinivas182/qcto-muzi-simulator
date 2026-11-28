import { useState, type ReactNode } from 'react';
import { Link, useParams } from 'react-router';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowDown, ArrowLeft, ArrowUp, CheckCircle2, Languages, Plus, Save, Sparkles, Trash2, Upload } from 'lucide-react';
import type { AssessmentTaskDetail, ContentLocale, Question, QuestionI18n, TaskSettings } from '@simuwork/core';
import type { MessageKey } from '@simuwork/core/locales/en';
import { Badge, Button, Card, cx, inputClass, Skeleton, useToast } from '../../components/ui';
import { api, ApiError } from '../../lib/api';
import { useI18n } from '../../lib/i18n';
import { can, useSession } from '../../lib/session';

const LOCALES: ContentLocale[] = ['en', 'zu', 'xh', 'af'];
const newId = () => Math.random().toString(36).slice(2, 10).padEnd(8, '0');
const errorText = (e: unknown, fallback: string) => (e instanceof ApiError ? Object.values(e.errors).flat().join(' ') || e.message : fallback);
type T = ReturnType<typeof useI18n>['t'];

function blank(type: Question['type'], outcome: string | null): Question {
  const base = { id: newId(), prompt: '', outcome, marks: type === 'short' ? 3 : 1 };
  if (type === 'short') return { ...base, type, model_answer: '' };
  if (type === 'multi') return { ...base, type, options: ['', '', ''], answer: [], explain: '' };
  return { ...base, type, options: ['', ''], answer: 0, explain: '' };
}

/** HQ: one summative task of the assessment plan; for tests, its question bank (with AI drafting, translation and review). */
export default function AssessmentTask() {
  const { id = '', code = '' } = useParams();
  const q = useQuery({ queryKey: ['assessment-task', id], queryFn: () => api<{ data: AssessmentTaskDetail }>(`/platform/assessment-tasks/${id}`) });
  if (!q.data) return <div className="space-y-4"><Skeleton className="h-10 w-80" /><Skeleton className="h-[480px] rounded-2xl" /></div>;
  return <Editor key={q.dataUpdatedAt} code={code} a={q.data.data} />;
}

function Editor({ code, a }: { code: string; a: AssessmentTaskDetail }) {
  const { t } = useI18n();
  const toast = useToast();
  const qc = useQueryClient();
  const { data: session } = useSession();
  const [questions, setQuestions] = useState<Question[]>(a.draft.questions);
  const [settings, setSettings] = useState<TaskSettings>(a.draft.settings);
  const [i18n, setI18n] = useState<QuestionI18n>(a.draft.i18n);
  const [locale, setLocale] = useState<ContentLocale>('en');
  const dirty = JSON.stringify([questions, settings, i18n]) !== JSON.stringify([a.draft.questions, a.draft.settings, a.draft.i18n]);
  const author = can(session, 'content.author');
  const test = a.kind === 'test';
  const call = useMutation({
    mutationFn: ({ path, body, method = 'POST' }: { path: string; body?: unknown; method?: 'POST' | 'PATCH'; msg?: string }) => api<{ data: AssessmentTaskDetail }>(`/platform/assessment-tasks/${a.id}${path}`, { method, body }),
    onSuccess: (r, v) => { qc.setQueryData(['assessment-task', a.id], r); void qc.invalidateQueries({ queryKey: ['assessment-plan', code] }); if (v.msg) toast(v.msg); },
    onError: (e) => toast(errorText(e, t('common.error')), 'error'),
  });
  const guard = (fn: () => void) => () => (dirty ? toast(t('ls.saveFirst'), 'error') : fn());
  const setQ = (i: number, x: Question) => setQuestions((all) => all.map((y, j) => (j === i ? x : y)));
  const move = (i: number, d: -1 | 1) => setQuestions((all) => { const n = [...all]; const [x] = n.splice(i, 1); n.splice(i + d, 0, x!); return n; });
  const setTr = (qid: string, field: 'prompt' | 'explain' | 'options', v: string | string[]) =>
    setI18n((all) => { const l = locale as 'zu'; return { ...all, [l]: { ...all[l], [qid]: { ...all[l]?.[qid], [field]: v } } }; });
  const review = a.draft.review[locale];

  return (
    <div className="sw-in">
      <Link to={`/hq/programmes/${code}?tab=assessment`} className="mb-3 inline-flex items-center gap-1.5 text-[13px] font-semibold text-muted hover:text-ink"><ArrowLeft className="size-4" />{t('ls.back')}</Link>
      <div className="mb-5 flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
        <div>
          <p className="text-[12px] font-semibold uppercase tracking-wide text-muted">{`${a.code} · ${t(`ap.component.${a.component}` as MessageKey)} · ${t(`ap.kind.${a.kind}` as MessageKey)} · ${a.outcomes.join(', ')}`}</p>
          <h1 className="mt-1 text-[26px] font-bold tracking-tight text-ink">{a.title}</h1>
          <div className="mt-2 flex gap-2"><Badge tone={a.status === 'published' ? 'success' : 'neutral'}>{t(a.status === 'published' ? 'cs.status.published' : 'cs.status.draft')}{a.status === 'published' ? ` · v${a.content_version}` : ''}</Badge>{a.has_draft && a.status === 'published' && <Badge tone="warning">{t('cs.status.changed')}</Badge>}</div>
        </div>
        <div className="flex flex-wrap gap-2">
          {author && test && a.ai_ready && locale === 'en' && <Button variant="secondary" icon={<Sparkles className="size-4" />} loading={call.isPending && call.variables?.path === '/ai-draft'} onClick={guard(() => call.mutate({ path: '/ai-draft', body: { count: 10 }, msg: t('ap.aiAdded') }))}>{t('ap.aiDraft')}</Button>}
          {author && test && a.ai_ready && locale !== 'en' && <Button variant="secondary" icon={<Languages className="size-4" />} onClick={guard(() => call.mutate({ path: '/translate', body: { locales: [locale] }, msg: t('ls.translated') }))}>{t('ls.aiTranslate')}</Button>}
          {author && <Button variant="secondary" icon={<Save className="size-4" />} disabled={!dirty} onClick={() => call.mutate({ path: '', method: 'PATCH', body: { questions, settings, i18n }, msg: t('ls.saved') })}>{t('common.save')}</Button>}
          {can(session, 'content.publish') && <Button icon={<Upload className="size-4" />} onClick={guard(() => call.mutate({ path: '/publish', msg: t('ls.publishedMsg') }))}>{t('ls.publish')}</Button>}
        </div>
      </div>

      {test && (
        <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
          <div role="tablist" aria-label={t('ls.language')} className="inline-flex rounded-xl bg-surface-2 p-1">
            {LOCALES.map((l) => (
              <button key={l} role="tab" aria-selected={locale === l} onClick={() => setLocale(l)} className={cx('flex h-9 items-center gap-1.5 rounded-lg px-3.5 text-[13px] font-semibold', locale === l ? 'bg-surface text-ink shadow-sm' : 'text-muted hover:text-ink')}>
                {t(`ls.lang.${l}` as MessageKey)}{a.review[l] === 'reviewed' ? <CheckCircle2 className="size-3.5 text-[#1E7A46]" /> : a.review[l] === 'ai' ? <Sparkles className="size-3.5 text-[#9A5B00]" /> : null}
              </button>
            ))}
          </div>
          {can(session, 'content.review') && (
            <label className="flex items-center gap-2 text-[13px] font-medium text-ink">
              <input type="checkbox" className="size-4 accent-[var(--brand)]" checked={!!review?.by} disabled={dirty || (locale !== 'en' && !i18n[locale as 'zu'])} onChange={(e) => call.mutate({ path: '/review', body: { locale, reviewed: e.target.checked }, msg: e.target.checked ? t('ls.reviewedMsg') : undefined })} />
              {review?.by ? t('ls.reviewedBy', { name: review.by }) : t('ap.markReviewed')}
            </label>
          )}
        </div>
      )}
      {a.problems.length > 0 && !dirty && <div role="alert" className="mb-4 rounded-xl border border-[#F5C26B] bg-[#FFF7E6] px-4 py-3 text-[13px] text-[#7A4A00]"><p className="font-semibold">{t('ls.beforePublish')}</p><ul className="mt-1 list-disc pl-5">{a.problems.map((p) => <li key={p}>{p}</li>)}</ul></div>}

      {locale === 'en' && <SettingsCard a={a} s={settings} onChange={setSettings} readOnly={!author} t={t} />}

      {test && locale === 'en' && (
        <div className="mt-4 space-y-3">
          {questions.map((x, i) => (
            <Card key={x.id} className="p-4">
              <div className="mb-3 flex items-center justify-between gap-2">
                <span className="text-[12px] font-bold uppercase tracking-wide text-muted">{`${i + 1}. ${t(`ap.qtype.${x.type}` as MessageKey)} · ${t('ap.marksN', { n: x.marks })}`}</span>
                {author && <span className="flex gap-1">
                  <Icon label={t('ls.up')} disabled={i === 0} onClick={() => move(i, -1)}><ArrowUp className="size-4" /></Icon>
                  <Icon label={t('ls.down')} disabled={i === questions.length - 1} onClick={() => move(i, 1)}><ArrowDown className="size-4" /></Icon>
                  <Icon label={t('ls.remove')} onClick={() => setQuestions((all) => all.filter((_, j) => j !== i))}><Trash2 className="size-4" /></Icon>
                </span>}
              </div>
              <QuestionEditor x={x} outcomes={a.outcome_details} onChange={(y) => setQ(i, y)} readOnly={!author} t={t} />
            </Card>
          ))}
          {author && (
            <Card className="flex flex-wrap items-center gap-2 p-4">
              <span className="mr-1 text-[13px] font-semibold text-ink">{t('ap.addQuestion')}</span>
              {(['single', 'multi', 'short'] as const).map((ty) => <Button key={ty} size="sm" variant="secondary" icon={<Plus className="size-4" />} onClick={() => setQuestions((all) => [...all, blank(ty, a.outcomes[0] ?? null)])}>{t(`ap.qtype.${ty}`)}</Button>)}
            </Card>
          )}
        </div>
      )}

      {test && locale !== 'en' && (
        <div className="space-y-3">
          <p className="rounded-xl bg-surface-2 px-4 py-2.5 text-[13px] text-muted">{t('ap.translationNote')}</p>
          {questions.map((x, i) => {
            const tr = i18n[locale as 'zu']?.[x.id] ?? {};
            return (
              <Card key={x.id} className="space-y-3 p-4">
                <p className="text-[12px] font-bold uppercase tracking-wide text-muted">{`${i + 1}. ${t(`ap.qtype.${x.type}` as MessageKey)}`}</p>
                <Tr en={x.prompt} value={tr.prompt ?? ''} label={t('ap.f.prompt')} onChange={(v) => setTr(x.id, 'prompt', v)} readOnly={!author} />
                {x.type !== 'short' && x.options.map((o, k) => <Tr key={k} en={o} value={tr.options?.[k] ?? ''} label={`${t('ap.f.option')} ${k + 1}`} readOnly={!author} onChange={(v) => { const n = x.options.map((_, j) => tr.options?.[j] ?? ''); n[k] = v; setTr(x.id, 'options', n); }} />)}
                {x.type !== 'short' && x.explain && <Tr en={x.explain} value={tr.explain ?? ''} label={t('ap.f.explain')} onChange={(v) => setTr(x.id, 'explain', v)} readOnly={!author} />}
              </Card>
            );
          })}
        </div>
      )}
    </div>
  );
}

function SettingsCard({ a, s, onChange, readOnly, t }: { a: AssessmentTaskDetail; s: TaskSettings; onChange: (s: TaskSettings) => void; readOnly: boolean; t: T }) {
  const num = (k: 'pick' | 'minutes' | 'pass_mark' | 'attempts' | 'min_days', label: string) => (
    <Labelled label={label}><input type="number" className={inputClass} value={s[k] ?? ''} disabled={readOnly} onChange={(e) => onChange({ ...s, [k]: Number(e.target.value) })} /></Labelled>
  );
  return (
    <Card className="p-4">
      {a.kind === 'test' && (
        <div className="grid gap-3 sm:grid-cols-5">
          {num('pick', t('ap.f.pick'))}{num('minutes', t('ap.f.minutes'))}{num('pass_mark', t('ap.f.passMark'))}{num('attempts', t('ap.f.attempts'))}
          <Labelled label={t('ap.f.integrity')}><select className={inputClass} value={s.integrity ?? 'light'} disabled={readOnly} onChange={(e) => onChange({ ...s, integrity: e.target.value as 'light' | 'camera' })}><option value="light">{t('ap.integrity.light')}</option><option value="camera">{t('ap.integrity.camera')}</option></select></Labelled>
          <p className="text-[12px] text-muted sm:col-span-5">{t(s.integrity === 'camera' ? 'ap.integrity.cameraHint' : 'ap.integrity.lightHint')}</p>
        </div>
      )}
      {a.kind === 'simulator' && <p className="text-[14px] text-ink">{t('ap.simulatorNote', { sim: a.simulators.find((x) => x.id === a.activity_id)?.title ?? '—' })}</p>}
      {a.kind === 'logbook' && <div className="w-48">{num('min_days', t('ap.f.minDays'))}</div>}
      {a.kind === 'observation' && (
        <fieldset className="space-y-2">
          <legend className="mb-1 text-[13px] font-semibold text-ink">{t('ap.f.items')}</legend>
          {(s.items ?? []).map((it, i) => (
            <div key={i} className="flex gap-2">
              <input aria-label={`${t('ap.f.items')} ${i + 1}`} className={inputClass} value={it} disabled={readOnly} onChange={(e) => onChange({ ...s, items: (s.items ?? []).map((x, j) => (j === i ? e.target.value : x)) })} />
              {!readOnly && <Icon label={t('ls.remove')} onClick={() => onChange({ ...s, items: (s.items ?? []).filter((_, j) => j !== i) })}><Trash2 className="size-4" /></Icon>}
            </div>
          ))}
          {!readOnly && <Button size="sm" variant="ghost" icon={<Plus className="size-4" />} onClick={() => onChange({ ...s, items: [...(s.items ?? []), ''] })}>{t('ap.addItem')}</Button>}
        </fieldset>
      )}
    </Card>
  );
}

function QuestionEditor({ x, outcomes, onChange, readOnly, t }: { x: Question; outcomes: { code: string; statement: string }[]; onChange: (q: Question) => void; readOnly: boolean; t: T }) {
  return (
    <div className="space-y-3">
      <Labelled label={t('ap.f.prompt')}><textarea rows={2} className={inputClass} value={x.prompt} disabled={readOnly} onChange={(e) => onChange({ ...x, prompt: e.target.value })} /></Labelled>
      {x.type === 'short' ? (
        <Labelled label={t('ap.f.modelAnswer')}><textarea rows={2} className={inputClass} value={x.model_answer} disabled={readOnly} onChange={(e) => onChange({ ...x, model_answer: e.target.value })} /></Labelled>
      ) : (
        <fieldset className="space-y-2">
          <legend className="mb-1 text-[13px] font-semibold text-ink">{t(x.type === 'multi' ? 'ap.f.optionsMulti' : 'ap.f.options')}</legend>
          {x.options.map((o, i) => {
            const right = x.type === 'single' ? x.answer === i : x.answer.includes(i);
            const toggle = () => onChange(x.type === 'single' ? { ...x, answer: i } : { ...x, answer: right ? x.answer.filter((k) => k !== i) : [...x.answer, i].sort() });
            return (
              <div key={i} className="flex items-center gap-2">
                <input type={x.type === 'single' ? 'radio' : 'checkbox'} name={`a-${x.id}`} aria-label={`${t('ap.f.right')} ${i + 1}`} className="size-4 accent-[var(--brand)]" checked={right} disabled={readOnly} onChange={toggle} />
                <input aria-label={`${t('ap.f.option')} ${i + 1}`} className={inputClass} value={o} disabled={readOnly} onChange={(e) => onChange({ ...x, options: x.options.map((y, j) => (j === i ? e.target.value : y)) })} />
              </div>
            );
          })}
          {!readOnly && x.options.length < 6 && <Button size="sm" variant="ghost" icon={<Plus className="size-4" />} onClick={() => onChange({ ...x, options: [...x.options, ''] })}>{t('ss.addOption' as MessageKey)}</Button>}
        </fieldset>
      )}
      {x.type !== 'short' && <Labelled label={t('ap.f.explain')}><input className={inputClass} value={x.explain} disabled={readOnly} onChange={(e) => onChange({ ...x, explain: e.target.value })} /></Labelled>}
      <div className="grid gap-3 sm:grid-cols-2">
        <Labelled label={t('ap.f.outcome')}><select className={inputClass} value={x.outcome ?? ''} disabled={readOnly} onChange={(e) => onChange({ ...x, outcome: e.target.value || null })}><option value="">—</option>{outcomes.map((o) => <option key={o.code} value={o.code}>{`${o.code} · ${o.statement.slice(0, 60)}`}</option>)}</select></Labelled>
        <Labelled label={t('ap.f.marks')}><input type="number" min={1} max={10} className={inputClass} value={x.marks} disabled={readOnly} onChange={(e) => onChange({ ...x, marks: Number(e.target.value) })} /></Labelled>
      </div>
    </div>
  );
}

function Tr({ en, value, label, onChange, readOnly }: { en: string; value: string; label: string; onChange: (v: string) => void; readOnly: boolean }) {
  return (
    <div className="grid gap-2 sm:grid-cols-2">
      <div><p className="text-[12px] font-semibold text-muted">{label}</p><p className="mt-1 text-[14px] text-ink">{en}</p></div>
      <textarea aria-label={label} rows={2} className={inputClass} value={value} disabled={readOnly} onChange={(e) => onChange(e.target.value)} />
    </div>
  );
}

function Labelled({ label, children }: { label: string; children: ReactNode }) {
  return <label className="block"><span className="mb-1.5 block text-[13px] font-semibold text-ink">{label}</span>{children}</label>;
}

function Icon({ label, onClick, disabled, children }: { label: string; onClick: () => void; disabled?: boolean; children: ReactNode }) {
  return <button type="button" aria-label={label} title={label} disabled={disabled} onClick={onClick} className="grid size-8 place-items-center rounded-lg text-muted hover:bg-surface-2 hover:text-ink disabled:opacity-30">{children}</button>;
}
