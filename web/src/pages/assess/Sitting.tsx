import { useState } from 'react';
import { useParams, Link } from 'react-router';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft } from 'lucide-react';
import type { SittingReview } from '@simuwork/core';
import { Badge, Button, Card, cx, inputClass, PageHeader, Skeleton, useToast } from '../../components/ui';
import { API_BASE, api } from '../../lib/api';
import { useI18n } from '../../lib/i18n';

/** Mark the written answers of a test, beside the model answer; camera-check photos are shown small. */
export default function SittingMark() {
  const { id = '' } = useParams();
  const { t, locale } = useI18n();
  const toast = useToast();
  const qc = useQueryClient();
  const q = useQuery({ queryKey: ['assess-sitting', id], queryFn: () => api<{ data: SittingReview }>(`/sittings/${id}`) });
  const [marks, setMarks] = useState<Record<string, number>>({});
  const s = q.data?.data;
  const save = useMutation({
    mutationFn: () => api(`/sittings/${id}/mark`, { method: 'POST', body: { marks } }),
    onSuccess: () => { toast(t('rv.saved')); void qc.invalidateQueries({ queryKey: ['assess-sitting', id] }); void qc.invalidateQueries({ queryKey: ['assess-queue'] }); },
    onError: () => toast(t('common.error'), 'error'),
  });
  if (!s) return <Skeleton className="h-96 max-w-4xl" />;
  const short = s.questions.filter((x) => x.type === 'short');
  const show = (a: string | string[] | null) => (Array.isArray(a) ? a.join(', ') : a ?? '—');
  return (
    <div className="sw-in max-w-4xl space-y-4">
      <Link to="/assess" className="inline-flex items-center gap-1 text-[13px] font-semibold text-brand"><ArrowLeft className="size-4" />{t('rv.back')}</Link>
      <PageHeader title={s.learner} lead={s.task.title} />
      <Card className="flex flex-wrap items-center gap-3 p-4">
        {s.status === 'marked' ? <Badge tone={s.result === 'competent' ? 'success' : 'warning'}>{t(s.result === 'competent' ? 'rv.competent' : 'rv.notYet')} · {s.score}%</Badge> : <Badge>{t('rv.toMark')}</Badge>}
        {s.late && <Badge tone="warning">{t('rv.late')}</Badge>}
        {s.camera && (
          <div className="ml-auto flex items-center gap-2" aria-label={t('rv.photos')}>
            {s.photos.length === 0 ? <span className="text-[12px] text-muted">{t('rv.noPhotos')}</span> : s.photos.map((p) => (
              <img key={p.id} src={`${API_BASE}/sittings/${id}/photos/${p.id}`} alt={t('rv.photoAt', { time: new Date(p.taken_at).toLocaleTimeString(locale) })} className="h-12 w-16 rounded-md object-cover" loading="lazy" />
            ))}
          </div>
        )}
      </Card>
      {s.questions.map((x, i) => (
        <Card key={x.id} className="p-4">
          <p className="font-semibold text-ink">{i + 1}. {x.prompt} <span className="text-[12px] font-normal text-muted">({t('rv.marks', { n: x.marks })})</span></p>
          <div className="mt-2 grid gap-2 text-[14px] sm:grid-cols-2">
            <div><p className="text-[12px] font-semibold text-muted">{t('rv.learnerAnswer')}</p><p className="whitespace-pre-wrap text-ink">{show(x.answer)}</p></div>
            <div><p className="text-[12px] font-semibold text-muted">{t(x.type === 'short' ? 'rv.modelAnswer' : 'rv.key')}</p><p className="whitespace-pre-wrap text-ink">{show(x.key)}</p></div>
          </div>
          {x.type === 'short' && s.status === 'submitted' ? (
            <label className="mt-3 flex items-center gap-2 text-[13px] font-semibold text-ink">{t('rv.award')}
              <input type="number" min={0} max={x.marks} className={cx(inputClass, 'h-9 w-20')} value={marks[x.id] ?? ''} onChange={(e) => setMarks({ ...marks, [x.id]: Math.max(0, Math.min(x.marks, Number(e.target.value))) })} />
              <span className="font-normal text-muted">/ {x.marks}</span>
            </label>
          ) : <p className="mt-2 text-[13px] text-muted">{t('rv.awarded', { n: x.awarded ?? 0, of: x.marks })}</p>}
        </Card>
      ))}
      {s.status === 'submitted' && <Button disabled={short.some((x) => marks[x.id] === undefined)} loading={save.isPending} onClick={() => save.mutate()}>{t('rv.finishMarking')}</Button>}
    </div>
  );
}
