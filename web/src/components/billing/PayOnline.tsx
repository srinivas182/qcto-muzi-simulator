import { useMutation, useQuery } from '@tanstack/react-query';
import { CreditCard } from 'lucide-react';
import type { PaymentOption } from '@simuwork/core';
import { Button, useToast } from '../ui';
import { api, ApiError } from '../../lib/api';
import { useI18n } from '../../lib/i18n';

type Checkout = { type: 'redirect'; url: string } | { type: 'form'; action: string; fields: Record<string, string> };

/** Sends the browser to the gateway: a plain redirect, or an auto-submitted form for form-post gateways. */
function go(checkout: Checkout) {
  if (checkout.type === 'redirect') {
    window.location.assign(checkout.url);
    return;
  }
  const form = document.createElement('form');
  form.method = 'POST';
  form.action = checkout.action;
  for (const [name, value] of Object.entries(checkout.fields)) {
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = name;
    input.value = value;
    form.appendChild(input);
  }
  document.body.appendChild(form);
  form.submit();
}

/** "Pay online" buttons for the gateways Super Admin has switched on. */
export function PayOnline({ invoiceId }: { invoiceId: string }) {
  const { t } = useI18n();
  const toast = useToast();
  const options = useQuery({ queryKey: ['payment-options'], queryFn: async () => (await api<{ data: PaymentOption[] }>('/payment-options')).data });
  const pay = useMutation({
    mutationFn: (gateway: string) => api<{ checkout: Checkout }>(`/invoices/${invoiceId}/pay`, { method: 'POST', body: { gateway, return_url: `${window.location.origin}/admin/billing` } }),
    onSuccess: (res) => go(res.checkout),
    onError: (e) => toast(e instanceof ApiError ? Object.values(e.errors)[0]?.[0] ?? e.message : t('common.error'), 'error'),
  });
  if (!options.data?.length) return null;
  return (
    <div>
      <h3 className="mb-2 text-[12px] font-bold uppercase tracking-[0.1em] text-muted">{t('pay.online')}</h3>
      <div className="flex flex-wrap gap-2">
        {options.data.map((o) => (
          <Button key={o.driver} icon={<CreditCard className="size-4" />} loading={pay.isPending && pay.variables === o.driver} disabled={pay.isPending} onClick={() => pay.mutate(o.driver)}>
            {t('pay.with', { name: o.label })}{o.test_mode ? ` (${t('gw.test')})` : ''}
          </Button>
        ))}
      </div>
      <p className="mt-2 text-[12px] text-muted">{t('pay.secure')}</p>
    </div>
  );
}
