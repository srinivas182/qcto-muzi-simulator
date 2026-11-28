import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Camera } from 'lucide-react';
import { Button, Card, Switch, useToast } from '../ui';
import { api } from '../../lib/api';
import { useI18n } from '../../lib/i18n';

type Settings = { camera_checks: { on: boolean; accepted_by?: string; accepted_at?: string } };

/** Provider setting: allow the optional camera check on tests (POPIA impact assessment accepted once). */
export function CameraChecksCard() {
  const { t, locale } = useI18n();
  const toast = useToast();
  const qc = useQueryClient();
  const q = useQuery({ queryKey: ['assessment-settings'], queryFn: () => api<{ data: Settings }>('/academy/assessment') });
  const [accept, setAccept] = useState(false);
  const [wantOn, setWantOn] = useState(false);
  const save = useMutation({
    mutationFn: (on: boolean) => api<{ data: Settings }>('/academy/assessment', { method: 'PATCH', body: on ? { camera_checks: true, accept_dpia: true } : { camera_checks: false } }),
    onSuccess: (r) => { qc.setQueryData(['assessment-settings'], r); setWantOn(false); setAccept(false); toast(t('acad.cam.saved')); },
    onError: () => toast(t('common.error'), 'error'),
  });
  const s = q.data?.data.camera_checks;
  if (!s) return null;
  return (
    <Card className="mt-6 p-5">
      <div className="flex items-start gap-3">
        <Camera className="mt-0.5 size-5 shrink-0 text-brand" />
        <div className="flex-1">
          <p className="font-semibold text-ink">{t('acad.cam.title')}</p>
          <p className="mt-1 text-[13px] text-muted">{t('acad.cam.lead')}</p>
          {s.on && s.accepted_by && <p className="mt-2 text-[13px] text-ink">{t('acad.cam.onBy', { name: s.accepted_by, date: new Date(s.accepted_at ?? '').toLocaleDateString(locale) })}</p>}
        </div>
        <Switch checked={s.on || wantOn} onChange={(v) => (v ? setWantOn(true) : save.mutate(false))} label={t('acad.cam.title')} />
      </div>
      {wantOn && !s.on && (
        <div className="mt-4 rounded-xl border border-line p-4">
          <ul className="list-disc space-y-1 pl-5 text-[13px] text-ink">
            <li>{t('acad.cam.p1')}</li><li>{t('acad.cam.p2')}</li><li>{t('acad.cam.p3')}</li><li>{t('acad.cam.p4')}</li>
          </ul>
          <label className="mt-3 flex items-start gap-2 text-[13px] font-medium text-ink">
            <input type="checkbox" className="mt-0.5 size-4 accent-[var(--brand)]" checked={accept} onChange={(e) => setAccept(e.target.checked)} />
            {t('acad.cam.accept')}
          </label>
          <div className="mt-3 flex gap-2">
            <Button size="sm" disabled={!accept} loading={save.isPending} onClick={() => save.mutate(true)}>{t('acad.cam.switchOn')}</Button>
            <Button size="sm" variant="ghost" onClick={() => setWantOn(false)}>{t('common.cancel')}</Button>
          </div>
        </div>
      )}
    </Card>
  );
}
