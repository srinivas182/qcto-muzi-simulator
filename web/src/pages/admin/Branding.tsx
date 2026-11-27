import { useEffect, useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Box, CircleCheck, CircleAlert, Info, Play } from 'lucide-react';
import { MCS_FOOTER } from '@simuwork/ui';
import { useSignedIn } from '../../app/access';
import { Button, Card, Field, inputClass, PageHeader, useToast, cx } from '../../components/ui';
import { api, ApiError } from '../../lib/api';
import { contrastWithWhite } from '@simuwork/core';
import { LOCALES, useI18n, type Locale } from '../../lib/i18n';
import { sessionKey } from '../../lib/session';
import { applyBranding } from '../../lib/theme';
import type { Branding as BrandingT } from '@simuwork/core';

type ColourKey = 'primary' | 'accent' | 'nav';
const HEX = /^#[0-9a-f]{6}$/i;

function ColourField({ id, label, value, onChange, checkContrast, error }: { id: ColourKey; label: string; value: string; onChange: (v: string) => void; checkContrast?: boolean; error?: string }) {
  const { t } = useI18n();
  const valid = HEX.test(value);
  const ratio = valid ? contrastWithWhite(value) : 0;
  const ok = ratio >= 4.5;
  return (
    <div>
      <label htmlFor={`c-${id}`} className="mb-1.5 block text-[13px] font-semibold text-ink">{label}</label>
      <div className="flex gap-2">
        <input type="color" aria-label={label} value={valid ? value : '#000000'} onChange={(e) => onChange(e.target.value.toUpperCase())} className="h-11 w-14 cursor-pointer rounded-xl border border-line bg-surface p-1" />
        <input id={`c-${id}`} value={value} onChange={(e) => onChange(e.target.value)} maxLength={7} spellCheck={false} className={cx(inputClass, 'font-mono uppercase')} />
      </div>
      {error ? <p className="mt-1.5 text-[13px] font-medium text-[#C92A2F]" role="alert">{error}</p> : checkContrast && valid && (
        <p className={cx('mt-1.5 flex items-center gap-1.5 text-[13px] font-medium', ok ? 'text-[#0B7A5C] dark:text-[#5FD3B1]' : 'text-[#C92A2F]')}>
          {ok ? <CircleCheck className="size-4" /> : <CircleAlert className="size-4" />}
          {ok ? t('branding.contrastOk', { ratio: ratio.toFixed(1) }) : t('branding.contrastFail')}
        </p>
      )}
    </div>
  );
}

