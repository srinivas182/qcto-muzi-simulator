import { useState, type FormEvent } from 'react';
import { Link, useNavigate } from 'react-router';
import { useMutation, useQuery } from '@tanstack/react-query';
import { Box, BookOpen, Plus } from 'lucide-react';
import type { ContentLocale, StudioActivity } from '@simuwork/core';
import { Badge, Button, Card, cx, Drawer, EmptyState, Field, Skeleton, useToast } from '../../components/ui';
import { api, ApiError } from '../../lib/api';
import { useI18n } from '../../lib/i18n';
import { can, useSession } from '../../lib/session';

type Index = { version: number; activities: StudioActivity[]; outcomes: { code: string; statement: string }[] };
const LOCALES: ContentLocale[] = ['en', 'zu', 'xh', 'af'];

/** HQ programme page → Content: the lessons and simulator scenarios of the published version. */
export function ContentTab({ code, published }: { code: string; published: boolean }) {
  const { t } = useI18n();
  const { data: session } = useSession();
  const [adding, setAdding] = useState<'lesson' | 'scenario' | null>(null);
  const list = useQuery({ queryKey: ['studio-activities', code], queryFn: () => api<{ data: Index }>(`/platform/programmes/${code}/activities`), enabled: published });
  const author = can(session, 'content.author');

  if (!published) return <Card><EmptyState icon={<BookOpen className="size-6" />} title={t('cs.notPublished')} body={t('cs.notPublishedBody')} /></Card>;
  if (!list.data) return <Skeleton className="h-64 rounded-2xl" />;
  const items = list.data.data.activities;

  return (
    <div>
      <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
        <p className="text-[14px] text-muted">{t('cs.lead', { n: list.data.data.version })}</p>
        {author && (
          <div className="flex gap-2">
            <Button variant="secondary" icon={<Box className="size-4" />} onClick={() => setAdding('scenario')}>{t('cs.newScenario')}</Button>
            <Button icon={<Plus className="size-4" />} onClick={() => setAdding('lesson')}>{t('cs.newLesson')}</Button>
          </div>
        )}
      </div>
      {items.length === 0 ? <Card><EmptyState icon={<BookOpen className="size-6" />} title={t('cs.none')} body={t('cs.noneBody')} /></Card> : (
        <Card className="overflow-hidden p-0">
          <ul className="divide-y divide-line">
            {items.map((a) => (
              <li key={a.id}>
                <Link to={a.kind === 'lesson' ? `/hq/programmes/${code}/lessons/${a.id}` : '#'} className={cx('flex flex-wrap items-center gap-3 px-5 py-4', a.kind === 'lesson' ? 'hover:bg-surface-2' : 'cursor-default')}>
                  <span className={cx('flex size-10 shrink-0 items-center justify-center rounded-xl', a.kind === 'lesson' ? 'bg-brand-soft text-brand' : 'bg-nav text-white')}>{a.kind === 'lesson' ? <BookOpen className="size-5" /> : <Box className="size-5" />}</span>
                  <span className="min-w-0 flex-1">
                    <span className="block font-semibold text-ink">{a.title}</span>
                    <span className="block text-[12px] text-muted">{a.code} · {t('cs.minutes', { n: a.minutes })} · {a.outcomes.join(', ')}{a.kind === 'scenario' ? ` · ${t('cs.scenarioLater')}` : ''}</span>
                  </span>
                  <span className="flex gap-1">
                    {LOCALES.map((l) => <span key={l} title={t(`cs.review.${a.review[l] ?? 'none'}` as 'cs.review.none')} className={cx('rounded-md px-1.5 py-0.5 font-mono text-[11px] font-bold uppercase', a.review[l] === 'reviewed' ? 'bg-[#E6F4EC] text-[#1E7A46]' : a.review[l] === 'ai' ? 'bg-[#FFF4E0] text-[#9A5B00]' : 'bg-surface-2 text-muted')}>{l}</span>)}
                  </span>
                  <Badge tone={a.status === 'published' ? (a.has_draft ? 'warning' : 'success') : 'neutral'}>{t(a.status === 'published' ? (a.has_draft ? 'cs.status.changed' : 'cs.status.published') : 'cs.status.draft')}</Badge>
                </Link>
              </li>
            ))}
          </ul>
        </Card>
      )}
      {adding && <NewActivity code={code} kind={adding} outcomes={list.data.data.outcomes} onClose={() => setAdding(null)} />}
    </div>
  );
}

