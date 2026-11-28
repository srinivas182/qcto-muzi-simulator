import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Accessibility } from 'lucide-react';
import { Button, cx, Drawer, inputClass, Switch, useToast } from '../ui';
import { api } from '../../lib/api';
import { useI18n } from '../../lib/i18n';
import { can, useSession } from '../../lib/session';

type Acc = { extra_time: number; read_aloud: boolean };

/** Extra time and read-aloud for one learner's tests. Only the settings are kept, never the reason. */
export function AccommodationsButton({ enrolmentId, learner }: { enrolmentId: string; learner: string }) {
  const { t } = useI18n();
  const { data: session } = useSession();
  const [open, setOpen] = useState(false);
  if (!can(session, 'assessments.signoff')) return null;
  return (
    <>
      <Button variant="ghost" size="sm" onClick={() => setOpen(true)}><Accessibility className="size-4" />{t('rv.acc.button')}</Button>
      {open && <AccDrawer enrolmentId={enrolmentId} learner={learner} onClose={() => setOpen(false)} />}
    </>
  );
}

function AccDrawer({ enrolmentId, learner, onClose }: { enrolmentId: string; learner: string; onClose: () => void }) {
  const { t } = useI18n();
  const toast = useToast();
  const qc = useQueryClient();
  const url = `/assess/enrolments/${enrolmentId}/accommodations`;
  const q = useQuery({ queryKey: ['accommodations', enrolmentId], queryFn: () => api<{ data: Acc }>(url) });
  const [edit, setEdit] = useState<Acc | null>(null);
  const v = edit ?? q.data?.data ?? null;
  const save = useMutation({
    mutationFn: () => api<{ data: Acc }>(url, { method: 'PUT', body: v ?? {} }),
    onSuccess: (r) => { qc.setQueryData(['accommodations', enrolmentId], r); toast(t('rv.saved')); onClose(); },
    onError: () => toast(t('common.error'), 'error'),
  });
  return (
    <Drawer open onClose={onClose} title={t('rv.acc.title', { name: learner })} subtitle={t('rv.acc.lead')}
      footer={<Button disabled={!v} loading={save.isPending} onClick={() => save.mutate()}>{t('rv.acc.save')}</Button>}>
      {v && (
        <div className="space-y-5">
          <label className="block text-[13px] font-semibold text-ink">{t('rv.acc.extra')}
            <select className={cx(inputClass, 'mt-1 h-10')} value={v.extra_time} onChange={(e) => setEdit({ ...v, extra_time: Number(e.target.value) })}>
              {[0, 25, 50, 100].map((n) => <option key={n} value={n}>{n === 0 ? t('rv.acc.none') : t('rv.acc.more', { n })}</option>)}
            </select>
          </label>
          <div className="flex items-center justify-between gap-3">
            <div><p className="text-[13px] font-semibold text-ink">{t('rv.acc.read')}</p><p className="text-[12px] text-muted">{t('rv.acc.readLead')}</p></div>
            <Switch checked={v.read_aloud} onChange={(on) => setEdit({ ...v, read_aloud: on })} label={t('rv.acc.read')} />
          </div>
          <p className="rounded-xl bg-surface-2 p-3 text-[12px] text-muted">{t('rv.acc.popia')}</p>
        </div>
      )}
    </Drawer>
  );
}
