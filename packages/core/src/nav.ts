import type { NavItem } from './types';

/** Group menu items by section, keeping their configured order. */
export function sections(items: NavItem[]): [string, NavItem[]][] {
  const map = new Map<string, NavItem[]>();
  for (const item of items) map.set(item.section, [...(map.get(item.section) ?? []), item]);
  return [...map.entries()];
}

/** The menu item a path belongs to (longest matching path). */
export function activeItem(items: NavItem[], pathname: string): NavItem | undefined {
  return [...items]
    .filter((i) => pathname === i.path || pathname.startsWith(i.path + '/'))
    .sort((a, b) => b.path.length - a.path.length)[0];
}
