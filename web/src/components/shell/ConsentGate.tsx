import { useState } from 'react';
import { ShieldCheck } from 'lucide-react';
import { api } from '../../lib/api';
import { useI18n } from '../../lib/i18n';
import { useRefreshSession } from '../../lib/session';
import { Button } from '../ui';

/** POPIA: nobody uses the app until the current consents are given. */
export function ConsentGate({ purposes }: { purposes: string[] }) {
  const { tx, t } = useI18n();
  const refresh = useRefreshSession();
  const [checked, setChecked] = useState<Record<string, boolean>>({});
  const [busy, setBusy] = useState(false);
  const all = purposes.every((p) => checked[p]);

  const submit = async () => {
    setBusy(true);
    try {
      for (const purpose of purposes) await api('/auth/consents', { method: 'POST', body: { purpose, granted: true } });
      await refresh();
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="fixed inset-0 z-[58] flex items-center justify-center bg-[#090c1c]/55 p-4 backdrop-blur-sm">
      <div role="dialog" aria-modal="true" aria-labelledby="consent-title" className="sw-in w-full max-w-md rounded-2xl bg-surface p-6 shadow-2xl">
        <div className="mb-4 flex size-11 items-center justify-center rounded-xl bg-brand-soft text-brand"><ShieldCheck className="size-6" /></div>
        <h2 id="consent-title" className="text-xl font-bold text-ink">{t('consent.title')}</h2>
        <p className="mt-1.5 text-[15px] text-muted">{t('consent.body')}</p>
        <div className="mt-5 space-y-3">
          {purposes.map((p) => (
            <label key={p} className="flex cursor-pointer items-start gap-3 rounded-xl border border-line p-3.5 hover:bg-surface-2">
              <input type="checkbox" className="mt-0.5 size-5 accent-[var(--brand-primary)]" checked={!!checked[p]} onChange={(e) => setChecked((c) => ({ ...c, [p]: e.target.checked }))} />
              <span className="text-[14px] text-ink">{tx('consent.' + p, p)}</span>
            </label>
          ))}
        </div>
        <Button className="mt-6 w-full" size="lg" disabled={!all} loading={busy} onClick={submit}>{t('consent.continue')}</Button>
      </div>
    </div>
  );
}
