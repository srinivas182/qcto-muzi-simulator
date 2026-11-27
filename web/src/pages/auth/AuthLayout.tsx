import type { ReactNode } from 'react';
import { CheckCircle2, ShieldCheck } from 'lucide-react';
import { LanguageMenu, ThemeMenu } from '../../components/shell/Menus';
import { AREA } from '../../lib/area';
import { useI18n } from '../../lib/i18n';
import { usePublicTenant } from '../../lib/session';

/** Split sign-in layout: the academy's brand on the left, a calm form on the right. */
export function AuthLayout({ children }: { children: ReactNode }) {
  const { t } = useI18n();
  const tenant = usePublicTenant();
  const branding = tenant.data?.branding;
  const hq = AREA === 'hq';
  const name = hq ? t('auth.hqTitle') : branding?.display_name ?? '';
  const points = hq ? [t('auth.hqPoint1'), t('auth.hqPoint2'), t('auth.hqPoint3')] : [t('auth.heroPoint1'), t('auth.heroPoint2'), t('auth.heroPoint3')];

  const mark = branding?.logo_url ? (
    <img src={branding.logo_url} alt="" className="size-10 rounded-xl bg-white object-contain p-1" />
  ) : (
    <span className="flex size-10 items-center justify-center rounded-xl bg-brand text-lg font-bold text-white">{(name || 'S').slice(0, 1)}</span>
  );

  return (
    <div className="grid flex-1 lg:grid-cols-[minmax(0,1.05fr)_minmax(0,1fr)]">
      <aside className="relative hidden overflow-hidden bg-nav p-12 text-white lg:flex lg:flex-col">
        <div aria-hidden className="pointer-events-none absolute -right-32 -top-32 size-[520px] rounded-full bg-brand opacity-40 blur-[120px]" />
        <div aria-hidden className="pointer-events-none absolute -bottom-40 -left-24 size-[420px] rounded-full bg-accent opacity-25 blur-[120px]" />
        <div aria-hidden className="pointer-events-none absolute inset-0 opacity-[0.07] [background-image:linear-gradient(rgb(255_255_255/.6)_1px,transparent_1px),linear-gradient(90deg,rgb(255_255_255/.6)_1px,transparent_1px)] [background-size:48px_48px]" />
        <div className="relative flex items-center gap-3">
          {mark}
          <span className="text-lg font-bold">{name}</span>
        </div>
        <div className="relative mt-auto max-w-lg">
          <h2 className="text-[40px] font-bold leading-[1.1] tracking-tight">{hq ? t('auth.hqHeroTitle') : t('auth.heroTitle')}</h2>
          <ul className="mt-8 space-y-3.5">
            {points.map((p) => (
              <li key={p} className="flex items-center gap-3 text-[16px] text-white/85">
                <CheckCircle2 className="size-5 shrink-0 text-accent" aria-hidden />
                {p}
              </li>
            ))}
          </ul>
        </div>
      </aside>

      <main className="flex flex-col px-5 py-6 sm:px-10">
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-2.5 lg:invisible">
            {mark}
            <span className="text-[15px] font-bold text-ink">{name}</span>
          </div>
          <div className="flex items-center gap-1">
            <LanguageMenu signedIn={false} />
            <ThemeMenu />
          </div>
        </div>
        <div className="mx-auto flex w-full max-w-[400px] flex-1 flex-col justify-center py-10">{children}</div>
        <p className="flex items-center justify-center gap-1.5 text-[12px] text-muted">
          <ShieldCheck className="size-3.5" aria-hidden />
          {t('auth.secure')}
        </p>
      </main>
    </div>
  );
}