export default function Branding() {
  const session = useSignedIn();
  const { t } = useI18n();
  const toast = useToast();
  const qc = useQueryClient();
  const saved = session.tenant!.branding;
  const [form, setForm] = useState<BrandingT>(saved);
  const set = <K extends keyof BrandingT>(k: K, v: BrandingT[K]) => setForm((f) => ({ ...f, [k]: v }));

  // Live preview across the whole app while editing; restored if the page is left unsaved.
  useEffect(() => {
    if ([form.primary, form.accent, form.nav].every((c) => HEX.test(c))) applyBranding(form);
  }, [form]);
  useEffect(() => () => applyBranding(qc.getQueryData<typeof session>(sessionKey)?.tenant?.branding), [qc]);

  const save = useMutation({
    mutationFn: async () => (await api<{ data: BrandingT }>('/branding', { method: 'PATCH', body: { ...form, logo_url: form.logo_url || null } })).data,
    onSuccess: (data) => {
      qc.setQueryData(sessionKey, { ...session, tenant: { ...session.tenant!, branding: data } });
      qc.invalidateQueries({ queryKey: ['tenant'] });
      toast(t('branding.saved'));
    },
  });
  const err = save.error instanceof ApiError ? save.error : null;
  const blocked = [form.primary, form.nav].some((c) => !HEX.test(c) || contrastWithWhite(c) < 4.5) || !HEX.test(form.accent);
  const dirty = JSON.stringify(form) !== JSON.stringify(saved);

  return (
    <div className="sw-in">
      <PageHeader
        title={t('branding.title')}
        lead={t('branding.lead')}
        actions={<Button loading={save.isPending} disabled={!dirty || blocked} onClick={() => save.mutate()}>{t('common.save')}</Button>}
      />

      <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_380px]">
        <Card className="space-y-6 p-6">
          <div className="grid gap-5 sm:grid-cols-2">
            <Field label={t('branding.name')} value={form.display_name} onChange={(e) => set('display_name', e.target.value)} maxLength={80} error={err?.field('display_name')} />
            <Field label={t('branding.logo')} type="url" placeholder="https://" value={form.logo_url ?? ''} onChange={(e) => set('logo_url', e.target.value)} error={err?.field('logo_url')} />
          </div>
          <div className="grid gap-5 sm:grid-cols-3">
            <ColourField id="primary" label={t('branding.primary')} value={form.primary} onChange={(v) => set('primary', v)} checkContrast error={err?.field('primary')} />
            <ColourField id="nav" label={t('branding.nav')} value={form.nav} onChange={(v) => set('nav', v)} checkContrast error={err?.field('nav')} />
            <ColourField id="accent" label={t('branding.accent')} value={form.accent} onChange={(v) => set('accent', v)} error={err?.field('accent')} />
          </div>
          <div className="sm:max-w-xs">
            <label htmlFor="b-locale" className="mb-1.5 block text-[13px] font-semibold text-ink">{t('branding.locale')}</label>
            <select id="b-locale" value={form.default_locale} onChange={(e) => set('default_locale', e.target.value)} className={inputClass}>
              {(Object.keys(LOCALES) as Locale[]).map((l) => <option key={l} value={l}>{LOCALES[l]}</option>)}
            </select>
          </div>
          <p className="flex items-start gap-2 rounded-xl bg-surface-2 p-3.5 text-[13px] text-muted"><Info className="mt-0.5 size-4 shrink-0" />{t('branding.footerNote')}</p>
        </Card>

        <div>
          <h2 className="mb-3 text-[13px] font-bold uppercase tracking-[0.12em] text-muted">{t('branding.preview')}</h2>
          <div className="mx-auto w-[300px] rounded-[38px] border-[10px] border-[#12152B] bg-surface shadow-2xl">
            <div className="overflow-hidden rounded-[28px]">
              <div className="flex items-center gap-2.5 px-4 pb-4 pt-6 text-white" style={{ background: HEX.test(form.nav) ? form.nav : undefined }}>
                {form.logo_url ? <img src={form.logo_url} alt="" className="size-8 rounded-lg bg-white object-contain p-0.5" /> : <span className="flex size-8 items-center justify-center rounded-lg bg-brand text-sm font-bold">{(form.display_name || 'S').slice(0, 1)}</span>}
                <span className="truncate text-[14px] font-bold">{form.display_name}</span>
              </div>
              <div className="space-y-3 bg-bg p-4">
                <div className="rounded-2xl border border-line bg-surface p-3.5">
                  <div className="flex size-10 items-center justify-center rounded-xl bg-brand-soft text-brand"><Box className="size-5" /></div>
                  <div className="mt-3 text-[14px] font-bold text-ink">{t('branding.previewCard')}</div>
                  <div className="text-[12px] text-muted">{t('branding.previewMeta')}</div>
                  <div className="mt-3 h-1.5 overflow-hidden rounded-full bg-surface-2"><div className="h-full w-2/3 rounded-full bg-accent" /></div>
                  <button tabIndex={-1} className="mt-3.5 flex h-10 w-full items-center justify-center gap-2 rounded-xl bg-brand text-[13px] font-semibold text-white"><Play className="size-4" />{t('branding.previewButton')}</button>
                </div>
                <p aria-hidden className="pt-1 text-center text-[10px] text-muted">{MCS_FOOTER.prefix} <span className="font-semibold text-[var(--sw-footer-link)]">{MCS_FOOTER.company}</span></p>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
