import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Download, FileSpreadsheet } from 'lucide-react';
import { QCTO_CODES, type CohortDetail, type QctoCheck } from '@simuwork/core';
import type { MessageKey } from '@simuwork/core/locales/en';
import { Badge, Button, Card, cx, inputClass, Switch, useToast } from '../ui';
import { api, downloadFile } from '../../lib/api';
import { useI18n } from '../../lib/i18n';
import { useQctoLabel } from '../../lib/qctoLabels';

/** Cohort page: QCTO deadline, data check, and the enrolment file download. */
export function QctoCohortCard({ cohort, manage, download, onPatch }: { cohort: CohortDetail; manage: boolean; download: boolean; onPatch: (body: Record<string, unknown>) => void }) {
  const { t, locale } = useI18n();
  const toast = useToast();
  const [qualId, setQualId] = useState(cohort.qcto_qualification_id ?? '');
  const [busy, setBusy] = useState(false);
  const label = useQctoLabel();
  const check = useQuery({ queryKey: ['qcto-check', cohort.id], queryFn: () => api<{ data: QctoCheck }>(`/cohorts/${cohort.id}/qcto-check`), enabled: cohort.qcto_type !== 'none' });
  if (cohort.qcto_type === 'none') return null;

  const c = check.data?.data;
  const left = cohort.qcto_days_left ?? 0;
  const submitted = cohort.qcto_submitted_at !== null;
  const tone = submitted ? 'success' : left <= 0 ? 'danger' : left <= 5 ? 'warning' : 'neutral';
  const due = cohort.qcto_due_on ? new Date(cohort.qcto_due_on).toLocaleDateString(locale, { day: 'numeric', month: 'long', year: 'numeric' }) : '';
  const incomplete = c ? c.learners.filter((l) => l.missing.length > 0) : [];

  const get = async () => {
    setBusy(true);
    try {
      await downloadFile(`/cohorts/${cohort.id}/qcto-register`, `LEISA-${cohort.name}.xlsx`);
    } catch {
      toast(t('common.error'), 'error');
    }
    setBusy(false);
  };

  return (
    <Card className="p-5">
      <div className="flex items-start justify-between gap-3">
        <div>
          <h2 className="flex items-center gap-2 text-[15px] font-bold text-ink"><FileSpreadsheet className="size-4" />{t('qc.title')}</h2>
          <p className="mt-1 text-[13px] text-muted">{t('qc.due', { date: due })}</p>
        </div>
        <Badge tone={tone} className="shrink-0 whitespace-nowrap">{submitted ? t('qc.submitted') : left < 0 ? t('qc.late', { n: -left }) : left === 0 ? t('qc.today') : t('qc.left', { n: left })}</Badge>
      </div>

      {c && (
        <div className="mt-4">
          <div className="flex items-center justify-between text-[13px]"><span className="font-semibold text-ink">{t('qc.ready', { n: c.complete, total: c.total })}</span></div>
          <div className="mt-1.5 h-2 overflow-hidden rounded-full bg-surface-2"><div className="h-full rounded-full bg-[#1E7A46]" style={{ width: `${c.total ? (c.complete / c.total) * 100 : 0}%` }} /></div>
          {c.cohort.length > 0 && <ul className="mt-3 space-y-1 text-[13px] text-[#9A5B00] dark:text-[#F5C26B]">{c.cohort.map((k) => <li key={k}>• {t(`qc.need.${k}` as MessageKey)}</li>)}</ul>}
          {incomplete.length > 0 && (
            <details className="mt-3">
              <summary className="cursor-pointer text-[13px] font-semibold text-muted">{t('qc.toFix', { n: incomplete.length })}</summary>
              <ul className="mt-2 space-y-1.5 text-[13px]">{incomplete.map((l) => <li key={l.learner_id}><span className="font-medium text-ink">{l.name}</span> <span className="text-muted">— {l.missing.map((m) => t(`qd.m.${m}` as MessageKey)).join(', ')}</span></li>)}</ul>
              <p className="mt-2 text-[12px] text-muted">{t('qc.fixWhere')}</p>
            </details>
          )}
        </div>
      )}

      {manage && (
        <div className="mt-4 grid gap-3">
          <label className="block">
            <span className="mb-1.5 block text-[13px] font-semibold text-ink">{t('qc.qualId')}</span>
            <input value={qualId} onChange={(e) => setQualId(e.target.value)} onBlur={() => qualId !== (cohort.qcto_qualification_id ?? '') && onPatch({ qcto_qualification_id: qualId || null })} className={inputClass} />
          </label>
          <label className="block">
            <span className="mb-1.5 block text-[13px] font-semibold text-ink">{t('qc.readiness')}</span>
            <select value={cohort.readiness_type} onChange={(e) => onPatch({ readiness_type: e.target.value })} className={inputClass}>
              {QCTO_CODES.readiness.map((r) => <option key={r} value={r}>{label('readiness', r)}</option>)}
            </select>
          </label>
        </div>
      )}

      <div className={cx('mt-4 flex flex-col gap-3', !download && !manage && 'hidden')}>
        {download && <Button variant="secondary" icon={<Download className="size-4" />} loading={busy} onClick={() => void get()}>{t('qc.download')}</Button>}
        {manage && <label className="flex items-center justify-between gap-3 text-[14px] font-medium text-ink">{t('qc.markSubmitted')}<Switch checked={submitted} onChange={(v) => onPatch({ qcto_submitted: v })} label={t('qc.markSubmitted')} /></label>}
      </div>
    </Card>
  );
}
