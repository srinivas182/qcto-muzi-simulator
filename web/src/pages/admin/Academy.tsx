import { useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { AlertTriangle } from 'lucide-react';
import type { ProviderDetails } from '@simuwork/core';
import { ProviderDetailsForm } from '../../components/ProviderDetailsForm';
import { PageHeader, Skeleton, useToast } from '../../components/ui';
import { api, ApiError } from '../../lib/api';
import { useI18n } from '../../lib/i18n';

/** The provider's own company, accreditation, contact and billing details. */
export default function Academy() {
  const { t } = useI18n();
  const toast = useToast();
  const qc = useQueryClient();
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const query = useQuery({ queryKey: ['academy'], queryFn: async () => (await api<{ data: ProviderDetails }>('/academy')).data });

  const save = async (changes: Record<string, unknown>) => {
    setErrors({});
    try {
      const res = await api<{ data: ProviderDetails }>('/academy', { method: 'PATCH', body: changes });
      qc.setQueryData(['academy'], res.data);
      toast(t('acad.saved'));
      return true;
    } catch (e) {
      if (e instanceof ApiError) setErrors(e.errors);
      toast(e instanceof ApiError ? e.message : t('common.error'), 'error');
      return false;
    }
  };

  const p = query.data;
  const warn = p && (p.accreditation.state === 'expiring' || p.accreditation.state === 'expired' || p.accreditation.state === 'missing');
  return (
    <div className="sw-in max-w-4xl">
      <PageHeader title={t('acad.title')} lead={t('acad.lead')} />
      {warn && (
        <p role="status" className="mb-5 flex items-start gap-2 rounded-xl bg-[#FDF4E1] px-4 py-3 text-[14px] font-medium text-[#8A5A00] dark:bg-[#8A5A00]/25 dark:text-[#F5C46B]">
          <AlertTriangle className="mt-0.5 size-4 shrink-0" />
          {p.accreditation.state === 'expiring' ? t('acad.warn.expiring', { n: p.accreditation.days_left ?? 0 }) : t(p.accreditation.state === 'expired' ? 'acad.warn.expired' : 'acad.warn.missing')}
        </p>
      )}
      {p ? <ProviderDetailsForm key={p.id} provider={p} scope="provider" editable onSave={save} errors={errors} /> : <Skeleton className="h-96" />}
    </div>
  );
}
