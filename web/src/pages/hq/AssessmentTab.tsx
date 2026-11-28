import { useState, type FormEvent } from 'react';
import { Link, useNavigate } from 'react-router';
import { useMutation, useQuery } from '@tanstack/react-query';
import { ClipboardCheck, Plus } from 'lucide-react';
import { KINDS_BY_COMPONENT, type AssessmentComponent, type AssessmentKind, type AssessmentPlan, type AssessmentTaskItem, type StudioActivity } from '@simuwork/core';
import type { MessageKey } from '@simuwork/core/locales/en';
import { Badge, Button, Card, cx, Drawer, EmptyState, Field, inputClass, Skeleton, useToast } from '../../components/ui';
import { api, ApiError } from '../../lib/api';
import { useI18n } from '../../lib/i18n';
import { can, useSession } from '../../lib/session';

const COMPONENTS: AssessmentComponent[] = ['knowledge', 'practical', 'workplace'];
const LETTER: Record<AssessmentComponent, string> = { knowledge: 'K', practical: 'P', workplace: 'W' };

/** HQ programme page → Assessment: the summative tasks per QCTO component and which outcomes they cover. */
export function AssessmentTab({ code, published }: { code: string; published: boolean }) {
  const { t } = useI18n();
  const { data: session } = useSession();
  const [adding, setAdding] = useState(false);
  const plan = useQuery({ queryKey: ['assessment-plan', code], queryFn: () => api<{ data: AssessmentPlan }>(`/platform/programmes/${code}/assessment`), enabled: published });
  if (!published) return <Card><EmptyState icon={<ClipboardCheck className="size-6" />} title={t('cs.notPublished')} body={t('cs.notPublishedBody')} /></Card>;
  if (!plan.data) return <Skeleton className="h-64 rounded-2xl" />;
  const p = plan.data.data;

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <p className="text-[14px] text-muted">{p.uncovered === 0 ? t('ap.allCovered') : t('ap.uncovered', { n: p.uncovered })}</p>
        {can(session, 'content.author') && <Button icon={<Plus className="size-4" />} onClick={() => setAdding(true)}>{t('ap.newTask')}</Button>}
      </div>
      <div className="grid gap-4 lg:grid-cols-3">
        {COMPONENTS.map((c) => (
          <Card key={c} className="p-0">
            <h3 className="border-b border-line px-4 py-3 text-[14px] font-bold text-ink">{t(`ap.component.${c}` as MessageKey)}</h3>
            {p.tasks.filter((x) => x.component === c).length === 0 ? <p className="px-4 py-6 text-center text-[13px] text-muted">{t('ap.noTasks')}</p> : (
              <ul className="divide-y divide-line">
                {p.tasks.filter((x) => x.component === c).map((x) => <TaskRow key={x.id} code={code} x={x} />)}
              </ul>
            )}
          </Card>
        ))}
      </div>
      <Card className="overflow-x-auto p-0">
        <h3 className="border-b border-line px-4 py-3 text-[14px] font-bold text-ink">{t('ap.coverage')}</h3>
        <table className="w-full text-[13px]">
          <tbody>
            {p.coverage.map((o) => (
              <tr key={o.code} className={cx('border-b border-line last:border-0', o.components.length === 0 && 'bg-[#FFF7E6]')}>
                <td className="w-24 px-4 py-2 font-mono font-semibold text-ink">{o.code}</td>
                <td className="px-2 py-2 text-ink">{o.statement}</td>
                <td className="w-28 px-4 py-2 text-right">
                  {COMPONENTS.map((c) => <span key={c} title={t(`ap.component.${c}` as MessageKey)} className={cx('ml-1 inline-grid size-6 place-items-center rounded-md text-[11px] font-bold', o.components.includes(c) ? 'bg-[#E6F4EC] text-[#1E7A46]' : 'bg-surface-2 text-muted')}>{LETTER[c]}</span>)}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </Card>
      {adding && <NewTask code={code} outcomes={p.coverage} onClose={() => setAdding(false)} />}
    </div>
  );
}

function TaskRow({ code, x }: { code: string; x: AssessmentTaskItem }) {
  const { t } = useI18n();
  return (
    <li>
      <Link to={`/hq/programmes/${code}/assessment/${x.id}`} className="block px-4 py-3 hover:bg-surface-2">
        <span className="flex items-center justify-between gap-2">
          <span className="font-semibold text-ink">{x.title}</span>
          <Badge tone={x.status === 'published' ? (x.has_draft ? 'warning' : 'success') : 'neutral'}>{t(x.status === 'published' ? (x.has_draft ? 'cs.status.changed' : 'cs.status.published') : 'cs.status.draft')}</Badge>
        </span>
        <span className="mt-0.5 block text-[12px] text-muted">{`${x.code} · ${t(`ap.kind.${x.kind}` as MessageKey)}${x.kind === 'test' ? ` · ${t('ap.questionsN', { n: x.questions })}` : ''} · ${x.outcomes.join(', ')}`}</span>
      </Link>
    </li>
  );
}

function NewTask({ code, outcomes, onClose }: { code: string; outcomes: AssessmentPlan['coverage']; onClose: () => void }) {
  const { t } = useI18n();
  const toast = useToast();
  const navigate = useNavigate();
  const [title, setTitle] = useState('');
  const [component, setComponent] = useState<AssessmentComponent>('knowledge');
  const [kind, setKind] = useState<AssessmentKind>('test');
  const [activity, setActivity] = useState('');
  const [picked, setPicked] = useState<string[]>([]);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const sims = useQuery({ queryKey: ['studio-activities', code], queryFn: () => api<{ data: { activities: StudioActivity[] } }>(`/platform/programmes/${code}/activities`) });
  const scenarios = sims.data?.data.activities.filter((a) => a.kind === 'scenario') ?? [];
  const save = useMutation({
    mutationFn: () => api<{ data: AssessmentTaskItem }>(`/platform/programmes/${code}/assessment-tasks`, { method: 'POST', body: { title, component, kind, activity_id: kind === 'simulator' ? activity || scenarios[0]?.id : null, outcomes: picked } }),
    onSuccess: (r) => void navigate(`/hq/programmes/${code}/assessment/${r.data.id}`),
    onError: (e) => {
      if (e instanceof ApiError && Object.keys(e.errors).length) setErrors(Object.fromEntries(Object.entries(e.errors).map(([k, v]) => [k.split('.')[0]!, v[0] ?? ''])));
      else toast(t('common.error'), 'error');
    },
  });
  const submit = (e: FormEvent) => { e.preventDefault(); setErrors({}); save.mutate(); };
  return (
    <Drawer open onClose={onClose} title={t('ap.newTask')} subtitle={t('ap.newLead')}
      footer={<div className="flex justify-end gap-2"><Button variant="secondary" onClick={onClose}>{t('common.cancel')}</Button><Button type="submit" form="new-task" loading={save.isPending}>{t('cs.create')}</Button></div>}>
      <form id="new-task" onSubmit={submit} className="space-y-4" noValidate>
        <Field label={t('cs.f.title')} value={title} onChange={(e) => setTitle(e.target.value)} error={errors.title} />
        <div className="grid grid-cols-2 gap-3">
          <label className="block"><span className="mb-1.5 block text-[13px] font-semibold text-ink">{t('ap.f.component')}</span>
            <select className={inputClass} value={component} onChange={(e) => { const c = e.target.value as AssessmentComponent; setComponent(c); setKind(KINDS_BY_COMPONENT[c][0]!); }}>
              {COMPONENTS.map((c) => <option key={c} value={c}>{t(`ap.component.${c}` as MessageKey)}</option>)}
            </select></label>
          <label className="block"><span className="mb-1.5 block text-[13px] font-semibold text-ink">{t('ap.f.kind')}</span>
            <select className={inputClass} value={kind} onChange={(e) => setKind(e.target.value as AssessmentKind)}>
              {KINDS_BY_COMPONENT[component].map((k) => <option key={k} value={k}>{t(`ap.kind.${k}` as MessageKey)}</option>)}
            </select></label>
        </div>
        {kind === 'simulator' && (
          <label className="block"><span className="mb-1.5 block text-[13px] font-semibold text-ink">{t('ap.f.simulator')}</span>
            <select className={inputClass} value={activity || scenarios[0]?.id || ''} onChange={(e) => setActivity(e.target.value)}>
              {scenarios.map((s) => <option key={s.id} value={s.id}>{`${s.code} · ${s.title}`}</option>)}
            </select>
            {errors.activity_id && <p role="alert" className="mt-1.5 text-[13px] font-medium text-[#C92A2F]">{errors.activity_id}</p>}
          </label>
        )}
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
