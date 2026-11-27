export const LOCALES = { en: 'English', zu: 'isiZulu', af: 'Afrikaans', xh: 'isiXhosa' } as const;
export type Locale = keyof typeof LOCALES;

export const isLocale = (v: unknown): v is Locale => typeof v === 'string' && v in LOCALES;

/** Fill {placeholders}; unknown ones are left as they are. */
export function format(template: string, vars?: Record<string, string | number>): string {
  if (!vars) return template;
  return template.replace(/\{(\w+)\}/g, (m, k: string) => (k in vars ? String(vars[k]) : m));
}

export const sectionKey = (section: string) => 'section.' + section.toLowerCase().replace(/\s+/g, '_');

/** Pick a supported language from device or browser preferences (e.g. ["zu-ZA", "en-ZA"]). */
export function pickLocale(preferred: readonly string[]): Locale {
  for (const tag of preferred) {
    const base = tag.toLowerCase().split(/[-_]/)[0];
    if (isLocale(base)) return base;
  }
  return 'en';
}
