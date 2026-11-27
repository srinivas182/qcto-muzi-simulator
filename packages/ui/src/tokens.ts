/**
 * Design tokens shared by web (CSS variables in tokens.css) and mobile (React Native styles).
 * Values match the approved UI/UX mock-ups v1.2.
 */
export const colors = {
  midnight: '#0D1230',
  midnight2: '#161C45',
  indigo: '#4B45D6',
  indigoLight: '#EEEDFC',
  gold: '#C9A24B',
  goldLight: '#FBF5E6',
  emerald: '#12A27C',
  emeraldLight: '#E6F6F1',
  coral: '#E5484D',
  coralLight: '#FDECEC',
  amber: '#E8A21B',
  amberLight: '#FDF4E1',
  sky: '#2F80ED',
  skyLight: '#E9F2FE',
  ink: '#151933',
  muted: '#6B7090',
  line: '#E9EAF2',
  canvas: '#F5F6FA',
} as const;

export const radius = { sm: 8, md: 10, lg: 14, xl: 18, pill: 999 } as const;

export const font = {
  ui: "'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif",
  display: "'Lora', Georgia, serif",
} as const;

export type ColorToken = keyof typeof colors;
