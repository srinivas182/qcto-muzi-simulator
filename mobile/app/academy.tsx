import { useState } from 'react';
import { View } from 'react-native';
import { router, useLocalSearchParams } from 'expo-router';
import { ScanLine } from '../src/ui/icons';
import { ApiError } from '../src/lib/api';
import { useAcademy } from '../src/lib/academy';
import { useI18n } from '../src/lib/i18n';
import { Button, Field, haptics, Screen, space, useTheme } from '../src/ui';

export default function AcademyScreen() {
  const { t } = useI18n();
  const { c } = useTheme();
  const { choose } = useAcademy();
  const params = useLocalSearchParams<{ code?: string }>();
  const [code, setCode] = useState(params.code ?? '');
  const [error, setError] = useState<string>();
  const [busy, setBusy] = useState(false);

  const submit = async () => {
    setBusy(true);
    setError(undefined);
    try {
      await choose(code);
      haptics.success();
      router.replace('/sign-in');
    } catch (e) {
      haptics.warn();
      setError(e instanceof ApiError && e.status === 0 ? t('common.networkError') : t('m.academy.notFound'));
    } finally {
      setBusy(false);
    }
  };

  return (
    <Screen
      title={t('m.academy.title')}
      subtitle={t('m.academy.body')}
      bottom={<Button title={t('m.academy.continue')} onPress={submit} loading={busy} disabled={code.trim().length < 2} />}
    >
      <Field
        label={t('m.academy.code')}
        placeholder={t('m.academy.codeHint')}
        value={code}
        onChangeText={setCode}
        autoCapitalize="none"
        autoCorrect={false}
        autoFocus
        returnKeyType="go"
        onSubmitEditing={submit}
        error={error}
      />
      <View style={{ marginTop: space.sm }}>
        <Button variant="secondary" title={t('m.academy.scan')} icon={<ScanLine size={20} color={c.text} />} onPress={() => router.push('/scan')} />
      </View>
    </Screen>
  );
}
