import { useCallback } from 'react';
import { useQuery } from '@tanstack/react-query';
import type { QctoList } from '@simuwork/core';
import type { Locale } from '@simuwork/core';
import { useI18n } from './i18n';

/** QCTO code labels are only needed on two screens, so each language's labels load on demand (~1.5 KB). */
const loaders: Record<Locale, () => Promise<{ default: Record<string, string> }>> = {
  en: () => import('@simuwork/core/locales/qcto-en'),
  zu: () => import('@simuwork/core/locales/qcto-zu'),
  af: () => import('@simuwork/core/locales/qcto-af'),
  xh: () => import('@simuwork/core/locales/qcto-xh'),
};

/** Returns label(list, code); until the labels arrive it shows the code itself. */
export function useQctoLabel(): (list: QctoList, code: string) => string {
  const { locale } = useI18n();
  const labels = useQuery({ queryKey: ['qcto-labels', locale], queryFn: async () => (await loaders[locale]()).default, staleTime: Infinity });
  return useCallback((list, code) => labels.data?.[`${list}.${code}`] ?? code, [labels.data]);
}