function NewActivity({ code, kind, outcomes, onClose }: { code: string; kind: 'lesson' | 'scenario'; outcomes: { code: string; statement: string }[]; onClose: () => void }) {
  const { t } = useI18n();
  const toast = useToast();
  const navigate = useNavigate();
  const [title, setTitle] = useState('');
  const [summary, setSummary] = useState('');
  const [minutes, setMinutes] = useState(kind === 'lesson' ? '4' : '10');
  const [picked, setPicked] = useState<string[]>([]);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const save = useMutation({
    mutationFn: () => api<{ data: StudioActivity }>(`/platform/programmes/${code}/activities`, { method: 'POST', body: { kind, title, summary: summary || null, minutes: Number(minutes) || 5, outcomes: picked } }),
    onSuccess: (r) => {
      toast(t('cs.created'));
      if (kind === 'lesson') void navigate(`/hq/programmes/${code}/lessons/${r.data.id}`);
      else onClose();
    },
    onError: (e) => {
      if (e instanceof ApiError && Object.keys(e.errors).length) setErrors(Object.fromEntries(Object.entries(e.errors).map(([k, v]) => [k.split('.')[0]!, v[0] ?? ''])));
      else toast(t('common.error'), 'error');
    },
  });
  const submit = (e: FormEvent) => { e.preventDefault(); setErrors({}); save.mutate(); };
  return (
    <Drawer open onClose={onClose} title={t(kind === 'lesson' ? 'cs.newLesson' : 'cs.newScenario')} subtitle={t('cs.newLead')}
      footer={<div className="flex justify-end gap-2"><Button variant="secondary" onClick={onClose}>{t('common.cancel')}</Button><Button type="submit" form="new-activity" loading={save.isPending}>{t('cs.create')}</Button></div>}>
      <form id="new-activity" onSubmit={submit} className="space-y-4" noValidate>
        <Field label={t('cs.f.title')} value={title} onChange={(e) => setTitle(e.target.value)} error={errors.title} placeholder={t(kind === 'lesson' ? 'cs.f.titlePh' : 'cs.f.titlePhS')} />
        <Field label={t('cs.f.summary')} value={summary} onChange={(e) => setSummary(e.target.value)} hint={t('cs.f.summaryHint')} />
        <div className="w-32"><Field label={t('cs.f.minutes')} type="number" min={1} max={120} value={minutes} onChange={(e) => setMinutes(e.target.value)} /></div>
        <fieldset>
          <legend className="mb-1.5 text-[13px] font-semibold text-ink">{t('cs.f.outcomes')}</legend>
          <div className="max-h-72 space-y-1 overflow-y-auto rounded-xl border border-line p-2">
            {outcomes.map((o) => (
              <label key={o.code} className="flex cursor-pointer gap-2.5 rounded-lg px-2 py-1.5 text-[13px] hover:bg-surface-2">
                <input type="checkbox" className="mt-0.5 size-4 accent-[var(--brand)]" checked={picked.includes(o.code)} onChange={(e) => setPicked((x) => (e.target.checked ? [...x, o.code] : x.filter((c) => c !== o.code)))} />
                <span><span className="font-mono font-semibold">{o.code}</span> {o.statement}</span>
              </label>
            ))}
          </div>
          {errors.outcomes && <p role="alert" className="mt-1.5 text-[13px] font-medium text-[#C92A2F]">{errors.outcomes}</p>}
        </fieldset>
      </form>
    </Drawer>
  );
}
