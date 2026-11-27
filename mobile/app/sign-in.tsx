import { useRef, useState, type ComponentRef } from 'react';
import { Pressable, StyleSheet, TextInput, View } from 'react-native';
import { router } from 'expo-router';
import { Mail, Smartphone } from '../src/ui/icons';
import { api, ApiError } from '../src/lib/api';
import { useAcademy } from '../src/lib/academy';
import { mobileClient, useAuth, type TokenResponse } from '../src/lib/auth';
import { useI18n } from '../src/lib/i18n';
import { AcademyMark, Button, Field, haptics, radius, Screen, space, T, useTheme } from '../src/ui';

type Step = 'phone' | 'code' | 'email' | 'two-factor';

export default function SignIn() {
  const { t } = useI18n();
  const { c, dark } = useTheme();
  const { academy, forget } = useAcademy();
  const { signedIn } = useAuth();
  const [step, setStep] = useState<Step>('phone');
  const [phone, setPhone] = useState('');
  const [sentTo, setSentTo] = useState('');
  const [code, setCode] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [challenge, setChallenge] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<ApiError | null>(null);
  const passwordRef = useRef<ComponentRef<typeof TextInput>>(null);

  const run = async (fn: () => Promise<void>) => {
    setBusy(true);
    setError(null);
    try {
      await fn();
    } catch (e) {
      haptics.warn();
      setError(e instanceof ApiError ? (e.status === 0 ? new ApiError(0, t('common.networkError')) : e) : new ApiError(0, t('common.error')));
    } finally {
      setBusy(false);
    }
  };

  const finish = async (r: TokenResponse) => {
    if (r.two_factor_required && r.challenge_token) {
      setChallenge(r.challenge_token);
      setCode('');
      setStep('two-factor');
      return;
    }
    if (!r.token) throw new ApiError(500, t('common.error'));
    haptics.success();
    await signedIn(r.token);
    router.replace('/home');
  };

  const submit = () => {
    if (step === 'phone')
      return run(async () => {
        const r = await api<{ sent_to: string }>('/auth/otp/request', { method: 'POST', body: { phone } });
        setSentTo(r.sent_to);
        setCode('');
        setStep('code');
      });
    if (step === 'code') return run(async () => finish(await api('/auth/otp/verify', { method: 'POST', body: { phone, code, ...mobileClient() } })));
    if (step === 'email') return run(async () => finish(await api('/auth/login', { method: 'POST', body: { email, password, ...mobileClient() } })));
    return run(async () => finish(await api('/auth/two-factor/challenge', { method: 'POST', body: { challenge_token: challenge, code } })));
  };

  const general = error && !Object.keys(error.errors).length ? error.message : error?.field('account') ?? error?.field('challenge_token');
  const codeStep = step === 'code' || step === 'two-factor';
  const disabled = codeStep ? code.length < 6 : step === 'phone' ? phone.trim().length < 9 : !email || !password;

  return (
    <Screen
      header={
        <View style={styles.academy}>
          <AcademyMark name={academy?.branding.display_name ?? ''} logo={academy?.branding.logo_url} />
          <View style={{ flex: 1 }}>
            <T variant="headline" numberOfLines={1}>{academy?.branding.display_name}</T>
            <Pressable accessibilityRole="button" onPress={() => { void forget(); router.replace('/academy'); }} hitSlop={8}>
              <T variant="caption" tone="brand" style={{ fontWeight: '700' }}>{t('m.academy.change')}</T>
            </Pressable>
          </View>
        </View>
      }
      title={step === 'two-factor' ? t('auth.twoFactorTitle') : t('auth.welcome')}
      subtitle={step === 'two-factor' ? t('auth.twoFactorBody') : step === 'code' ? t('auth.codeSent', { to: sentTo }) : t('auth.subtitle', { name: academy?.branding.display_name ?? '' })}
      bottom={
        <View style={{ gap: space.sm }}>
          <Button title={step === 'phone' ? t('auth.sendCode') : t('auth.signIn')} onPress={() => void submit()} loading={busy} disabled={disabled} />
          {(step === 'phone' || step === 'email') && (
            <Button
              variant="ghost"
              title={step === 'phone' ? t('m.signin.useEmail') : t('m.signin.usePhone')}
              icon={step === 'phone' ? <Mail size={18} color={c.brand} /> : <Smartphone size={18} color={c.brand} />}
              onPress={() => { setError(null); setStep(step === 'phone' ? 'email' : 'phone'); }}
            />
          )}
          {step === 'code' && <Button variant="ghost" title={t('auth.changeNumber')} onPress={() => setStep('phone')} />}
        </View>
      }
    >
      {step === 'phone' && (
        <Field
          label={t('auth.phone')}
          placeholder="082 123 4567"
          keyboardType="phone-pad"
          textContentType="telephoneNumber"
          autoComplete="tel"
          value={phone}
          onChangeText={setPhone}
          hint={t('auth.phoneHint')}
          error={error?.field('phone')}
          autoFocus
        />
      )}
      {codeStep && (
        <Field
          key={step}
          label={t('auth.code')}
          keyboardType="number-pad"
          textContentType="oneTimeCode"
          autoComplete="sms-otp"
          maxLength={6}
          value={code}
          onChangeText={(v) => setCode(v.replace(/\D/g, ''))}
          error={error?.field('code')}
          big
          autoFocus
          onSubmitEditing={() => void submit()}
        />
      )}
      {step === 'email' && (
        <>
          <Field label={t('auth.email')} keyboardType="email-address" autoCapitalize="none" autoComplete="email" textContentType="username" value={email} onChangeText={setEmail} error={error?.field('email')} returnKeyType="next" onSubmitEditing={() => passwordRef.current?.focus()} autoFocus />
          <Field ref={passwordRef} label={t('auth.password')} secureTextEntry autoComplete="current-password" textContentType="password" value={password} onChangeText={setPassword} error={error?.field('password')} returnKeyType="go" onSubmitEditing={() => void submit()} />
        </>
      )}
      {general ? (
        <View style={[styles.alert, { backgroundColor: dark ? '#3a1416' : '#FDECEC' }]} accessibilityLiveRegion="assertive">
          <T variant="label" tone="danger">{general}</T>
        </View>
      ) : null}
      <T variant="caption" tone="muted" style={{ textAlign: 'center', marginTop: space.xl }}>{t('auth.secure')}</T>
    </Screen>
  );
}

const styles = StyleSheet.create({
  academy: { flexDirection: 'row', alignItems: 'center', gap: space.md, paddingHorizontal: space.xl, paddingTop: space.lg, maxWidth: 640, width: '100%', alignSelf: 'center' },
  alert: { borderRadius: radius.md, padding: space.md },
});
