import { useEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { CornerDownLeft, Search } from 'lucide-react';
import { useI18n } from '../../lib/i18n';
import { cx, Kbd } from '../ui';
import { filterItems, type PaletteItem } from './palette';

/** Render only while open: every opening starts with a fresh, empty search. */
export function CommandPalette({ onClose, items }: { onClose: () => void; items: PaletteItem[] }) {
  const { t } = useI18n();
  const [query, setQuery] = useState('');
  const [active, setActive] = useState(0);
  const input = useRef<HTMLInputElement>(null);
  const results = useMemo(() => filterItems(items, query), [items, query]);

  useEffect(() => {
    const id = requestAnimationFrame(() => input.current?.focus());
    return () => cancelAnimationFrame(id);
  }, []);

  const run = (item: PaletteItem | undefined) => {
    if (!item) return;
    onClose();
    item.run();
  };
  const onKey = (e: React.KeyboardEvent) => {
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      setActive((a) => Math.min(a + 1, results.length - 1));
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      setActive((a) => Math.max(a - 1, 0));
    } else if (e.key === 'Enter') {
      e.preventDefault();
      run(results[active]);
    } else if (e.key === 'Escape') onClose();
  };

  return createPortal(
    <div className="fixed inset-0 z-[55] flex items-start justify-center px-4 pt-[12vh]">
      <div className="sw-fade absolute inset-0 bg-[#090c1c]/45 backdrop-blur-[2px]" onClick={onClose} />
      <div role="dialog" aria-modal="true" aria-label={t('common.search')} className="sw-in relative w-full max-w-xl overflow-hidden rounded-2xl border border-line bg-surface shadow-2xl">
        <div className="flex items-center gap-3 border-b border-line px-4">
          <Search className="size-5 text-muted" aria-hidden />
          <input
            ref={input}
            value={query}
            onChange={(e) => { setQuery(e.target.value); setActive(0); }}
            onKeyDown={onKey}
            placeholder={t('palette.placeholder')}
            className="h-14 flex-1 bg-transparent text-[15px] text-ink placeholder:text-muted focus:outline-none"
            role="combobox"
            aria-expanded="true"
            aria-controls="sw-palette-list"
            aria-activedescendant={results[active] ? `sw-pal-${results[active].id}` : undefined}
          />
          <Kbd>Esc</Kbd>
        </div>
        <ul id="sw-palette-list" role="listbox" className="max-h-[50vh] overflow-y-auto p-2">
          {results.length === 0 && <li className="px-3 py-8 text-center text-sm text-muted">{t('palette.empty')}</li>}
          {results.map((item, i) => {
            const header = i === 0 || results[i - 1]?.group !== item.group ? t(item.group === 'pages' ? 'palette.pages' : 'palette.actions') : null;
            return (
              <li key={item.id} role="presentation">
                {header && <div className="px-3 pb-1 pt-3 text-[11px] font-bold uppercase tracking-wider text-muted">{header}</div>}
                <div
                  id={`sw-pal-${item.id}`}
                  role="option"
                  aria-selected={i === active}
                  onMouseMove={() => setActive(i)}
                  onClick={() => run(item)}
                  className={cx(
                    'flex cursor-pointer items-center justify-between rounded-xl px-3 py-2.5 text-[14px]',
                    i === active ? 'bg-brand-soft text-ink' : 'text-ink',
                  )}
                >
                  <span className="font-medium">{item.label}</span>
                  <span className="flex items-center gap-2 text-[12px] text-muted">
                    {item.hint}
                    {i === active && <CornerDownLeft className="size-3.5" aria-hidden />}
                  </span>
                </div>
              </li>
            );
          })}
        </ul>
      </div>
    </div>,
    document.body,
  );
}
