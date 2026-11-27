import type { ReactNode } from 'react';
import { CheckSquare, Square } from 'lucide-react';

/**
 * Renders the small markdown subset used by the guide's reference pages (headings, paragraphs, bullet and
 * check lists, tables, **bold**). It builds React elements directly, so no HTML is ever injected.
 */
export function Markdown({ source }: { source: string }) {
  const blocks: ReactNode[] = [];
  const lines = source.replace(/\r\n/g, '\n').split('\n');
  let i = 0;

  while (i < lines.length) {
    const line = lines[i] ?? '';
    if (line.trim() === '') { i++; continue; }

    const heading = /^(#{1,4})\s+(.*)$/.exec(line);
    if (heading) {
      const level = heading[1]!.length;
      const text = inline(heading[2]!);
      blocks.push(level <= 2
        ? <h2 key={i} className="mt-8 text-[20px] font-bold tracking-tight text-ink first:mt-0">{text}</h2>
        : <h3 key={i} className="mt-6 text-[16px] font-bold text-ink">{text}</h3>);
      i++;
      continue;
    }

    if (line.trimStart().startsWith('|')) {
      const rows: string[][] = [];
      while (i < lines.length && (lines[i] ?? '').trimStart().startsWith('|')) {
        const cells = (lines[i] ?? '').trim().replace(/^\||\|$/g, '').split('|').map((c) => c.trim());
        if (!cells.every((c) => /^:?-{2,}:?$/.test(c))) rows.push(cells);
        i++;
      }
      const [head, ...body] = rows;
      blocks.push(
        <div key={i} className="my-4 overflow-x-auto rounded-xl border border-line">
          <table className="w-full text-left text-[14px]">
            {head && <thead className="bg-surface-2/60 text-[12px] font-bold uppercase tracking-[0.06em] text-muted"><tr>{head.map((c, k) => <th key={k} className="px-4 py-2.5">{inline(c)}</th>)}</tr></thead>}
            <tbody className="divide-y divide-line">{body.map((r, k) => <tr key={k}>{r.map((c, j) => <td key={j} className="px-4 py-2.5 align-top text-ink">{inline(c)}</td>)}</tr>)}</tbody>
          </table>
        </div>,
      );
      continue;
    }

    if (/^\s*[-*]\s+/.test(line)) {
      const items: { text: string; check: boolean | null }[] = [];
      while (i < lines.length && /^\s*[-*]\s+/.test(lines[i] ?? '')) {
        const raw = (lines[i] ?? '').replace(/^\s*[-*]\s+/, '');
        const box = /^\[( |x|X)\]\s+(.*)$/.exec(raw);
        items.push(box ? { text: box[2]!, check: box[1] !== ' ' } : { text: raw, check: null });
        i++;
      }
      blocks.push(
        <ul key={i} className="my-3 space-y-2">
          {items.map((it, k) => (
            <li key={k} className="flex items-start gap-2.5 text-[15px] leading-relaxed text-ink">
              {it.check === null ? <span aria-hidden className="mt-2.5 size-1.5 shrink-0 rounded-full bg-brand" />
                : it.check ? <CheckSquare className="mt-1 size-4 shrink-0 text-brand" aria-hidden /> : <Square className="mt-1 size-4 shrink-0 text-muted" aria-hidden />}
              <span>{inline(it.text)}</span>
            </li>
          ))}
        </ul>,
      );
      continue;
    }

    const para: string[] = [];
    while (i < lines.length && (lines[i] ?? '').trim() !== '' && !/^(#{1,4}\s|\s*[-*]\s|\s*\|)/.test(lines[i] ?? '')) {
      para.push((lines[i] ?? '').trim());
      i++;
    }
    blocks.push(<p key={i} className="my-3 text-[15px] leading-relaxed text-ink">{inline(para.join(' '))}</p>);
  }

  return <div className="max-w-3xl">{blocks}</div>;
}

/** **bold** only; everything else is plain text. */
function inline(text: string): ReactNode {
  const parts = text.split(/(\*\*[^*]+\*\*)/g);
  return parts.map((p, k) => (p.startsWith('**') && p.endsWith('**') ? <strong key={k} className="font-semibold">{p.slice(2, -2)}</strong> : p));
}
