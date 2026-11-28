import { useEffect, useRef, useState } from 'react';
import { Link, useParams } from 'react-router';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, Briefcase, Camera, CircleCheck, CircleHelp, Clock, WifiOff } from 'lucide-react';
import type { CheckIn, LogbookEntryOut, LogbookView } from '@simuwork/core';
import { Badge, Button, Card, cx, inputClass, Skeleton, useToast } from '../../components/ui';
import { API_BASE, api, ApiError } from '../../lib/api';
import { useI18n } from '../../lib/i18n';
import { blobToDataUrl, compressPhoto, dataUrlToBlob } from '../../lib/photo';

type Waiting = CheckIn & { photo?: string };
const KEY = (eid: string) => `sw.logbook.${eid}`;
const waiting = (eid: string): Waiting[] => { try { return JSON.parse(localStorage.getItem(KEY(eid)) ?? '[]') as Waiting[]; } catch { return []; } };
const keep = (eid: string, list: Waiting[]) => { try { localStorage.setItem(KEY(eid), JSON.stringify(list)); } catch { /* full */ } };
const today = () => new Date().toLocaleDateString('en-CA'); // YYYY-MM-DD in local time

/** Send check-ins saved on this device, oldest first. Stops at the first one that can't reach the server. */
async function sendWaiting(eid: string): Promise<number> {
  let sent = 0;
  for (const w of waiting(eid)) {
    try {
      const { photo, ...body } = w;
      const r = await api<{ data: LogbookEntryOut }>(`/learn/enrolments/${eid}/logbook`, { method: 'POST', body });
      if (photo) {
        const form = new FormData();
        form.append('photo', await dataUrlToBlob(photo), 'day.jpg');
        await api(`/learn/logbook/${r.data.id}/photo`, { method: 'POST', body: form }).catch(() => undefined);
      }
      sent++;
    } catch (e) {
      if (!(e instanceof ApiError)) break; // still offline
    }
    keep(eid, waiting(eid).filter((x) => x.client_id !== w.client_id));
  }
  return sent;
}

