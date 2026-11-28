import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { LogbookView } from '@simuwork/core';
import { ApiError, api } from '../lib/api';
import { useI18n } from '../lib/i18n';
import { Button, cx, Drawer, inputClass, Skeleton, useToast } from './ui';

type Form = { employer: string; site: string; mentor_name: string; mentor_phone: string; confirm_by: 'link' | 'account'; starts_on: string; ends_on: string };

/** Staff: a learner's workplace placement and mentor, and how their logbook stands (Sprint 5.3). */
export function WorkplaceDrawer({ enrolmentId, learner, onClose }: { enrolmentId: string; learner: string; onClose: () => void }) {
  const { t } = useI18n();
  const toast = useToast();
  const qc = useQueryClient();
  const q = useQuery({ queryKey: ['staff-logbook', enrolmentId], queryFn: () => api<{ data: LogbookView }>(`/enrolments/${enrolmentId}/logbook`) });
  const [edit, setEdit] = useState<Form | null>(null);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const w = q.data?.data.workplace;
  const f: Form = edit ?? { employer: w?.employer ?? '', site: w?.site ?? '', mentor_name: w?.mentor_name ?? '', mentor_phone: w?.mentor_phone ?? '', confirm_by: 'link', starts_on: w?.starts_on ?? '', ends_on: w?.ends_on ?? '' };
  const set = (k: keyof Form, val: string) => setEdit({ ...f, [k]: val });
  const save = useMutation({
    mutationFn: () => api(`/enrolments/${enrolmentId}/workplace`, { method: 'PUT', body: { ...f, site: f.site || null, ends_on: f.ends_on || null } }),
    onSuccess: () => { void qc.invalidateQueries({ queryKey: ['staff-logbook', enrolmentId] }); toast(t('wp.saved')); onClose(); },
    onError: (e) => (e instanceof ApiError ? setErrors(e.errors) : toast(t('common.error'), 'error')),
  });
  const s = q.data?.data.summary;
  const field = (k: keyof Form, label: string, type = 'text') => (
    <label className="block text-[13px] font-semibold text-ink">{label}
      <input type={type} className={cx(inputClass, 'mt-1')} value={f[k]} onChange={(e) => set(k, e.target.value)} aria-invalid={!!errors[k]} />
      {errors[k] && <span className="mt-1 block text-[12px] font-medium text-[#C92A2F]">{errors[k][0]}</span>}
    </label>
  );
  return (
    <Drawer open onClose={onClose} title={t('wp.title', { name: learner })} subtitle={t('wp.lead')}
      footer={<Button loading={save.isPending} onClick={() => { setErrors({}); save.mutate(); }}>{t('wp.save')}</Button>}>
      {!q.data ? <Skeleton className="h-64" /> : (
        <div className="space-y-4">
          {s && w && <p className="rounded-xl bg-surface-2 p-3 text-[13px] text-ink">{t('wp.summary', { days: s.confirmed_days, min: s.min_days ?? '—', hours: s.confirmed_hours, waiting: s.days - s.confirmed_days })}</p>}
          {field('employer', t('wp.employer'))}
          {field('site', t('wp.site'))}
          {field('mentor_name', t('wp.mentorName'))}
          {field('mentor_phone', t('wp.mentorPhone'), 'tel')}
          <div className="grid grid-cols-2 gap-3">{field('starts_on', t('wp.starts'), 'date')}{field('ends_on', t('wp.ends'), 'date')}</div>
          <p className="text-[12px] text-muted">{t('wp.howConfirm')}</p>
        </div>
      )}
    </Drawer>
  );
}
