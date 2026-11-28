import { useMemo, useState, type ReactNode } from 'react';
import { Link, useParams } from 'react-router';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowDown, ArrowLeft, ArrowUp, CheckCircle2, Image as ImageIcon, Languages, Plus, Save, Sparkles, Trash2, Upload } from 'lucide-react';
import { localiseBlocks, type ContentAssetItem, type ContentLocale, type LessonBlock, type LessonBlockType, type LessonI18n, type StudioActivityDetail } from '@simuwork/core';
import type { MessageKey } from '@simuwork/core/locales/en';
import { LessonView } from '../../components/lesson/LessonView';
import { Badge, Button, Card, cx, inputClass, Skeleton, useToast } from '../../components/ui';
import { api, ApiError } from '../../lib/api';
import { useI18n } from '../../lib/i18n';
import { can, useSession } from '../../lib/session';

const LOCALES: ContentLocale[] = ['en', 'zu', 'xh', 'af'];
const TYPES: LessonBlockType[] = ['narration', 'text', 'keypoints', 'image', 'check', 'video', 'try'];
const newId = () => Math.random().toString(36).slice(2, 10).padEnd(8, '0');

function blank(type: LessonBlockType): LessonBlock {
  const id = newId();
  switch (type) {
    case 'text': return { id, type, body: '' };
    case 'keypoints': return { id, type, items: [''] };
    case 'image': return { id, type, asset_id: '', alt: '' };
    case 'narration': return { id, type, script: '', audio_asset_id: null };
    case 'video': return { id, type, asset_id: '', low_asset_id: null, text: '' };
    case 'check': return { id, type, prompt: '', options: ['', ''], answer: 0, explain: '', outcome: null };
    case 'try': return { id, type, activity_code: '' };
  }
}

const errorText = (e: unknown, fallback: string) => (e instanceof ApiError ? Object.values(e.errors).flat().join(' ') || e.message : fallback);

/** HQ Course Studio: edit a lesson's blocks, draft with AI, translate, review and publish — with a live phone preview. */
export default function LessonStudio() {
  const { id = '', code = '' } = useParams();
  const { t } = useI18n();
  const detail = useQuery({ queryKey: ['studio-activity', id], queryFn: () => api<{ data: StudioActivityDetail }>(`/platform/activities/${id}`) });
  if (!detail.data) return <div className="space-y-4"><Skeleton className="h-10 w-80" /><Skeleton className="h-[480px] rounded-2xl" /></div>;
  return <Editor key={detail.dataUpdatedAt} code={code} a={detail.data.data} t={t} />;
}

