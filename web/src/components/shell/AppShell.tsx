import { useCallback, useEffect, useMemo, useState } from 'react';
import { NavLink, useLocation, useNavigate } from 'react-router';
import { AccessGuard } from '../../app/access';
import { activeItem, sections } from './nav';
import { ChevronsLeft, ChevronsRight, LogOut, Menu, Search, ShieldAlert } from 'lucide-react';
import { AREA } from '../../lib/area';
import { navIcon } from '../../lib/icons';
import { LOCALES, sectionKey, useI18n, type Locale } from '../../lib/i18n';
import { homePath, useSignOut } from '../../lib/session';
import { applyTheme, isDark } from '../../lib/theme';
import type { NavItem, Session } from '../../lib/types';
import { Avatar, cx, Drawer, Kbd } from '../ui';
import { CommandPalette } from './CommandPalette';
import { ConsentGate } from './ConsentGate';
import { LanguageMenu, MenuItem, Popover, ThemeMenu, useChangeLocale } from './Menus';
import type { PaletteItem } from './palette';

const COLLAPSE_KEY = 'sw-nav-collapsed';
const isMac = typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.platform);

function BrandMark({ session, collapsed }: { session: Session; collapsed: boolean }) {
  const { t } = useI18n();
  const branding = session.tenant?.branding;
  const name = branding?.display_name ?? t('auth.hqTitle');
  return (
    <div className="flex h-16 items-center gap-3 px-4">
      {branding?.logo_url ? (
        <img src={branding.logo_url} alt="" className="size-9 shrink-0 rounded-xl bg-white object-contain p-1" />
      ) : (
        <span className="flex size-9 shrink-0 items-center justify-center rounded-xl bg-brand text-[15px] font-bold text-white shadow-inner">
          {name.slice(0, 1)}
        </span>
      )}
      {!collapsed && (
        <div className="min-w-0">
          <div className="truncate text-[15px] font-bold text-white">{name}</div>
          {AREA === 'hq' && <div className="text-[11px] font-semibold uppercase tracking-[0.14em] text-accent">{t('shell.hq')}</div>}
        </div>
      )}
    </div>
  );
}

/** Scroll only the menu (never the page) so the current item is visible. */
function revealInMenu(el: HTMLElement | null) {
  const menu = el?.closest('nav');
  if (!el || !menu || menu.scrollHeight <= menu.clientHeight) return;
  const top = el.offsetTop - menu.offsetTop;
  if (top < menu.scrollTop || top + el.offsetHeight > menu.scrollTop + menu.clientHeight) {
    menu.scrollTop = top - menu.clientHeight / 2;
  }
}

function NavList({ items, collapsed, onNavigate }: { items: NavItem[]; collapsed: boolean; onNavigate?: () => void }) {
  const { tx } = useI18n();
  const location = useLocation();
  const current = activeItem(items, location.pathname);

  return (
    <nav aria-label="Main" className="flex-1 overflow-y-auto px-3 pb-4">
      {sections(items).map(([section, list]) => (
        <div key={section} className="mt-3 first:mt-0">
          {!collapsed && (
            <div className="px-3 pb-1 text-[11px] font-bold uppercase tracking-[0.12em] text-white/45">{tx(sectionKey(section), section)}</div>
          )}
          <ul className="space-y-0.5">
            {list.map((item) => {
              const Icon = navIcon(item.icon);
              const label = tx('nav.' + item.id, item.label);
              const active = current?.id === item.id;
              return (
                <li key={item.id}>
                  <NavLink
                    to={item.path}
                    end
                    ref={active ? revealInMenu : undefined}
                    onClick={onNavigate}
                    title={collapsed ? label : undefined}
                    aria-current={active ? 'page' : undefined}
                    className={cx(
                      'group relative flex h-9 items-center gap-3 rounded-xl px-3 text-[14px] font-medium transition',
                      active ? 'bg-white/12 text-white' : 'text-white/70 hover:bg-white/[0.07] hover:text-white',
                      collapsed && 'justify-center px-0',
                    )}
                  >
                    {active && <span aria-hidden className="absolute left-0 top-2 bottom-2 w-[3px] rounded-full bg-accent" />}
                    <Icon className="size-[18px] shrink-0" aria-hidden />
                    {!collapsed && <span className="truncate">{label}</span>}
                  </NavLink>
                </li>
              );
            })}
          </ul>
        </div>
      ))}
    </nav>
  );
}

function SupportBanner({ session }: { session: Session }) {
  const { t, locale } = useI18n();
  if (!session.support) return null;
  const time = new Date(session.support.expires_at).toLocaleTimeString(locale, { hour: '2-digit', minute: '2-digit' });
  return (
    <div role="status" className="flex flex-wrap items-center justify-center gap-x-3 gap-y-1 bg-[#E8A21B] px-4 py-2 text-center text-[13px] font-semibold text-[#2B1D00]">
      <ShieldAlert className="size-4" aria-hidden />
      <span>{t('shell.support', { name: session.support.staff_name })}</span>
      <span className="font-normal">{t('shell.supportReason', { reason: session.support.reason })} · {t('shell.supportEnds', { time })}</span>
    </div>
  );
}

