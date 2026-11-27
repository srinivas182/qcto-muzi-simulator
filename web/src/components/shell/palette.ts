/** Ranking for the ⌘K palette: prefix and word-start matches beat matches inside words. */
export interface PaletteItem {
  id: string;
  label: string;
  group: 'pages' | 'actions';
  hint?: string;
  keywords?: string;
  run: () => void;
}

export function score(item: Pick<PaletteItem, 'label' | 'keywords'>, query: string): number {
  const q = query.trim().toLowerCase();
  if (!q) return 1;
  const label = item.label.toLowerCase();
  const hay = `${label} ${item.keywords?.toLowerCase() ?? ''}`;
  if (label.startsWith(q)) return 100;
  if (label.split(/\s+/).some((w) => w.startsWith(q))) return 60;
  if (hay.includes(q)) return 30;
  // all characters in order ("rls" → "roles")
  let i = 0;
  for (const ch of hay) if (ch === q[i]) i++;
  return i === q.length ? 10 : 0;
}

export function filterItems<T extends Pick<PaletteItem, 'label' | 'keywords'>>(items: T[], query: string): T[] {
  return items
    .map((item, index) => ({ item, index, s: score(item, query) }))
    .filter((x) => x.s > 0)
    .sort((a, b) => b.s - a.s || a.index - b.index)
    .map((x) => x.item);
}