/** Learner: my workplace days, checked in daily and confirmed by my mentor (Sprint 5.3). */
export default function Logbook() {
  const { eid = '' } = useParams();
  const { t, locale } = useI18n();
  const qc = useQueryClient();
  const q = useQuery({ queryKey: ['logbook', eid], queryFn: () => api<{ data: LogbookView }>(`/learn/enrolments/${eid}/logbook`) });
  const [queued, setQueued] = useState(() => waiting(eid));
  useEffect(() => {
    const send = () => void sendWaiting(eid).then((n) => { setQueued(waiting(eid)); if (n) void qc.invalidateQueries({ queryKey: ['logbook', eid] }); });
    send();
    window.addEventListener('online', send);
    return () => window.removeEventListener('online', send);
  }, [eid, qc]);
  const v = q.data?.data;
  const date = (d: string) => new Date(`${d}T12:00:00`).toLocaleDateString(locale, { weekday: 'short', day: 'numeric', month: 'short' });
  return (
    <div className="sw-in mx-auto max-w-2xl space-y-4">
      <Link to={`/learn/enrolments/${eid}/assessments`} className="inline-flex items-center gap-1 text-[14px] font-semibold text-brand hover:underline"><ArrowLeft className="size-4" />{t('as.title')}</Link>
      <div><h1 className="text-[24px] font-bold text-ink">{t('as.lb.title')}</h1><p className="mt-1 text-[15px] text-muted">{t('as.lb.lead')}</p></div>
      {!v ? <Skeleton className="h-64 rounded-2xl" /> : !v.workplace ? <Card className="p-6 text-center text-[14px] text-muted">{t('as.lb.noPlacement')}</Card> : (
        <>
          <Card className="flex items-center gap-3 p-4">
            <span className="grid size-10 shrink-0 place-items-center rounded-xl bg-brand-soft text-brand"><Briefcase className="size-5" /></span>
            <div className="min-w-0 flex-1"><p className="font-semibold text-ink">{v.workplace.employer}{v.workplace.site ? ` · ${v.workplace.site}` : ''}</p><p className="text-[13px] text-muted">{t('as.lb.mentor', { name: v.workplace.mentor_name })}</p></div>
            <div className="text-right"><p className="text-[20px] font-bold text-ink">{v.summary.confirmed_days}{v.summary.min_days ? <span className="text-[14px] font-medium text-muted">/{v.summary.min_days}</span> : null}</p><p className="text-[12px] text-muted">{t('as.lb.daysConfirmed')}</p></div>
          </Card>
          {v.summary.min_days ? <div className="h-2 overflow-hidden rounded-full bg-surface-2" role="progressbar" aria-valuemin={0} aria-valuemax={v.summary.min_days} aria-valuenow={v.summary.confirmed_days} aria-label={t('as.lb.daysConfirmed')}>
            <div className="h-full rounded-full bg-brand" style={{ width: `${Math.min(100, (100 * v.summary.confirmed_days) / v.summary.min_days)}%` }} /></div> : null}
          <CheckInForm eid={eid} v={v} onSaved={(w) => { setQueued(waiting(eid)); if (!w) void qc.invalidateQueries({ queryKey: ['logbook', eid] }); }} />
          {queued.length > 0 && <p role="status" className="flex items-center gap-2 rounded-xl bg-[#FFF4E5] px-3 py-2 text-[14px] text-[#7A4B00]"><WifiOff className="size-4" />{t('as.lb.waiting', { n: queued.length })}</p>}
          <Card className="p-0">
            <ul className="divide-y divide-line">
              {v.entries.length === 0 && <li className="p-5 text-center text-[14px] text-muted">{t('as.lb.none')}</li>}
              {v.entries.map((x) => (
                <li key={x.id} className="flex gap-3 px-4 py-3">
                  {x.photo && <img src={`${API_BASE}/logbook/${x.id}/photo`} alt="" className="size-12 shrink-0 rounded-lg object-cover" loading="lazy" />}
                  <div className="min-w-0 flex-1">
                    <p className="font-semibold text-ink">{date(x.day)} · {t('as.lb.hours', { n: x.hours })}</p>
                    {x.note && <p className="text-[13px] text-muted">{x.note}</p>}
                    {x.status === 'queried' && x.mentor_note && <p className="mt-1 text-[13px] font-medium text-[#8A5A00]">{t('as.lb.queried', { note: x.mentor_note })}</p>}
                  </div>
                  {x.status === 'confirmed' ? <Badge tone="success"><CircleCheck className="size-3" />{t('as.lb.confirmed')}</Badge>
                    : x.status === 'queried' ? <Badge tone="warning"><CircleHelp className="size-3" />{t('as.lb.query')}</Badge>
                    : <Badge><Clock className="size-3" />{t('as.lb.pending')}</Badge>}
                </li>
              ))}
            </ul>
          </Card>
        </>
      )}
    </div>
  );
}

