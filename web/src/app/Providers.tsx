import { useEffect, type ReactNode } from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { ToastProvider } from '../components/ui';
import { AREA } from '../lib/area';
import { I18nProvider, isLocale, storedLocale, useI18n } from '../lib/i18n';
import { usePublicTenant, useSession } from '../lib/session';
import { applyBranding } from '../lib/theme';

export function createQueryClient() {
  return new QueryClient({ defaultOptions: { queries: { staleTime: 30_000, refetchOnWindowFocus: false } } });
}

/** Keeps colours and language in step with the academy and the signed-in person. */
function Sync() {
  const tenant = usePublicTenant();
  const session = useSession();
  const { locale, setLocale } = useI18n();
  const branding = session.data?.tenant?.branding ?? (AREA === 'tenant' ? tenant.data?.branding : null);

  useEffect(() => applyBranding(branding ?? null), [branding]);

  useEffect(() => {
    if (storedLocale()) return;
    const preferred = session.data?.user.locale ?? branding?.default_locale;
    if (isLocale(preferred) && preferred !== locale) setLocale(preferred);
  }, [session.data?.user.locale, branding?.default_locale, locale, setLocale]);

  useEffect(() => {
    const name = branding?.display_name ?? (AREA === 'hq' ? 'SimuWork HQ' : 'SimuWork');
    document.title = name;
  }, [branding?.display_name]);

  return null;
}

export function Providers({ client, children }: { client: QueryClient; children: ReactNode }) {
  return (
    <QueryClientProvider client={client}>
      <I18nProvider>
        <ToastProvider>
          <Sync />
          {children}
        </ToastProvider>
      </I18nProvider>
    </QueryClientProvider>
  );
}