function Editor({ code, a, t }: { code: string; a: StudioActivityDetail; t: ReturnType<typeof useI18n>['t'] }) {
  const toast = useToast();
  const qc = useQueryClient();
  const { data: session } = useSession();
  const [blocks, setBlocks] = useState<LessonBlock[]>(a.draft.blocks);
  const [i18n, setI18n] = useState<LessonI18n>(a.draft.i18n);
  const [assets, setAssets] = useState<Record<string, ContentAssetItem>>(a.assets);
  const [locale, setLocale] = useState<ContentLocale>('en');
  const [adding, setAdding] = useState(false);
  const dirty = JSON.stringify(blocks) !== JSON.stringify(a.draft.blocks) || JSON.stringify(i18n) !== JSON.stringify(a.draft.i18n);
  const author = can(session, 'content.author');
  const reviewer = can(session, 'content.review');
  const publisher = can(session, 'content.publish');
  const scenarios = useMemo(() => Object.fromEntries(a.scenarios.map((s) => [s.code, s.title])), [a.scenarios]);

  const done = (r: { data: StudioActivityDetail }, msg?: string) => {
    qc.setQueryData(['studio-activity', a.id], r);
    void qc.invalidateQueries({ queryKey: ['studio-activities', code] });
    if (msg) toast(msg);
  };
  const call = useMutation({
    mutationFn: ({ path, body, method = 'POST' }: { path: string; body?: unknown; method?: 'POST' | 'PATCH'; msg?: string }) => api<{ data: StudioActivityDetail }>(`/platform/activities/${a.id}${path}`, { method, body }),
    onSuccess: (r, v) => done(r, v.msg),
    onError: (e) => toast(errorText(e, t('common.error')), 'error'),
  });
  const save = () => call.mutate({ path: '', method: 'PATCH', body: { blocks, i18n }, msg: t('ls.saved') });
  const guard = (fn: () => void) => () => (dirty ? toast(t('ls.saveFirst'), 'error') : fn());

  const setBlock = (i: number, b: LessonBlock) => setBlocks((all) => all.map((x, j) => (j === i ? b : x)));
  const move = (i: number, d: -1 | 1) => setBlocks((all) => { const n = [...all]; const [x] = n.splice(i, 1); n.splice(i + d, 0, x!); return n; });
  const setTr = (blockId: string, field: string, value: string | string[]) =>
    setI18n((all) => ({ ...all, [locale]: { ...(all[locale as 'zu'] ?? {}), [blockId]: { ...(all[locale as 'zu']?.[blockId] ?? {}), [field]: value } } }));

  const review = a.draft.review[locale];
  const preview = localiseBlocks(blocks, i18n, locale);

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
          </div>
        </div>
        <div className="flex flex-wrap gap-2">
          {author && a.ai_ready && locale === 'en' && <Button variant="secondary" icon={<Sparkles className="size-4" />} loading={call.isPending && call.variables?.path === '/ai-draft'} onClick={() => (blocks.length && !window.confirm(t('ls.aiReplace')) ? undefined : call.mutate({ path: '/ai-draft', msg: t('ls.aiDone') }))}>{t('ls.aiDraft')}</Button>}
          {author && a.ai_ready && locale !== 'en' && <Button variant="secondary" icon={<Languages className="size-4" />} loading={call.isPending && call.variables?.path === '/translate'} onClick={guard(() => call.mutate({ path: '/translate', body: { locales: [locale] }, msg: t('ls.translated') }))}>{t('ls.aiTranslate')}</Button>}
          {author && <Button variant="secondary" icon={<Save className="size-4" />} disabled={!dirty} loading={call.isPending && call.variables?.method === 'PATCH'} onClick={save}>{t('common.save')}</Button>}
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
            {review?.by ? t('ls.reviewedBy', { name: review.by }) : t('ls.markReviewed')}
          </label>
        )}
      </div>
      {locale !== 'en' && <p className="mb-4 rounded-xl bg-surface-2 px-4 py-2.5 text-[13px] text-muted">{t('ls.translationNote')}</p>}
      {a.problems.length > 0 && !dirty && <div role="alert" className="mb-4 rounded-xl border border-[#F5C26B] bg-[#FFF7E6] px-4 py-3 text-[13px] text-[#7A4A00] dark:bg-[#9A5B00]/20 dark:text-[#F5C26B]"><p className="font-semibold">{t('ls.beforePublish')}</p><ul className="mt-1 list-disc pl-5">{a.problems.map((p) => <li key={p}>{p}</li>)}</ul></div>}

      <div className="grid gap-6 xl:grid-cols-[1fr_400px]">
        <div className="space-y-3">
          {blocks.length === 0 && <Card className="p-8 text-center text-[14px] text-muted">{t(a.ai_ready ? 'ls.emptyAi' : 'ls.empty')}</Card>}
          {blocks.map((b, i) => (
            <Card key={b.id} className="p-4">
              <div className="mb-3 flex items-center justify-between gap-2">
                <span className="text-[12px] font-bold uppercase tracking-wide text-muted">{i + 1}. {t(`ls.type.${b.type}` as MessageKey)}</span>
                {author && locale === 'en' && (
                  <span className="flex gap-1">
                    <IconButton label={t('ls.up')} disabled={i === 0} onClick={() => move(i, -1)}><ArrowUp className="size-4" /></IconButton>
                    <IconButton label={t('ls.down')} disabled={i === blocks.length - 1} onClick={() => move(i, 1)}><ArrowDown className="size-4" /></IconButton>
                    <IconButton label={t('ls.remove')} onClick={() => setBlocks((all) => all.filter((_, j) => j !== i))}><Trash2 className="size-4" /></IconButton>
                  </span>
                )}
              </div>
              {locale === 'en'
                ? <BlockEditor b={b} onChange={(x) => setBlock(i, x)} readOnly={!author} assets={assets} onAsset={(x) => setAssets((all) => ({ ...all, [x.id]: x }))} scenarios={a.scenarios} outcomes={a.outcome_details} />
                : <TranslationEditor b={b} tr={i18n[locale as 'zu']?.[b.id] ?? {}} onChange={(f, v) => setTr(b.id, f, v)} readOnly={!author} />}
            </Card>
          ))}
          {author && locale === 'en' && (adding ? (
            <Card className="flex flex-wrap gap-2 p-4">
              {TYPES.map((ty) => <Button key={ty} size="sm" variant="secondary" onClick={() => { setBlocks((all) => [...all, blank(ty)]); setAdding(false); }}>{t(`ls.type.${ty}` as MessageKey)}</Button>)}
            </Card>
          ) : <Button variant="secondary" icon={<Plus className="size-4" />} onClick={() => setAdding(true)}>{t('ls.addBlock')}</Button>)}
        </div>

        <div className="xl:sticky xl:top-20 xl:self-start">
          <p className="mb-2 text-center text-[12px] font-semibold uppercase tracking-wide text-muted">{t('ls.preview')}</p>
          <div className="mx-auto w-full max-w-[380px] rounded-[2.2rem] border-[10px] border-nav bg-surface p-4 shadow-xl">
            <div className="max-h-[640px] overflow-y-auto pr-1">
              <h2 className="mb-3 text-[19px] font-bold text-ink">{a.title}</h2>
              <LessonView blocks={preview} assets={assets} scenarios={scenarios} />
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}

