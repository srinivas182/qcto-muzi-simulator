import { useState, type ReactNode } from 'react';
import { Link, useParams } from 'react-router';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowDown, ArrowLeft, ArrowUp, CheckCircle2, GitBranch, Languages, Plus, RotateCcw, Save, Sparkles, Trash2, Upload } from 'lucide-react';
import {
  localiseScenario, type ContentLocale, type ScenarioContent, type ScenarioI18n, type ScenarioStep, type SimEffect, type SimMode, type StudioScenarioDetail,
} from '@simuwork/core';
import type { MessageKey } from '@simuwork/core/locales/en';
import { SimRunner } from '../../components/sim/SimRunner';
import { Badge, Button, Card, cx, inputClass, Skeleton, useToast } from '../../components/ui';
import { api, ApiError } from '../../lib/api';
import { useI18n } from '../../lib/i18n';
import { can, useSession } from '../../lib/session';

const LOCALES: ContentLocale[] = ['en', 'zu', 'xh', 'af'];
const EFFECTS: SimEffect[] = ['none', 'spark', 'static', 'beep', 'customer'];
const newId = () => Math.random().toString(36).slice(2, 10).padEnd(8, '0');
const errorText = (e: unknown, fallback: string) => (e instanceof ApiError ? Object.values(e.errors).flat().join(' ') || e.message : fallback);
type T = ReturnType<typeof useI18n>['t'];

function blankStep(type: ScenarioStep['type']): ScenarioStep {
  const base = { id: newId(), prompt: '', hints: [], why: '', outcome: null, lesson: null, critical: false, only: [], sets: {}, wrong: [] };
  if (type === 'choose') return { ...base, type, options: ['', ''], answer: 0 };
  if (type === 'place') return { ...base, type, target: '', slot: '' };
  return { ...base, type, target: '' };
}

/** HQ Scenario Studio: build a simulator scenario with forms, branch it, translate, review, publish — with a live preview. */
export default function ScenarioStudio() {
  const { id = '', code = '' } = useParams();
  const detail = useQuery({ queryKey: ['studio-activity', id], queryFn: () => api<{ data: StudioScenarioDetail }>(`/platform/activities/${id}`) });
  if (!detail.data) return <div className="space-y-4"><Skeleton className="h-10 w-80" /><Skeleton className="h-[480px] rounded-2xl" /></div>;
  return <Editor key={detail.dataUpdatedAt} code={code} a={detail.data.data} />;
}

