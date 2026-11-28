import { Link, useNavigate } from 'react-router';
import { useQuery } from '@tanstack/react-query';
import { ArrowLeft } from 'lucide-react';
import type { QctoDetails } from '@simuwork/core';
import { QctoForm } from '../../components/qcto/QctoDetailsDrawer';
import { Card, Skeleton } from '../../components/ui';
import { api } from '../../lib/api';
import { useI18n } from '../../lib/i18n';

/** Learner: fill in my own details for QCTO. */
export default function MyDetails() {
  const { t } = useI18n();
  const navigate = useNavigate();
  const details = useQuery({ queryKey: ['my-qcto'], queryFn: () => api<{ data: QctoDetails }>('/learn/me/qcto') });
  return (
    <div className="sw-in mx-auto max-w-2xl">
      <Link to="/learn" className="mb-3 inline-flex items-center gap-1.5 text-[13px] font-semibold text-muted hover:text-ink"><ArrowLeft className="size-4" />{t('lh.back')}</Link>
      <h1 className="text-[26px] font-bold tracking-tight text-ink">{t('lh.detailsPage')}</h1>
      <p className="mt-1 text-[15px] text-muted">{t('lh.detailsLead')}</p>
      <Card className="mt-6 p-6">
        {details.isError ? <p className="text-[14px] text-muted">{t('lh.notRegistered')}</p> : !details.data ? <Skeleton className="h-96 rounded-xl" /> : (
          <QctoForm self endpoint="/learn/me/qcto" queryKey={['my-qcto']} details={details.data.data} hasSaId={details.data.data.alt_id_type === null} onDone={() => void navigate('/learn')} />
        )}
      </Card>
    </div>
  );
}