function IconButton({ label, disabled, onClick, children }: { label: string; disabled?: boolean; onClick: () => void; children: ReactNode }) {
  return <button type="button" aria-label={label} title={label} disabled={disabled} onClick={onClick} className="flex size-8 items-center justify-center rounded-lg text-muted hover:bg-surface-2 hover:text-ink disabled:opacity-30">{children}</button>;
}

function Text({ label, value, onChange, rows, readOnly, placeholder }: { label: string; value: string; onChange: (v: string) => void; rows?: number; readOnly?: boolean; placeholder?: string }) {
  return (
    <label className="block">
      <span className="mb-1 block text-[12px] font-semibold text-ink">{label}</span>
      {rows ? <textarea rows={rows} value={value} readOnly={readOnly} placeholder={placeholder} onChange={(e) => onChange(e.target.value)} className={cx(inputClass, 'h-auto py-2.5 leading-relaxed')} />
        : <input value={value} readOnly={readOnly} placeholder={placeholder} onChange={(e) => onChange(e.target.value)} className={inputClass} />}
    </label>
  );
}

function Upload_({ kind, label, assetId, assets, onUploaded, readOnly }: { kind: 'image' | 'audio' | 'video'; label: string; assetId: string | null; assets: Record<string, ContentAssetItem>; onUploaded: (a: ContentAssetItem) => void; readOnly?: boolean }) {
  const { t } = useI18n();
  const toast = useToast();
  const up = useMutation({
    mutationFn: (file: File) => { const f = new FormData(); f.append('kind', kind); f.append('file', file); return api<{ data: ContentAssetItem }>('/platform/content-assets', { method: 'POST', body: f }); },
    onSuccess: (r) => onUploaded(r.data),
    onError: (e) => toast(errorText(e, t('common.error')), 'error'),
  });
  const current = assetId ? assets[assetId] : undefined;
  return (
    <div className="flex items-center gap-3">
      {kind === 'image' && (current ? <img src={current.url} alt="" className="size-14 rounded-lg object-cover" /> : <span className="flex size-14 items-center justify-center rounded-lg bg-surface-2 text-muted"><ImageIcon className="size-5" /></span>)}
      <div className="min-w-0 flex-1 text-[13px]"><p className="font-semibold text-ink">{label}</p><p className="truncate text-muted">{current ? `${current.name ?? ''} · ${Math.round(current.bytes / 1024)} KB` : t('ls.noFile')}</p></div>
      {!readOnly && (
        <label className={cx('inline-flex h-9 cursor-pointer items-center gap-1.5 rounded-xl border border-line px-3 text-[13px] font-semibold text-ink hover:bg-surface-2', up.isPending && 'opacity-50')}>
          <Upload className="size-4" />{t(up.isPending ? 'ls.uploading' : 'ls.upload')}
          <input type="file" className="sr-only" accept={`${kind}/*`} onChange={(e) => { const f = e.target.files?.[0]; if (f) up.mutate(f); }} />
        </label>
      )}
    </div>
  );
}

