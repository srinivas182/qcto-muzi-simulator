import type { ProviderStatus } from '@simuwork/core';

export const PROVIDERS_KEY = ['hq-providers'] as const;
export const STATUS_TONE: Record<ProviderStatus, 'success' | 'brand' | 'warning' | 'neutral'> = { active: 'success', trial: 'brand', suspended: 'warning', archived: 'neutral' };
