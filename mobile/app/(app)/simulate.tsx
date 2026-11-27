import { Box } from '../../src/ui/icons';
import { useI18n } from '../../src/lib/i18n';
import { Soon } from '../../src/screens/Soon';
import { useTheme } from '../../src/ui';

export default function Screen() {
  const { t } = useI18n();
  const { c } = useTheme();
  return <Soon title={t('nav.learn.simulate')} icon={<Box size={28} color={c.brand} />} />;
}
