import { useState, type FormEvent } from 'react';
import { Link } from 'react-router';
import { ArrowLeft, MailCheck } from 'lucide-react';
import { Button, Field } from '../../components/ui';
import { api, ApiError } from '../../lib/api';
import { areaPrefix } from '../../lib/area';
import { useI18n } from '../../lib/i18n';
import { AuthLayout } from './AuthLayout';

export default function ForgotPassword() {
  const { t } = useI18n();
  const [email, setEmail] = useState('');
  const [sent, setSent] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<ApiError | null>(null);

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      await api(areaPrefix() + '/auth/password/forgot', { method: 'POST', body: { email } });
      setSent(true);
    } catch (err) {
      setError(err instanceof ApiError ? err : new ApiError(0, t('common.networkError')));
    } finally {
      setBusy(false);
    }
  };

  return (
    <AuthLayout>
      <div className="sw-in">
        <Link to="/sign-in" className="mb-6 inline-flex items-center gap-1.5 text-[13px] font-semibold text-brand hover:underline">
          <ArrowLeft className="size-4" /> {t('auth.backToSignIn')}
        </Link>
        <h1 className="text-[28px] font-bold tracking-tight text-ink">{t('auth.forgotTitle')}</h1>
        {sent ? (
          <div role="status" className="mt-6 flex gap-3 rounded-xl bg-brand-soft p-4 text-[15px] text-ink">
            <MailCheck className="size-5 shrink-0 text-brand" /> {t('auth.linkSent')}
          </div>
        ) : (
          <>
            <p className="mt-1.5 text-[15px] text-muted">{t('auth.forgotBody')}</p>
            <form onSubmit={submit} className="mt-6 space-y-4" noValidate>
              <Field label={t('auth.email')} type="email" autoComplete="email" value={email} onChange={(e) => setEmail(e.target.value)} error={error?.field('email') ?? (error && !Object.keys(error.errors).length ? error.message : undefined)} autoFocus required />
              <Button type="submit" size="lg" className="w-full" loading={busy}>{t('auth.sendLink')}</Button>
            </form>
          </>
        )}
      </div>
    </AuthLayout>
  );
}