function BlockEditor({ b, onChange, readOnly, assets, onAsset, scenarios, outcomes }: {
  b: LessonBlock; onChange: (b: LessonBlock) => void; readOnly: boolean; assets: Record<string, ContentAssetItem>; onAsset: (a: ContentAssetItem) => void;
  scenarios: { code: string; title: string }[]; outcomes: { code: string; statement: string }[];
}) {
  const { t } = useI18n();
  switch (b.type) {
    case 'text': return <Text label={t('ls.f.body')} rows={5} value={b.body} readOnly={readOnly} onChange={(body) => onChange({ ...b, body })} />;
    case 'narration': return (
      <div className="space-y-3">
        <Text label={t('ls.f.script')} rows={3} value={b.script} readOnly={readOnly} onChange={(script) => onChange({ ...b, script })} />
        <Upload_ kind="audio" label={t('ls.f.audio')} assetId={b.audio_asset_id} assets={assets} readOnly={readOnly} onUploaded={(x) => { onAsset(x); onChange({ ...b, audio_asset_id: x.id }); }} />
      </div>
    );
    case 'keypoints': return (
      <div className="space-y-2">
        {b.items.map((it, i) => <Text key={i} label={t('ls.f.point', { n: i + 1 })} value={it} readOnly={readOnly} onChange={(v) => onChange({ ...b, items: b.items.map((x, j) => (j === i ? v : x)) })} />)}
        {!readOnly && b.items.length < 6 && <Button size="sm" variant="ghost" icon={<Plus className="size-4" />} onClick={() => onChange({ ...b, items: [...b.items, ''] })}>{t('ls.addPoint')}</Button>}
      </div>
    );
    case 'image': return (
      <div className="space-y-3">
        <Upload_ kind="image" label={t('ls.f.picture')} assetId={b.asset_id || null} assets={assets} readOnly={readOnly} onUploaded={(x) => { onAsset(x); onChange({ ...b, asset_id: x.id }); }} />
        <Text label={t('ls.f.alt')} value={b.alt} readOnly={readOnly} onChange={(alt) => onChange({ ...b, alt })} placeholder={t('ls.f.altPh')} />
      </div>
    );
    case 'video': return (
      <div className="space-y-3">
        <Upload_ kind="video" label={t('ls.f.video')} assetId={b.asset_id || null} assets={assets} readOnly={readOnly} onUploaded={(x) => { onAsset(x); onChange({ ...b, asset_id: x.id }); }} />
        <Upload_ kind="video" label={t('ls.f.videoLow')} assetId={b.low_asset_id} assets={assets} readOnly={readOnly} onUploaded={(x) => { onAsset(x); onChange({ ...b, low_asset_id: x.id }); }} />
        <Text label={t('ls.f.videoText')} rows={3} value={b.text} readOnly={readOnly} onChange={(text) => onChange({ ...b, text })} />
      </div>
    );
    case 'check': return (
      <div className="space-y-3">
        <Text label={t('ls.f.question')} value={b.prompt} readOnly={readOnly} onChange={(prompt) => onChange({ ...b, prompt })} />
        <fieldset className="space-y-2">
          <legend className="mb-1 text-[12px] font-semibold text-ink">{t('ls.f.answers')}</legend>
          {b.options.map((o, i) => (
            <div key={i} className="flex items-center gap-2">
              <input type="radio" name={`ans-${b.id}`} aria-label={t('ls.f.correct', { n: i + 1 })} checked={b.answer === i} disabled={readOnly} onChange={() => onChange({ ...b, answer: i })} className="size-4 accent-[var(--brand)]" />
              <input value={o} readOnly={readOnly} aria-label={t('ls.f.answer', { n: i + 1 })} onChange={(e) => onChange({ ...b, options: b.options.map((x, j) => (j === i ? e.target.value : x)) })} className={inputClass} />
            </div>
          ))}
          {!readOnly && b.options.length < 5 && <Button size="sm" variant="ghost" icon={<Plus className="size-4" />} onClick={() => onChange({ ...b, options: [...b.options, ''] })}>{t('ls.addAnswer')}</Button>}
        </fieldset>
        <Text label={t('ls.f.hint')} rows={1} value={b.hint ?? ''} readOnly={readOnly} onChange={(hint) => onChange({ ...b, hint })} />
        <Text label={t('ls.f.explain')} rows={2} value={b.explain} readOnly={readOnly} onChange={(explain) => onChange({ ...b, explain })} />
        <label className="block">
          <span className="mb-1 block text-[12px] font-semibold text-ink">{t('ls.f.outcome')}</span>
          <select value={b.outcome ?? ''} disabled={readOnly} onChange={(e) => onChange({ ...b, outcome: e.target.value || null })} className={inputClass}>
            <option value="">—</option>
            {outcomes.map((o) => <option key={o.code} value={o.code}>{o.code} {o.statement.slice(0, 70)}</option>)}
          </select>
        </label>
      </div>
    );
    case 'try': return (
      <label className="block">
        <span className="mb-1 block text-[12px] font-semibold text-ink">{t('ls.f.simulator')}</span>
        <select value={b.activity_code} disabled={readOnly} onChange={(e) => onChange({ ...b, activity_code: e.target.value })} className={inputClass}>
          <option value="">{t('ls.f.choose')}</option>
          {scenarios.map((s) => <option key={s.code} value={s.code}>{s.code} — {s.title}</option>)}
        </select>
      </label>
    );
  }
}

