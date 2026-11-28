import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Copy, Link2, Share2, X } from 'lucide-react';
import type { ChecklistShareItem } from '@simuwork/core';
import { Button, Card, inputClass, useToast } from '../ui';
import { api } from '../../lib/api';
import { useI18n } from '../../lib/i18n';

/** The learner shares a read-only checklist link with a mentor or employer, and can stop it at any time. */
export function ShareCard({ eid }: { eid: string }) {
  const { t, locale } = useI18n();
  const toast = useToast();
  const qc = useQueryClient();
  const [label, setLabel] = useState('');
  const [made, setMade] = useState<string | null>(null);
  const list = useQuery({ queryKey: ['shares', eid], queryFn: () => api<{ data: ChecklistShareItem[] }>(`/learn/enrolments/${eid}/shares`) });
  const refresh = () => void qc.invalidateQueries({ queryKey: ['shares', eid] });
  const create = useMutation({
    mutationFn: () => api<{ data: ChecklistShareItem }>(`/learn/enrolments/${eid}/shares`, { method: 'POST', body: { label: label.trim() || null } }),
    onSuccess: (r) => { setMade(r.data.url ?? null); setLabel(''); refresh(); },
    onError: () => toast(t('common.error'), 'error'),
  });
  const stop = useMutation({ mutationFn: (id: string) => api(`/learn/shares/${id}`, { method: 'DELETE' }), onSuccess: () => { toast(t('jr.share.stopped')); setMade(null); refresh(); } });
  const copy = async (url: string) => {
    try {
      if (navigator.share) await navigator.share({ title: t('jr.title'), url });
      else { await navigator.clipboard.writeText(url); toast(t('jr.share.copied')); }
    } catch { /* the learner closed the share sheet */ }
  };
  const date = (iso: string) => new Date(iso).toLocaleDateString(locale, { day: 'numeric', month: 'short' });

  return (
    <Card className="p-5">
      <div className="flex items-center gap-2"><Share2 className="size-5 text-brand" /><h2 className="text-[16px] font-bold text-ink">{t('jr.share.title')}</h2></div>
      <p className="mt-1 text-[14px] text-muted">{t('jr.share.lead')}</p>
      <div className="mt-3 flex gap-2">
        <input aria-label={t('jr.share.label')} className={inputClass} value={label} placeholder={t('jr.share.labelPh')} maxLength={80} onChange={(e) => setLabel(e.target.value)} />
        <Button icon={<Link2 className="size-4" />} loading={create.isPending} onClick={() => create.mutate()}>{t('jr.share.make')}</Button>
      </div>
      {made && (
        <div className="mt-3 flex items-center gap-2 rounded-xl bg-brand-soft px-3 py-2">
          <code className="min-w-0 flex-1 truncate text-[13px] text-ink">{made}</code>
          <Button size="sm" variant="secondary" icon={<Copy className="size-4" />} onClick={() => void copy(made)}>{t('jr.share.send')}</Button>
        </div>
      )}
      {(list.data?.data.length ?? 0) > 0 && (
        <ul className="mt-4 divide-y divide-line rounded-xl border border-line text-[13px]">
          {list.data!.data.map((s) => (
            <li key={s.id} className="flex items-center gap-3 px-3 py-2">
              <span className="min-w-0 flex-1"><span className="block font-semibold text-ink">{s.label || t('jr.share.noLabel')}</span><span className="text-muted">{t('jr.share.meta', { views: s.views, date: date(s.expires_at) })}</span></span>
              <Button size="sm" variant="ghost" icon={<X className="size-4" />} onClick={() => stop.mutate(s.id)}>{t('jr.share.stop')}</Button>
            </li>
          ))}
        </ul>
      )}
    </Card>
  );
}