function Editor({ code, a }: { code: string; a: StudioScenarioDetail }) {
  const { t, tx } = useI18n();
  const toast = useToast();
  const qc = useQueryClient();
  const { data: session } = useSession();
  const [sc, setSc] = useState<ScenarioContent>(a.draft.scenario);
  const [i18n, setI18n] = useState<ScenarioI18n>(a.draft.i18n);
  const [locale, setLocale] = useState<ContentLocale>('en');
  const [preview, setPreview] = useState<{ mode: SimMode; variant: string | null; n: number }>({ mode: 'practice', variant: a.draft.scenario.variants[0]?.id ?? null, n: 0 });
  const dirty = JSON.stringify(sc) !== JSON.stringify(a.draft.scenario) || JSON.stringify(i18n) !== JSON.stringify(a.draft.i18n);
  const author = can(session, 'content.author');
  const reviewer = can(session, 'content.review');
  const publisher = can(session, 'content.publish');
  const objects = a.scenes[sc.scene] ?? [];
  const obj = (id: string) => tx(`sim.obj.${id}`, id);

  const call = useMutation({
    mutationFn: ({ path, body, method = 'POST' }: { path: string; body?: unknown; method?: 'POST' | 'PATCH'; msg?: string }) => api<{ data: StudioScenarioDetail }>(`/platform/activities/${a.id}${path}`, { method, body }),
    onSuccess: (r, v) => {
      qc.setQueryData(['studio-activity', a.id], r);
      void qc.invalidateQueries({ queryKey: ['studio-activities', code] });
      if (v.msg) toast(v.msg);
    },
    onError: (e) => toast(errorText(e, t('common.error')), 'error'),
  });
  const guard = (fn: () => void) => () => (dirty ? toast(t('ls.saveFirst'), 'error') : fn());
  const setStep = (i: number, st: ScenarioStep) => setSc((s) => ({ ...s, steps: s.steps.map((x, j) => (j === i ? st : x)) }));
  const move = (i: number, d: -1 | 1) => setSc((s) => { const n = [...s.steps]; const [x] = n.splice(i, 1); n.splice(i + d, 0, x!); return { ...s, steps: n }; });
  const tr = locale === 'en' ? null : (i18n[locale] ?? {});
  const setTr = (stepId: string, field: string, value: string | string[]) =>
    setI18n((all) => { const l = locale as 'zu'; const cur = all[l] ?? {}; return { ...all, [l]: { ...cur, steps: { ...cur.steps, [stepId]: { ...cur.steps?.[stepId], [field]: value } } } }; });
  const review = a.draft.review[locale];
  const shown = localiseScenario(sc, i18n, locale);
  const restart = (p: Partial<typeof preview>) => setPreview((x) => ({ ...x, ...p, n: x.n + 1 }));

  return (
    <div className="sw-in">
      <Link to={`/hq/programmes/${code}`} className="mb-3 inline-flex items-center gap-1.5 text-[13px] font-semibold text-muted hover:text-ink"><ArrowLeft className="size-4" />{t('ls.back')}</Link>
      <div className="mb-5 flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
        <div>
          <p className="text-[12px] font-semibold uppercase tracking-wide text-muted">{a.code} · {a.outcomes.join(', ')}</p>
          <h1 className="mt-1 text-[26px] font-bold tracking-tight text-ink">{a.title}</h1>
          <div className="mt-2 flex flex-wrap gap-2">
            <Badge tone={a.status === 'published' ? 'success' : 'neutral'}>{t(a.status === 'published' ? 'cs.status.published' : 'cs.status.draft')}{a.status === 'published' ? ` · v${a.content_version}` : ''}</Badge>
            {a.has_draft && a.status === 'published' && <Badge tone="warning">{t('cs.status.changed')}</Badge>}
            {sc.variants.length > 1 && <Badge tone="brand"><GitBranch className="size-3" />{t('ss.causesN', { n: sc.variants.length })}</Badge>}
          </div>
        </div>
        <div className="flex flex-wrap gap-2">
          {author && a.ai_ready && locale !== 'en' && <Button variant="secondary" icon={<Languages className="size-4" />} loading={call.isPending && call.variables?.path === '/translate'} onClick={guard(() => call.mutate({ path: '/translate', body: { locales: [locale] }, msg: t('ls.translated') }))}>{t('ls.aiTranslate')}</Button>}
          {author && <Button variant="secondary" icon={<Save className="size-4" />} disabled={!dirty} loading={call.isPending && call.variables?.method === 'PATCH'} onClick={() => call.mutate({ path: '', method: 'PATCH', body: { scenario: sc, i18n }, msg: t('ls.saved') })}>{t('common.save')}</Button>}
          {publisher && <Button icon={<Upload className="size-4" />} loading={call.isPending && call.variables?.path === '/publish'} onClick={guard(() => call.mutate({ path: '/publish', msg: t('ls.publishedMsg') }))}>{t('ls.publish')}</Button>}
        </div>
      </div>

      <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div role="tablist" aria-label={t('ls.language')} className="inline-flex rounded-xl bg-surface-2 p-1">
          {LOCALES.map((l) => (
            <button key={l} role="tab" aria-selected={locale === l} onClick={() => setLocale(l)} className={cx('flex h-9 items-center gap-1.5 rounded-lg px-3.5 text-[13px] font-semibold transition', locale === l ? 'bg-surface text-ink shadow-sm' : 'text-muted hover:text-ink')}>
              {t(`ls.lang.${l}` as MessageKey)}
              {a.review[l] === 'reviewed' ? <CheckCircle2 className="size-3.5 text-[#1E7A46]" /> : a.review[l] === 'ai' ? <Sparkles className="size-3.5 text-[#9A5B00]" /> : null}
            </button>
          ))}
        </div>
        {reviewer && (
          <label className="flex items-center gap-2 text-[13px] font-medium text-ink">
            <input type="checkbox" className="size-4 accent-[var(--brand)]" checked={!!review?.by} disabled={dirty || (locale !== 'en' && !i18n[locale as 'zu'])}
              onChange={(e) => call.mutate({ path: '/review', body: { locale, reviewed: e.target.checked }, msg: e.target.checked ? t('ls.reviewedMsg') : undefined })} />
            {review?.by ? t('ls.reviewedBy', { name: review.by }) : t('ss.markReviewed')}
          </label>
        )}
      </div>
      {a.problems.length > 0 && !dirty && <div role="alert" className="mb-4 rounded-xl border border-[#F5C26B] bg-[#FFF7E6] px-4 py-3 text-[13px] text-[#7A4A00] dark:bg-[#9A5B00]/20 dark:text-[#F5C26B]"><p className="font-semibold">{t('ls.beforePublish')}</p><ul className="mt-1 list-disc pl-5">{a.problems.map((p) => <li key={p}>{p}</li>)}</ul></div>}

      <div className="grid gap-6 xl:grid-cols-[1fr_440px]">
        <div className="space-y-3">
          {locale === 'en' ? (
            <>
              <Card className="grid gap-3 p-4 sm:grid-cols-3">
                <Labelled label={t('ss.scene')}><select className={inputClass} value={sc.scene} disabled={!author} onChange={(e) => setSc({ ...sc, scene: e.target.value })}>{Object.keys(a.scenes).map((s) => <option key={s} value={s}>{tx(`ss.scene.${s}`, s)}</option>)}</select></Labelled>
                <Labelled label={t('ss.passMark')}><input type="number" min={0} max={100} className={inputClass} value={sc.pass_mark} disabled={!author} onChange={(e) => setSc({ ...sc, pass_mark: Number(e.target.value) })} /></Labelled>
                <Labelled label={t('ss.jobMinutes')}><input type="number" min={1} max={60} className={inputClass} value={sc.job?.minutes ?? 15} disabled={!author} onChange={(e) => setSc({ ...sc, job: { customer: sc.job?.customer ?? '', minutes: Number(e.target.value) } })} /></Labelled>
                <div className="sm:col-span-3"><Labelled label={t('ss.customer')}><input className={inputClass} value={sc.job?.customer ?? ''} placeholder={t('ss.customerPh')} disabled={!author} onChange={(e) => setSc({ ...sc, job: { minutes: sc.job?.minutes ?? 15, customer: e.target.value } })} /></Labelled></div>
              </Card>
              <Causes sc={sc} setSc={setSc} objects={objects} obj={obj} readOnly={!author} t={t} />
              {sc.steps.map((st, i) => (
                <Card key={st.id} className="p-4">
                  <div className="mb-3 flex items-center justify-between gap-2">
                    <span className="text-[12px] font-bold uppercase tracking-wide text-muted">{i + 1}. {t(`ss.type.${st.type}` as MessageKey)}{st.critical ? ` · ${t('ss.critical')}` : ''}</span>
                    {author && (
                      <span className="flex gap-1">
                        <IconButton label={t('ls.up')} disabled={i === 0} onClick={() => move(i, -1)}><ArrowUp className="size-4" /></IconButton>
                        <IconButton label={t('ls.down')} disabled={i === sc.steps.length - 1} onClick={() => move(i, 1)}><ArrowDown className="size-4" /></IconButton>
                        <IconButton label={t('ls.remove')} onClick={() => setSc({ ...sc, steps: sc.steps.filter((_, j) => j !== i) })}><Trash2 className="size-4" /></IconButton>
                      </span>
                    )}
                  </div>
                  <StepEditor st={st} sc={sc} a={a} objects={objects} obj={obj} onChange={(x) => setStep(i, x)} readOnly={!author} t={t} />
                </Card>
              ))}
              {author && (
                <Card className="flex flex-wrap items-center gap-2 p-4">
                  <span className="mr-1 text-[13px] font-semibold text-ink">{t('ss.addStep')}</span>
                  {(['tap', 'choose', 'place'] as const).map((ty) => <Button key={ty} size="sm" variant="secondary" icon={<Plus className="size-4" />} onClick={() => setSc({ ...sc, steps: [...sc.steps, blankStep(ty)] })}>{t(`ss.type.${ty}`)}</Button>)}
                </Card>
              )}
            </>
          ) : (
            <>
              <p className="rounded-xl bg-surface-2 px-4 py-2.5 text-[13px] text-muted">{t('ls.translationNote')}</p>
              {sc.job && <Card className="p-4"><TrRow label={t('ss.customer')} en={sc.job.customer} value={tr?.job?.customer ?? ''} readOnly={!author} onChange={(v) => setI18n((all) => ({ ...all, [locale]: { ...all[locale as 'zu'], job: { customer: v } } }))} /></Card>}
              {sc.steps.map((st, i) => {
                const x = tr?.steps?.[st.id] ?? {};
                const list = (f: 'hints' | 'options' | 'wrong', en: string[]) => en.map((e, k) => (
                  <TrRow key={`${f}${k}`} label={`${t(`ss.f.${f}` as MessageKey)} ${k + 1}`} en={e} value={x[f]?.[k] ?? ''} readOnly={!author}
                    onChange={(v) => { const n = en.map((_, j) => x[f]?.[j] ?? ''); n[k] = v; setTr(st.id, f, n); }} />
                ));
                return (
                  <Card key={st.id} className="space-y-3 p-4">
                    <p className="text-[12px] font-bold uppercase tracking-wide text-muted">{i + 1}. {t(`ss.type.${st.type}` as MessageKey)}</p>
                    <TrRow label={t('ss.f.prompt')} en={st.prompt} value={x.prompt ?? ''} readOnly={!author} onChange={(v) => setTr(st.id, 'prompt', v)} />
                    {st.type === 'choose' && list('options', st.options)}
                    {list('hints', st.hints)}
                    {list('wrong', st.wrong.map((w) => w.message))}
                    {st.why && <TrRow label={t('ss.f.why')} en={st.why} value={x.why ?? ''} readOnly={!author} onChange={(v) => setTr(st.id, 'why', v)} />}
                  </Card>
                );
              })}
            </>
          )}
        </div>

        <div className="xl:sticky xl:top-20 xl:self-start">
          <Card className="p-4">
            <div className="mb-3 flex flex-wrap items-center gap-2">
              <p className="mr-auto text-[13px] font-bold text-ink">{t('ss.preview')}</p>
              {sc.variants.length > 1 && (
                <select aria-label={t('ss.previewCause')} className={smallSelect} value={preview.variant ?? ''} onChange={(e) => restart({ variant: e.target.value })}>
                  {sc.variants.map((v) => <option key={v.id} value={v.id}>{v.label || v.id}</option>)}
                </select>
              )}
              <select aria-label={t('ss.previewMode')} className={smallSelect} value={preview.mode} onChange={(e) => restart({ mode: e.target.value as SimMode })}>
                <option value="practice">{t('sim.practice')}</option><option value="job">{t('sim.job')}</option>
              </select>
              <IconButton label={t('ss.restart')} onClick={() => restart({})}><RotateCcw className="size-4" /></IconButton>
            </div>
            {sc.steps.length === 0 ? <p className="py-10 text-center text-[14px] text-muted">{t('ss.empty')}</p> : (
              // Any edit restarts the preview, so it always shows what the learner would get.
              <SimRunner key={`${preview.n}-${preview.mode}-${preview.variant}-${locale}-${JSON.stringify(shown)}`} content={shown} mode={preview.mode}
                variant={sc.variants.length > 1 ? (preview.variant ?? sc.variants[0]!.id) : null} customer={shown.job?.customer ?? null}
                onMove={noop} onDone={() => toast(t('ss.previewDone'))} onQuit={() => restart({})} />
            )}
          </Card>
        </div>
      </div>
    </div>
  );
}

