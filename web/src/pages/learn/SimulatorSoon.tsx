import { Link, useParams } from 'react-router';
import { Box } from 'lucide-react';
import { Card } from '../../components/ui';
import { useI18n } from '../../lib/i18n';

/** Placeholder until the 3D simulator runtime arrives (Sprint 4.4). */
export default function SimulatorSoon() {
  const { eid = '' } = useParams();
  const { t } = useI18n();
  return (
    <div className="sw-in mx-auto max-w-md pt-6">
      <Card className="p-7 text-center">
        <Box className="mx-auto size-12 text-brand" />
        <h1 className="mt-3 text-[20px] font-bold text-ink">{t('lp.simSoon')}</h1>
        <p className="mt-2 text-[15px] text-muted">{t('lp.simSoonBody')}</p>
        <Link to={`/learn/enrolments/${eid}`} className="mt-5 inline-block text-[14px] font-semibold text-brand hover:underline">{t('lp.allLessons')}</Link>
      </Card>
    </div>
  );
}
