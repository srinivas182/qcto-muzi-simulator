/**
 * Which portal this page is: HQ (Super Admin, on the admin.* host) or a training provider
 * (its sub-domain or custom domain). Set VITE_AREA=hq to force HQ in development.
 */
export type Area = 'hq' | 'tenant';

export function detectArea(hostname: string, forced?: string): Area {
  if (forced === 'hq' || forced === 'tenant') return forced;
  return hostname.startsWith('admin.') ? 'hq' : 'tenant';
}

export const AREA: Area = detectArea(
  typeof location === 'undefined' ? '' : location.hostname,
  import.meta.env.VITE_AREA as string | undefined,
);

/** API prefix for HQ-only routes (auth, session, roles, audit at HQ live under /platform). */
export const areaPrefix = (area: Area = AREA) => (area === 'hq' ? '/platform' : '');
