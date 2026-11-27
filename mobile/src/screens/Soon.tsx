import type { ReactNode } from 'react';
import { useI18n } from '../lib/i18n';
import { EmptyState, Screen } from '../ui';

/** Tabs whose content arrives with the simulator and course sprints. */
export function Soon({ title, icon }: { title: string; icon: ReactNode }) {
  const { t } = useI18n();
  return (
    <Screen title={title}>
      <EmptyState icon={icon} title={t('common.comingSoon.title')} body={t('common.comingSoon.body')} />
    </Screen>
  );
}