const noop = () => undefined;
const smallSelect = 'h-8 max-w-[190px] rounded-lg border border-line bg-surface px-2 text-[13px] text-ink';

/** Different causes of the same symptom. Each run picks one; steps can belong to some causes only. */
function Causes({ sc, setSc, objects, obj, readOnly, t }: { sc: ScenarioContent; setSc: (s: ScenarioContent) => void; objects: string[]; obj: (id: string) => string; readOnly: boolean; t: T }) {
  const setV = (i: number, v: ScenarioContent['variants'][number]) => setSc({ ...sc, variants: sc.variants.map((x, j) => (j === i ? v : x)) });
  return (
    <Card className="p-4">
      <div className="flex items-center gap-2"><GitBranch className="size-4 text-brand" /><p className="text-[14px] font-bold text-ink">{t('ss.causes')}</p></div>
      <p className="mt-1 text-[13px] text-muted">{t('ss.causesLead')}</p>
      <div className="mt-3 space-y-3">
        {sc.variants.map((v, i) => (
          <div key={v.id} className="rounded-xl border border-line p-3">
            <div className="flex gap-2">
              <input aria-label={t('ss.causeLabel')} className={inputClass} value={v.label ?? ''} placeholder={t('ss.causeLabelPh')} disabled={readOnly} onChange={(e) => setV(i, { ...v, label: e.target.value })} />
              {!readOnly && <IconButton label={t('ls.remove')} onClick={() => setSc({ ...sc, variants: sc.variants.filter((_, j) => j !== i), steps: sc.steps.map((st) => ({ ...st, only: st.only.filter((o) => o !== v.id) })) })}><Trash2 className="size-4" /></IconButton>}
            </div>
            <StateRows label={t('ss.startsAs')} value={v.start} objects={objects} obj={obj} readOnly={readOnly} t={t} onChange={(start) => setV(i, { ...v, start })} />
          </div>
        ))}
      </div>
      {!readOnly && sc.variants.length < 4 && <Button className="mt-3" size="sm" variant="secondary" icon={<Plus className="size-4" />} onClick={() => setSc({ ...sc, variants: [...sc.variants, { id: newId().slice(0, 6), label: '', start: {} }] })}>{t('ss.addCause')}</Button>}
    </Card>
  );
}

