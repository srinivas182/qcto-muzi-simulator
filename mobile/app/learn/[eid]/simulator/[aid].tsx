import { router, useLocalSearchParams } from 'expo-router';
import { useI18n } from '../../../../src/lib/i18n';
import { Box } from '../../../../src/ui/icons';
import { Button, EmptyState, Screen, space, useTheme } from '../../../../src/ui';

/** Placeholder until the 3D simulator runtime arrives (Sprint 4.4). */
export default function SimulatorSoon() {
  const { eid } = useLocalSearchParams<{ eid: string }>();
  const { t } = useI18n();
  const { c } = useTheme();
  return (
    <Screen center>
      <EmptyState icon={<Box size={28} color={c.brand} />} title={t('lp.simSoon')} body={t('lp.simSoonBody')} />
      <Button style={{ marginTop: space.xl }} variant="secondary" title={t('lp.allLessons')} onPress={() => router.replace(`/learn/${eid}`)} />
    </Screen>
  );
}