export function AppShell({ session }: { session: Session }) {
  const { t, tx } = useI18n();
  const navigate = useNavigate();
  const location = useLocation();
  const signOut = useSignOut();
  const changeLocale = useChangeLocale(true);
  const [collapsed, setCollapsed] = useState(() => {
    try {
      return localStorage.getItem(COLLAPSE_KEY) === '1';
    } catch {
      return false;
    }
  });
  const [mobileOpen, setMobileOpen] = useState(false);
  const [paletteOpen, setPaletteOpen] = useState(false);

  const items = session.navigation;
  const learnerOnly = items.length > 0 && items.every((i) => i.id === 'learn' || i.id.startsWith('learn.'));
  const current = activeItem(items, location.pathname);

  const toggleCollapsed = () =>
    setCollapsed((c) => {
      try {
        localStorage.setItem(COLLAPSE_KEY, c ? '0' : '1');
      } catch {
        /* ignore */
      }
      return !c;
    });

  const doSignOut = useCallback(() => signOut.mutate(undefined, { onSettled: () => navigate('/sign-in', { replace: true }) }), [signOut, navigate]);

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      const typing = e.target instanceof HTMLElement && (e.target.closest('input, textarea, select, [contenteditable]') !== null);
      if ((e.key === 'k' || e.key === 'K') && (e.metaKey || e.ctrlKey)) {
        e.preventDefault();
        setPaletteOpen((o) => !o);
      } else if (e.key === '/' && !typing) {
        e.preventDefault();
        setPaletteOpen(true);
      }
    };
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, []);

  useEffect(() => {
    const root = document.documentElement;
    if (learnerOnly) root.dataset.tabbar = '';
    else delete root.dataset.tabbar;
    return () => {
      delete root.dataset.tabbar;
    };
  }, [learnerOnly]);

  const paletteItems = useMemo<PaletteItem[]>(() => [
    ...items.map<PaletteItem>((i) => ({
      id: 'nav-' + i.id,
      group: 'pages',
      label: tx('nav.' + i.id, i.label),
      hint: tx(sectionKey(i.section), i.section),
      keywords: `${i.label} ${i.section} ${i.path}`,
      run: () => navigate(i.path),
    })),
    { id: 'theme', group: 'actions', label: t('palette.toggleTheme'), keywords: 'dark light mode theme', run: () => applyTheme(isDark() ? 'light' : 'dark') },
    ...(Object.keys(LOCALES) as Locale[]).map<PaletteItem>((l) => ({
      id: 'lang-' + l, group: 'actions', label: t('palette.language', { name: LOCALES[l] }), keywords: 'language ' + l, run: () => changeLocale(l),
    })),
    { id: 'signout', group: 'actions', label: t('common.signOut'), keywords: 'logout log out', run: doSignOut },
  ], [items, t, tx, navigate, changeLocale, doSignOut]);

  return (
    <div className="flex min-h-dvh">
      <a href="#main" className="sr-only focus:not-sr-only focus:fixed focus:left-3 focus:top-3 focus:z-[70] focus:rounded-lg focus:bg-surface focus:px-3 focus:py-2 focus:shadow">
        {t('shell.skip')}
      </a>

      {!learnerOnly && (
        <aside className={cx('sticky top-0 hidden h-dvh shrink-0 flex-col bg-nav transition-[width] duration-200 lg:flex', collapsed ? 'w-[76px]' : 'w-64')}>
          <BrandMark session={session} collapsed={collapsed} />
          <NavList items={items} collapsed={collapsed} />
          <button
            onClick={toggleCollapsed}
            className="m-3 flex h-9 items-center justify-center gap-2 rounded-xl text-[13px] font-medium text-white/60 hover:bg-white/[0.07] hover:text-white"
            aria-label={collapsed ? t('shell.expand') : t('shell.collapse')}
          >
            {collapsed ? <ChevronsRight className="size-4" /> : <><ChevronsLeft className="size-4" />{t('shell.collapse')}</>}
          </button>
        </aside>
      )}

      <div className="flex min-w-0 flex-1 flex-col">
        <SupportBanner session={session} />
        <header className="sticky top-0 z-30 flex h-16 items-center gap-2 border-b border-line bg-bg/85 px-3 backdrop-blur-md sm:px-5">
          {!learnerOnly && (
            <button onClick={() => setMobileOpen(true)} className="inline-flex size-10 items-center justify-center rounded-xl text-ink hover:bg-surface-2 lg:hidden" aria-label={t('shell.menu')}>
              <Menu className="size-5" />
            </button>
          )}
          {learnerOnly && (
            <>
              <div className="mr-4"><BrandInline session={session} /></div>
              <nav aria-label="Main" className="hidden items-center gap-1 lg:flex">
                {items.map((item) => (
                  <NavLink key={item.id} to={item.path} end className={({ isActive }) => cx('rounded-xl px-3 py-2 text-[14px] font-semibold', isActive ? 'bg-brand-soft text-brand' : 'text-muted hover:text-ink')}>
                    {tx('nav.' + item.id, item.label)}
                  </NavLink>
                ))}
              </nav>
            </>
          )}
          <div className="hidden min-w-0 items-center gap-2 text-[14px] md:flex">
            {current && !learnerOnly && (
              <>
                <span className="text-muted">{tx(sectionKey(current.section), current.section)}</span>
                <span className="text-line">/</span>
                <span className="truncate font-semibold text-ink">{tx('nav.' + current.id, current.label)}</span>
              </>
            )}
          </div>

          <div className="ml-auto flex items-center gap-1">
            <button
              onClick={() => setPaletteOpen(true)}
              className="flex h-9 items-center gap-2 rounded-xl border border-line bg-surface px-2.5 text-[13px] text-muted shadow-xs hover:border-brand/40 hover:text-ink sm:w-56 sm:px-3"
              aria-label={t('common.search')}
            >
              <Search className="size-4" aria-hidden />
              <span className="hidden flex-1 text-left sm:inline">{t('common.search')}…</span>
              <span className="hidden items-center gap-0.5 sm:flex"><Kbd>{isMac ? '⌘' : 'Ctrl'}</Kbd><Kbd>K</Kbd></span>
            </button>
            <LanguageMenu signedIn compact />
            <ThemeMenu />
            <Popover
              button={(open, toggle) => (
                <button onClick={toggle} aria-expanded={open} aria-haspopup="menu" aria-label={t('shell.account')} className="ml-1 rounded-full ring-offset-2 ring-offset-bg hover:ring-2 hover:ring-line">
                  <Avatar name={session.user.name} />
                </button>
              )}
            >
              {() => (
                <>
                  <div className="border-b border-line px-2.5 pb-2.5 pt-1.5">
                    <div className="text-[14px] font-semibold text-ink">{session.user.name}</div>
                    <div className="truncate text-[12px] text-muted">{session.user.email ?? session.user.phone}</div>
                  </div>
                  <div className="pt-1">
                    <MenuItem icon={<LogOut className="size-4" />} onClick={doSignOut}>{t('common.signOut')}</MenuItem>
                  </div>
                </>
              )}
            </Popover>
          </div>
        </header>

        <main id="main" tabIndex={-1} className="mx-auto w-full max-w-[1240px] flex-1 px-4 py-6 outline-none sm:px-6 lg:px-10 lg:py-9">
          <AccessGuard session={session} />
        </main>
      </div>

      {learnerOnly && (
        <nav aria-label="Main" className="fixed inset-x-0 bottom-0 z-30 border-t border-line bg-surface/95 pb-[env(safe-area-inset-bottom)] backdrop-blur-md lg:hidden">
          <ul className="mx-auto flex max-w-md justify-around">
            {items.map((item) => {
              const Icon = navIcon(item.icon);
              const active = current?.id === item.id;
              return (
                <li key={item.id} className="flex-1">
                  <NavLink to={item.path} end aria-current={active ? 'page' : undefined} className={cx('flex flex-col items-center gap-1 py-2.5 text-[11px] font-semibold', active ? 'text-brand' : 'text-muted')}>
                    <Icon className="size-[22px]" aria-hidden />
                    {tx('nav.' + item.id, item.label)}
                  </NavLink>
                </li>
              );
            })}
          </ul>
        </nav>
      )}

      <Drawer open={mobileOpen} onClose={() => setMobileOpen(false)} side="left" width="max-w-[300px]" title={session.tenant?.branding.display_name ?? t('auth.hqTitle')}>
        <div className="-mx-6 -my-5 min-h-full bg-nav py-2">
          <NavList items={items} collapsed={false} onNavigate={() => setMobileOpen(false)} />
        </div>
      </Drawer>

      {paletteOpen && <CommandPalette onClose={() => setPaletteOpen(false)} items={paletteItems} />}
      {AREA === 'tenant' && !session.support && session.user.consent_required.length > 0 && <ConsentGate purposes={session.user.consent_required} />}
      <HomeHint session={session} />
    </div>
  );
}

function BrandInline({ session }: { session: Session }) {
  const b = session.tenant?.branding;
  return (
    <NavLink to={homePath(session)} className="flex items-center gap-2.5">
      {b?.logo_url ? <img src={b.logo_url} alt="" className="size-8 rounded-lg object-contain" /> : <span className="flex size-8 items-center justify-center rounded-lg bg-brand text-sm font-bold text-white">{(b?.display_name ?? 'S').slice(0, 1)}</span>}
      <span className="max-w-[38vw] truncate text-[15px] font-bold text-ink sm:max-w-none">{b?.display_name}</span>
    </NavLink>
  );
}

/** Sends people who land on "/" to their own home. */
function HomeHint({ session }: { session: Session }) {
  const location = useLocation();
  const navigate = useNavigate();
  useEffect(() => {
    if (location.pathname === '/') navigate(homePath(session), { replace: true });
  }, [location.pathname, navigate, session]);
  return null;
}