function StepEditor({ st, sc, a, objects, obj, onChange, readOnly, t }: {
  st: ScenarioStep; sc: ScenarioContent; a: StudioScenarioDetail; objects: string[]; obj: (id: string) => string; onChange: (s: ScenarioStep) => void; readOnly: boolean; t: T;
}) {
  const pick = (value: string, set: (v: string) => void, label: string) => (
    <Labelled label={label}><select className={inputClass} value={value} disabled={readOnly} onChange={(e) => set(e.target.value)}><option value="">—</option>{objects.map((o) => <option key={o} value={o}>{obj(o)}</option>)}</select></Labelled>
  );
  const onOptions = st.type === 'choose' ? st.options.map((_, i) => `option:${i}`) : [];
  return (
    <div className="space-y-3">
      <Labelled label={t('ss.f.prompt')}><textarea rows={2} className={inputClass} value={st.prompt} disabled={readOnly} onChange={(e) => onChange({ ...st, prompt: e.target.value })} /></Labelled>
      {st.type !== 'choose' && (
        <div className="grid gap-3 sm:grid-cols-2">
          {pick(st.target, (v) => onChange({ ...st, target: v }), t(st.type === 'tap' ? 'ss.f.target' : 'ss.f.moves'))}
          {st.type === 'place' && pick(st.slot, (v) => onChange({ ...st, slot: v }), t('ss.f.slot'))}
        </div>
      )}
      {st.type === 'choose' && (
        <fieldset className="space-y-2">
          <legend className="mb-1 text-[13px] font-semibold text-ink">{t('ss.f.options')}</legend>
          {st.options.map((o, i) => (
            <div key={i} className="flex items-center gap-2">
              <input type="radio" aria-label={t('ss.f.right')} name={`ans-${st.id}`} className="size-4 accent-[var(--brand)]" checked={st.answer === i} disabled={readOnly} onChange={() => onChange({ ...st, answer: i })} />
              <input className={inputClass} value={o} disabled={readOnly} onChange={(e) => onChange({ ...st, options: st.options.map((x, j) => (j === i ? e.target.value : x)) })} />
              {!readOnly && st.options.length > 2 && <IconButton label={t('ls.remove')} onClick={() => onChange({ ...st, options: st.options.filter((_, j) => j !== i), answer: st.answer >= i && st.answer > 0 ? st.answer - 1 : st.answer })}><Trash2 className="size-4" /></IconButton>}
            </div>
          ))}
          {!readOnly && st.options.length < 5 && <Button size="sm" variant="ghost" icon={<Plus className="size-4" />} onClick={() => onChange({ ...st, options: [...st.options, ''] })}>{t('ss.addOption')}</Button>}
        </fieldset>
      )}
      <fieldset className="space-y-2">
        <legend className="mb-1 text-[13px] font-semibold text-ink">{t('ss.f.hints')} <span className="font-normal text-muted">{t('ss.hintsLead')}</span></legend>
        {[0, 1, 2].map((i) => <input key={i} aria-label={`${t('ss.f.hints')} ${i + 1}`} className={inputClass} value={st.hints[i] ?? ''} disabled={readOnly || i > st.hints.length} onChange={(e) => { const h = [...st.hints]; h[i] = e.target.value; onChange({ ...st, hints: h.filter((x, j) => x !== '' || j < i) }); }} />)}
      </fieldset>
      <Labelled label={t('ss.f.why')}><input className={inputClass} value={st.why} disabled={readOnly} onChange={(e) => onChange({ ...st, why: e.target.value })} /></Labelled>
      <div className="grid gap-3 sm:grid-cols-2">
        <Labelled label={t('ss.f.outcome')}><select className={inputClass} value={st.outcome ?? ''} disabled={readOnly} onChange={(e) => onChange({ ...st, outcome: e.target.value || null })}><option value="">—</option>{a.outcome_details.map((o) => <option key={o.code} value={o.code}>{o.code}</option>)}</select></Labelled>
        <Labelled label={t('ss.f.lesson')}><select className={inputClass} value={st.lesson?.code ?? ''} disabled={readOnly} onChange={(e) => onChange({ ...st, lesson: e.target.value ? { code: e.target.value, block: null } : null })}><option value="">—</option>{a.lessons.map((l) => <option key={l.code} value={l.code}>{`${l.code} · ${l.title}`}</option>)}</select></Labelled>
      </div>
      <label className="flex items-center gap-2 text-[13px] text-ink"><input type="checkbox" className="size-4 accent-[var(--brand)]" checked={st.critical} disabled={readOnly} onChange={(e) => onChange({ ...st, critical: e.target.checked })} />{t('ss.f.critical')}</label>
      {sc.variants.length > 1 && (
        <fieldset className="flex flex-wrap items-center gap-3 text-[13px]">
          <legend className="sr-only">{t('ss.f.only')}</legend>
          <span className="font-semibold text-ink">{t('ss.f.only')}</span>
          {sc.variants.map((v) => (
            <label key={v.id} className="flex items-center gap-1.5"><input type="checkbox" className="size-4 accent-[var(--brand)]" disabled={readOnly} checked={st.only.length === 0 || st.only.includes(v.id)}
              onChange={(e) => { const all = st.only.length === 0 ? sc.variants.map((x) => x.id) : st.only; const next = e.target.checked ? [...all, v.id] : all.filter((x) => x !== v.id); onChange({ ...st, only: next.length === sc.variants.length ? [] : next }); }} />{v.label || v.id}</label>
          ))}
        </fieldset>
      )}
      <StateRows label={t('ss.f.sets')} value={st.sets} objects={objects} obj={obj} readOnly={readOnly} t={t} onChange={(sets) => onChange({ ...st, sets })} />
      <fieldset className="space-y-2">
        <legend className="mb-1 text-[13px] font-semibold text-ink">{t('ss.f.wrong')} <span className="font-normal text-muted">{t('ss.wrongLead')}</span></legend>
        {st.wrong.map((w, i) => (
          <div key={i} className="grid gap-2 sm:grid-cols-[170px_170px_1fr_auto]">
            <select aria-label={t('ss.f.wrongOn')} className={inputClass} value={w.on} disabled={readOnly} onChange={(e) => onChange({ ...st, wrong: st.wrong.map((x, j) => (j === i ? { ...x, on: e.target.value } : x)) })}>
              <option value="*">{t('ss.anyOther')}</option>
              {(st.type === 'choose' ? onOptions : objects).map((o) => <option key={o} value={o}>{o.startsWith('option:') ? `${t('ss.f.option')} ${Number(o.slice(7)) + 1}` : obj(o)}</option>)}
            </select>
            <select aria-label={t('ss.f.effect')} className={inputClass} value={w.effect} disabled={readOnly} onChange={(e) => onChange({ ...st, wrong: st.wrong.map((x, j) => (j === i ? { ...x, effect: e.target.value as SimEffect } : x)) })}>
              {EFFECTS.map((ef) => <option key={ef} value={ef}>{t(`ss.effect.${ef}` as MessageKey)}</option>)}
            </select>
            <input aria-label={t('ss.f.message')} className={inputClass} value={w.message} placeholder={t('ss.messagePh')} disabled={readOnly} onChange={(e) => onChange({ ...st, wrong: st.wrong.map((x, j) => (j === i ? { ...x, message: e.target.value } : x)) })} />
            {!readOnly && <IconButton label={t('ls.remove')} onClick={() => onChange({ ...st, wrong: st.wrong.filter((_, j) => j !== i) })}><Trash2 className="size-4" /></IconButton>}
          </div>
        ))}
        {!readOnly && st.wrong.length < 6 && <Button size="sm" variant="ghost" icon={<Plus className="size-4" />} onClick={() => onChange({ ...st, wrong: [...st.wrong, { on: '*', effect: 'none', message: '' }] })}>{t('ss.addWrong')}</Button>}
      </fieldset>
    </div>
  );
}