function CheckInForm({ eid, v, onSaved }: { eid: string; v: LogbookView; onSaved: (queued: boolean) => void }) {
  const { t } = useI18n();
  const toast = useToast();
  const [day, setDay] = useState(today());
  const [hours, setHours] = useState(8);
  const [outcomes, setOutcomes] = useState<string[]>([]);
  const [note, setNote] = useState('');
  const [photo, setPhoto] = useState<Blob | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const file = useRef<HTMLInputElement>(null);
  const min = new Date(Date.parse(`${today()}T12:00:00`) - v.late_days * 86_400_000).toLocaleDateString('en-CA');
  const reset = () => { setOutcomes([]); setNote(''); setPhoto(null); if (file.current) file.current.value = ''; };

  const submit = async () => {
    setBusy(true); setError(null);
    const body: CheckIn = { client_id: crypto.randomUUID(), day, hours, outcomes, note: note.trim() || null };
    try {
      const r = await api<{ data: LogbookEntryOut }>(`/learn/enrolments/${eid}/logbook`, { method: 'POST', body });
      if (photo) { const form = new FormData(); form.append('photo', photo, 'day.jpg'); await api(`/learn/logbook/${r.data.id}/photo`, { method: 'POST', body: form }); }
      toast(t('as.lb.saved')); reset(); onSaved(false);
    } catch (e) {
      if (e instanceof ApiError) { setError(Object.values(e.errors)[0]?.[0] ?? e.message); }
      else { keep(eid, [...waiting(eid), { ...body, photo: photo ? await blobToDataUrl(photo) : undefined }]); toast(t('as.lb.savedOffline')); reset(); onSaved(true); }
    } finally { setBusy(false); }
  };

  return (
    <Card className="space-y-4 p-5">
      <h2 className="text-[16px] font-semibold text-ink">{t('as.lb.checkIn')}</h2>
      <div className="grid gap-3 sm:grid-cols-2">
        <label className="text-[13px] font-semibold text-ink">{t('as.lb.day')}<input type="date" className={cx(inputClass, 'mt-1')} value={day} min={min} max={today()} onChange={(e) => setDay(e.target.value)} /></label>
        <label className="text-[13px] font-semibold text-ink">{t('as.lb.hoursLabel')}<input type="number" step={0.5} min={0.5} max={12} className={cx(inputClass, 'mt-1')} value={hours} onChange={(e) => setHours(Number(e.target.value))} /></label>
      </div>
      <fieldset>
        <legend className="text-[13px] font-semibold text-ink">{t('as.lb.what')}</legend>
        <div className="mt-2 space-y-2">
          {v.outcomes.map((o) => {
            const on = outcomes.includes(o.code);
            return (
              <label key={o.code} className={cx('flex min-h-11 cursor-pointer items-start gap-3 rounded-xl border-[1.5px] px-3 py-2 text-[14px]', on ? 'border-brand bg-brand-soft' : 'border-line')}>
                <input type="checkbox" className="mt-0.5 size-4 accent-[var(--brand)]" checked={on} onChange={() => setOutcomes(on ? outcomes.filter((c) => c !== o.code) : [...outcomes, o.code])} />
                <span className="text-ink">{o.statement}</span>
              </label>
            );
          })}
        </div>
      </fieldset>
      <label className="block text-[13px] font-semibold text-ink">{t('as.lb.note')}<textarea className={cx(inputClass, 'mt-1 min-h-20 py-2')} maxLength={500} value={note} onChange={(e) => setNote(e.target.value)} placeholder={t('as.lb.notePlaceholder')} /></label>
      <div className="flex items-center gap-3">
        <input ref={file} id="lb-photo" type="file" accept="image/*" capture="environment" className="sr-only" onChange={async (e) => {
          const f = e.target.files?.[0];
          if (!f) return;
          try { setPhoto(await compressPhoto(f)); } catch { toast(t('as.lb.photoFail'), 'error'); }
        }} />
        <label htmlFor="lb-photo" className="inline-flex h-10 cursor-pointer items-center gap-2 rounded-xl border border-line px-4 text-[14px] font-semibold text-ink"><Camera className="size-4" />{t(photo ? 'as.lb.photoChange' : 'as.lb.photo')}</label>
        {photo && <span className="text-[12px] text-muted">{t('as.lb.photoSize', { kb: Math.round(photo.size / 1024) })}</span>}
      </div>
      {error && <p role="alert" className="text-[13px] text-[#C92A2F]">{error}</p>}
      <Button className="w-full" size="lg" loading={busy} disabled={outcomes.length === 0} onClick={() => void submit()}>{t('as.lb.send')}</Button>
    </Card>
  );
}
