import { useEffect, useRef, useState, type ReactNode } from 'react';
import { Check, Languages, Monitor, Moon, Sun } from 'lucide-react';
import { api } from '../../lib/api';
import { areaPrefix } from '../../lib/area';
import { LOCALES, useI18n, type Locale } from '../../lib/i18n';
import { applyTheme, storedTheme, type ThemeMode } from '../../lib/theme';
import { cx } from '../ui';

/** Small popover menu that closes on outside click and Esc. */
export function Popover({ button, children, align = 'right' }: { button: (open: boolean, toggle: () => void) => ReactNode; children: (close: () => void) => ReactNode; align?: 'left' | 'right' }) {
  const [open, setOpen] = useState(false);
  const ref = useRef<HTMLDivElement>(null);
  useEffect(() => {
    if (!open) return;
    const onDown = (e: MouseEvent) => !ref.current?.contains(e.target as Node) && setOpen(false);
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && setOpen(false);
    document.addEventListener('mousedown', onDown);
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('mousedown', onDown);
      document.removeEventListener('keydown', onKey);
    };
  }, [open]);
  return (
    <div ref={ref} className="relative">
      {button(open, () => setOpen((o) => !o))}
      {open && (
        <div role="menu" className={cx('sw-in absolute top-full z-40 mt-2 min-w-52 rounded-xl border border-line bg-surface p-1.5 shadow-xl', align === 'right' ? 'right-0' : 'left-0')}>
          {children(() => setOpen(false))}
        </div>
      )}
    </div>
  );
}

export function MenuItem({ children, onClick, active, icon }: { children: ReactNode; onClick: () => void; active?: boolean; icon?: ReactNode }) {
  return (
    <button role="menuitem" onClick={onClick} className="flex w-full items-center gap-2.5 rounded-lg px-2.5 py-2 text-left text-[14px] text-ink hover:bg-surface-2">
      {icon}
      <span className="flex-1">{children}</span>
      {active && <Check className="size-4 text-brand" aria-hidden />}
    </button>
  );
}

/** Saves the choice to the account when signed in, so mobile and web agree. */
export function useChangeLocale(signedIn: boolean) {
  const { setLocale } = useI18n();
  return (l: Locale) => {
    setLocale(l);
    if (signedIn) api(areaPrefix() + '/auth/preferences', { method: 'PATCH', body: { locale: l } }).catch(() => {});
  };
}

export function LanguageMenu({ signedIn, compact }: { signedIn: boolean; compact?: boolean }) {
  const { locale, t } = useI18n();
  const change = useChangeLocale(signedIn);
  return (
    <Popover
      button={(open, toggle) => (
        <button onClick={toggle} aria-expanded={open} aria-haspopup="menu" aria-label={`${t('common.language')}: ${LOCALES[locale]}`} className="inline-flex h-9 items-center gap-1.5 rounded-xl px-2.5 text-[13px] font-semibold text-muted hover:bg-surface-2 hover:text-ink">
          <Languages className="size-4" aria-hidden />
          {!compact && LOCALES[locale]}
        </button>
      )}
    >
      {(close) =>
        (Object.keys(LOCALES) as Locale[]).map((l) => (
          <MenuItem key={l} active={l === locale} onClick={() => { change(l); close(); }}>
            {LOCALES[l]}
          </MenuItem>
        ))
      }
    </Popover>
  );
}

export function ThemeMenu() {
  const { t } = useI18n();
  const [mode, setMode] = useState<ThemeMode>(storedTheme());
  const choose = (m: ThemeMode) => { setMode(m); applyTheme(m); };
  const options: [ThemeMode, ReactNode, string][] = [
    ['light', <Sun key="l" className="size-4" />, t('common.light')],
    ['dark', <Moon key="d" className="size-4" />, t('common.dark')],
    ['system', <Monitor key="s" className="size-4" />, t('common.system')],
  ];
  return (
    <Popover
      button={(open, toggle) => (
        <button onClick={toggle} aria-expanded={open} aria-haspopup="menu" aria-label={t('common.theme')} className="inline-flex size-9 items-center justify-center rounded-xl text-muted hover:bg-surface-2 hover:text-ink">
          <Sun className="size-[18px] dark:hidden" aria-hidden />
          <Moon className="hidden size-[18px] dark:block" aria-hidden />
        </button>
      )}
    >
      {(close) => options.map(([m, icon, label]) => (
        <MenuItem key={m} icon={icon} active={mode === m} onClick={() => { choose(m); close(); }}>{label}</MenuItem>
      ))}
    </Popover>
  );
}