/** Object → state rows (how a cause starts, or how the scene changes after a step). */
function StateRows({ label, value, objects, obj, onChange, readOnly, t }: { label: string; value: Record<string, string>; objects: string[]; obj: (id: string) => string; onChange: (v: Record<string, string>) => void; readOnly: boolean; t: T }) {
  const rows = Object.entries(value);
  const set = (i: number, k: string, v: string) => onChange(Object.fromEntries(rows.map((r, j) => (j === i ? [k, v] : r))));
  return (
    <fieldset className="mt-2 space-y-2">
      <legend className="mb-1 text-[13px] font-semibold text-ink">{label}</legend>
      {rows.map(([k, v], i) => (
        <div key={i} className="flex gap-2">
          <select aria-label={t('ss.f.object')} className={inputClass} value={k} disabled={readOnly} onChange={(e) => set(i, e.target.value, v)}>{objects.map((o) => <option key={o} value={o}>{obj(o)}</option>)}</select>
          <input aria-label={t('ss.f.state')} className={cx(inputClass, 'w-40')} value={v} placeholder="off" disabled={readOnly} onChange={(e) => set(i, k, e.target.value.toLowerCase().replace(/[^a-z-]/g, ''))} />
          {!readOnly && <IconButton label={t('ls.remove')} onClick={() => onChange(Object.fromEntries(rows.filter((_, j) => j !== i)))}><Trash2 className="size-4" /></IconButton>}
        </div>
      ))}
      {!readOnly && <Button size="sm" variant="ghost" icon={<Plus className="size-4" />} onClick={() => { const free = objects.find((o) => !(o in value)); if (free) onChange({ ...value, [free]: '' }); }}>{t('ss.addState')}</Button>}
    </fieldset>
  );
}

function TrRow({ label, en, value, onChange, readOnly }: { label: string; en: string; value: string; onChange: (v: string) => void; readOnly: boolean }) {
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

function IconButton({ label, onClick, disabled, children }: { label: string; onClick: () => void; disabled?: boolean; children: ReactNode }) {
  return <button type="button" aria-label={label} title={label} disabled={disabled} onClick={onClick} className="grid size-8 place-items-center rounded-lg text-muted hover:bg-surface-2 hover:text-ink disabled:opacity-30">{children}</button>;
}

