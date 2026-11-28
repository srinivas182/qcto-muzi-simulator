import { Link, useParams } from 'react-router';
import { useMutation } from '@tanstack/react-query';
import { CheckCircle2, QrCode } from 'lucide-react';
import { Button, Card } from '../../components/ui';
import { api, ApiError } from '../../lib/api';
import { useI18n } from '../../lib/i18n';

/** Learner: the page the cohort QR code opens. One tap joins the class. */
export default function JoinCohort() {
  const { code = '' } = useParams();
  const { t } = useI18n();
  const join = useMutation({ mutationFn: () => api<{ data: { cohort: string } }>('/cohorts/join', { method: 'POST', body: { code } }) });
  const error = join.error instanceof ApiError ? Object.values(join.error.errors)[0]?.[0] ?? join.error.message : join.error ? t('common.error') : null;

  return (
    <div className="sw-in mx-auto max-w-md pt-6">
      <Card className="p-7 text-center">
        {join.data ? (
          <>
            <CheckCircle2 className="mx-auto size-12 text-[#1E7A46]" />
            <h1 className="mt-3 text-[22px] font-bold text-ink">{t('join.done', { cohort: join.data.data.cohort })}</h1>
            <Link to="/learn" className="mt-5 inline-flex h-11 items-center rounded-xl bg-brand px-5 text-[15px] font-semibold text-white">{t('join.toHome')}</Link>
          </>
        ) : (
          <>
            <QrCode className="mx-auto size-12 text-brand" />
            <h1 className="mt-3 text-[22px] font-bold text-ink">{t('join.title')}</h1>
            <p className="mt-2 text-[15px] text-muted">{t('join.lead')}</p>
            <p className="mt-4 font-mono text-[24px] font-bold tracking-[0.2em] text-ink">{code.toUpperCase()}</p>
            {error && <p role="alert" className="mt-4 rounded-xl bg-[#FDECEC] px-4 py-3 text-[14px] font-medium text-[#B42328]">{error}</p>}
            <Button size="lg" className="mt-6 w-full" loading={join.isPending} onClick={() => join.mutate()}>{t('join.button')}</Button>
          </>
        )}
      </Card>
    </div>
  );
}
