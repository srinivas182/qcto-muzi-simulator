/**
 * Minimal .xlsx reader for learner lists: first worksheet, values only. Uses the browser's own unzip
 * (DecompressionStream) and XML parser, so it adds ~2 KB instead of a spreadsheet library.
 */
type Entry = { method: number; size: number; offset: number };

function entries(buf: ArrayBuffer): Map<string, Entry> {
  const v = new DataView(buf);
  let eocd = buf.byteLength - 22;
  while (eocd >= 0 && v.getUint32(eocd, true) !== 0x06054b50) eocd--;
  if (eocd < 0) throw new Error('not a zip file');
  const count = v.getUint16(eocd + 10, true);
  let p = v.getUint32(eocd + 16, true);
  const out = new Map<string, Entry>();
  const dec = new TextDecoder();
  for (let i = 0; i < count; i++) {
    const nameLen = v.getUint16(p + 28, true);
    const name = dec.decode(new Uint8Array(buf, p + 46, nameLen));
    out.set(name, { method: v.getUint16(p + 10, true), size: v.getUint32(p + 20, true), offset: v.getUint32(p + 42, true) });
    p += 46 + nameLen + v.getUint16(p + 30, true) + v.getUint16(p + 32, true);
  }
  return out;
}

async function read(buf: ArrayBuffer, e: Entry | undefined): Promise<string | null> {
  if (!e) return null;
  const v = new DataView(buf);
  const start = e.offset + 30 + v.getUint16(e.offset + 26, true) + v.getUint16(e.offset + 28, true);
  const raw = new Uint8Array(buf, start, e.size);
  if (e.method === 0) return new TextDecoder().decode(raw);
  const stream = new Response(raw).body!.pipeThrough(new DecompressionStream('deflate-raw'));
  return new Response(stream).text();
}

const col = (ref: string) => [...ref.replace(/\d+$/, '')].reduce((n, c) => n * 26 + c.charCodeAt(0) - 64, 0) - 1;

export async function readXlsx(file: Blob): Promise<unknown[][]> {
  const buf = await file.arrayBuffer();
  const zip = entries(buf);
  const xml = (s: string | null) => new DOMParser().parseFromString(s ?? '<x/>', 'application/xml');
  const shared = [...xml(await read(buf, zip.get('xl/sharedStrings.xml'))).getElementsByTagName('si')].map((si) => [...si.getElementsByTagName('t')].map((t) => t.textContent ?? '').join(''));
  const sheetName = [...zip.keys()].filter((k) => /^xl\/worksheets\/sheet\d+\.xml$/.test(k)).sort()[0];
  const sheet = xml(await read(buf, sheetName ? zip.get(sheetName) : undefined));
  return [...sheet.getElementsByTagName('row')].map((row) => {
    const cells: unknown[] = [];
    for (const c of row.getElementsByTagName('c')) {
      const type = c.getAttribute('t');
      const value = c.getElementsByTagName('v')[0]?.textContent ?? c.getElementsByTagName('t')[0]?.textContent ?? '';
      cells[col(c.getAttribute('r') ?? 'A')] = type === 's' ? shared[Number(value)] ?? '' : type === 'str' || type === 'inlineStr' || type === 'b' ? value : value === '' ? '' : Number(value);
    }
    return Array.from(cells, (x) => x ?? '');
  });
}