const FIELDS: Record<LessonBlockType, string[]> = { text: ['body'], keypoints: ['items'], image: ['alt'], narration: ['script'], video: ['text'], check: ['prompt', 'options', 'hint', 'explain'], try: [] };

function TranslationEditor({ b, tr, onChange, readOnly }: { b: LessonBlock; tr: Record<string, string | string[]>; onChange: (field: string, v: string | string[]) => void; readOnly: boolean }) {
  const { t } = useI18n();
  const fields = FIELDS[b.type];
  if (fields.length === 0) return <p className="text-[13px] text-muted">{t('ls.nothingToTranslate')}</p>;
  const src = b as unknown as Record<string, string | string[]>;
  return (
    <div className="space-y-3">
      {fields.map((f) => {
        const source = src[f];
        if (source === undefined || source === '') return null; // optional field left empty in English (e.g. a check's hint)
        if (Array.isArray(source)) {
          const cur = (tr[f] as string[] | undefined) ?? [];
          return source.map((s, i) => (
            <div key={`${f}${i}`}>
              <p className="mb-1 text-[12px] text-muted">{s}</p>
              <input value={cur[i] ?? ''} readOnly={readOnly} aria-label={`${f} ${i + 1}`} onChange={(e) => { const n = [...source.map((_, j) => cur[j] ?? '')]; n[i] = e.target.value; onChange(f, n); }} className={inputClass} />
            </div>
          ));
        }
        return (
          <div key={f}>
            <p className="mb-1 text-[12px] text-muted">{source}</p>
            <textarea rows={Math.min(5, Math.ceil(source.length / 60) + 1)} value={(tr[f] as string | undefined) ?? ''} readOnly={readOnly} aria-label={f} onChange={(e) => onChange(f, e.target.value)} className={cx(inputClass, 'h-auto py-2.5')} />
          </div>
        );
      })}
    </div>
  );
}
