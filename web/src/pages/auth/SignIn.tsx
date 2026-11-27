import { useEffect, useRef, useState, type FormEvent } from 'react';
import { Link, Navigate, useNavigate, useSearchParams } from 'react-router';
import { ArrowLeft, Mail, Smartphone } from 'lucide-react';
import { Button, cx, Field } from '../../components/ui';
import { api, ApiError } from '../../lib/api';
import { AREA, areaPrefix } from '../../lib/area';
import { useI18n } from '../../lib/i18n';
import { homePath, useRefreshSession, useSession, usePublicTenant, type SignInResult } from '../../lib/session';
import type { Session } from '@simuwork/core';
import { AuthLayout } from './AuthLayout';

type Step = 'phone' | 'code' | 'email' | 'two-factor';

/** Only same-site paths are followed after sign-in. */
const safeNext = (next: string | null) => (next && next.startsWith('/') && !next.startsWith('//') ? next : null);

export default function SignIn() {
  const { t } = useI18n();
  const session = useSession();
  const tenant = usePublicTenant();
  const refresh = useRefreshSession();
  const navigate = useNavigate();
  const [params] = useSearchParams();
  const hq = AREA === 'hq';

  const [step, setStep] = useState<Step>(hq ? 'email' : 'phone');
  const [phone, setPhone] = useState('');
  const [sentTo, setSentTo] = useState('');
  const [code, setCode] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [challenge, setChallenge] = useState('');
  const [useRecovery, setUseRecovery] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<ApiError | null>(null);
  const codeRef = useRef<HTMLInputElement>(null);

  useEffect(() => {
    if (step === 'code' || step === 'two-factor') codeRef.current?.focus();
  }, [step]);

  if (session.data) return <Navigate to={safeNext(params.get('next')) ?? homePath(session.data)} replace />;

  const run = async (fn: () => Promise<void>) => {
    setBusy(true);
    setError(null);
    try {
      await fn();
    } catch (e) {
      setError(e instanceof ApiError ? e : new ApiError(0, t('common.networkError')));
    } finally {
      setBusy(false);
    }
  };

  const finish = async (result: SignInResult) => {
    if (result.two_factor_required && result.challenge_token) {
      setChallenge(result.challenge_token);
      setCode('');
      setStep('two-factor');
      return;
    }
    const s = (await api<{ data: Session }>(areaPrefix() + '/session')).data;
    await refresh();
    navigate(safeNext(params.get('next')) ?? homePath(s), { replace: true });
  };

  const submit = (e: FormEvent) => {
    e.preventDefault();
    if (step === 'phone')
      run(async () => {
        const r = await api<{ sent_to: string }>('/auth/otp/request', { method: 'POST', body: { phone } });
        setSentTo(r.sent_to);
        setCode('');
        setStep('code');
      });
    if (step === 'code') run(async () => finish(await api('/auth/otp/verify', { method: 'POST', body: { phone, code } })));
    if (step === 'email') run(async () => finish(await api(areaPrefix() + '/auth/login', { method: 'POST', body: { email, password } })));
    if (step === 'two-factor')
      run(async () =>
        finish(
          await api(areaPrefix() + '/auth/two-factor/challenge', {
            method: 'POST',
            body: useRecovery ? { challenge_token: challenge, recovery_code: code } : { challenge_token: challenge, code },
          }),
        ),
      );
  };

  const general = error && !Object.keys(error.errors).length ? error.message : error?.field('account') ?? error?.field('challenge_token');
  const title = step === 'two-factor' ? t('auth.twoFactorTitle') : hq ? t('auth.hqTitle') : t('auth.welcome');
  const subtitle =
    step === 'two-factor' ? t('auth.twoFactorBody') : hq ? t('auth.hqSubtitle') : t('auth.subtitle', { name: tenant.data?.branding.display_name ?? '' });

  return (
    <AuthLayout>
      <div className="sw-in">
        <h1 className="text-[28px] font-bold tracking-tight text-ink">{title}</h1>
        <p className="mt-1.5 text-[15px] text-muted">{subtitle}</p>

        {!hq && (step === 'phone' || step === 'email') && (
          <div role="tablist" className="mt-7 grid grid-cols-2 rounded-xl bg-surface-2 p-1">
            {(['phone', 'email'] as const).map((s) => (
              <button
                key={s}
                role="tab"
                aria-selected={step === s}
                onClick={() => { setStep(s); setError(null); }}
                className={cx('flex h-9 items-center justify-center gap-2 rounded-lg text-[13px] font-semibold transition', step === s ? 'bg-surface text-ink shadow-sm' : 'text-muted hover:text-ink')}
              >
                {s === 'phone' ? <Smartphone className="size-4" /> : <Mail className="size-4" />}
                {s === 'phone' ? t('auth.tabPhone') : t('auth.tabEmail')}
              </button>
            ))}
          </div>
        )}

        <form onSubmit={submit} className="mt-6 space-y-4" noValidate>
          {step === 'phone' && (
            <Field label={t('auth.phone')} type="tel" inputMode="tel" autoComplete="tel" placeholder="082 123 4567" value={phone} onChange={(e) => setPhone(e.target.value)} hint={t('auth.phoneHint')} error={error?.field('phone')} autoFocus required />
          )}

          {(step === 'code' || step === 'two-factor') && (
            <>
              {step === 'code' && (
                <button type="button" onClick={() => setStep('phone')} className="flex items-center gap-1.5 text-[13px] font-semibold text-brand hover:underline">
                  <ArrowLeft className="size-4" /> {t('auth.changeNumber')}
                </button>
              )}
              <Field
                ref={codeRef}
                label={useRecovery && step === 'two-factor' ? t('auth.recovery') : t('auth.code')}
                hint={step === 'code' ? t('auth.codeSent', { to: sentTo }) : undefined}
                inputMode={useRecovery ? 'text' : 'numeric'}
                autoComplete="one-time-code"
                maxLength={useRecovery ? 32 : 6}
                value={code}
                onChange={(e) => setCode(useRecovery ? e.target.value : e.target.value.replace(/\D/g, ''))}
                className="[&_input]:text-center [&_input]:text-[22px] [&_input]:font-semibold [&_input]:tracking-[0.5em]"
                error={error?.field('code')}
                required
              />
            </>
          )}

          {step === 'email' && (
            <>
              <Field label={t('auth.email')} type="email" autoComplete="username" value={email} onChange={(e) => setEmail(e.target.value)} error={error?.field('email')} autoFocus required />
              <Field
                label={t('auth.password')}
                type="password"
                autoComplete="current-password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                error={error?.field('password')}
                trailing={<Link to="/forgot-password" className="text-[13px] font-semibold text-brand hover:underline">{t('auth.forgot')}</Link>}
                required
              />
            </>
          )}

          {general && <p role="alert" className="rounded-xl bg-[#FDECEC] px-3.5 py-2.5 text-[14px] font-medium text-[#B42328] dark:bg-[#B42328]/20 dark:text-[#FF9A9E]">{general}</p>}

          <Button type="submit" size="lg" className="w-full" loading={busy} disabled={(step === 'code' && code.length < 6)}>
            {step === 'phone' ? t('auth.sendCode') : t('auth.signIn')}
          </Button>

          {step === 'two-factor' && (
            <button type="button" onClick={() => { setUseRecovery((r) => !r); setCode(''); }} className="w-full text-center text-[13px] font-semibold text-brand hover:underline">
              {useRecovery ? t('auth.useCode') : t('auth.useRecovery')}
            </button>
          )}
        </form>
      </div>
    </AuthLayout>
  );
}
