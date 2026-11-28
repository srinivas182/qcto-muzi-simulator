/** Turn a CSV or Excel file of learners into rows for POST /learners/import. Headers are matched loosely. */
export type ImportRow = Record<'first_names' | 'surname' | 'id_number' | 'phone' | 'email' | 'home_language', string>;

const HEADERS: Record<keyof ImportRow, string[]> = {
  first_names: ['first names', 'first name', 'firstname', 'names', 'name'],
  surname: ['surname', 'last name', 'lastname', 'family name'],
  id_number: ['id number', 'id', 'sa id', 'id no', 'identity number', 'passport', 'passport number', 'id or passport'],
  phone: ['mobile', 'phone', 'cell', 'cellphone', 'mobile number', 'phone number', 'cell number'],
  email: ['email', 'e-mail', 'email address'],
  home_language: ['home language', 'language'],
};

export const TEMPLATE_CSV = 'First names,Surname,ID number,Mobile,Email,Home language\nSipho,Ndlovu,8001015009087,082 555 0101,,zu\n';

const norm = (h: unknown) => String(h ?? '').trim().toLowerCase().replace(/[_*]+/g, ' ').replace(/\s+/g, ' ');

/** Minimal RFC 4180 CSV reader (quotes, escaped quotes, CRLF). Also accepts ';' when the file uses it. */
export function parseCsv(text: string): string[][] {
  const src = text.replace(/^﻿/, '');
  const firstLine = src.split(/\r?\n/, 1)[0] ?? '';
  const sep = (firstLine.match(/;/g)?.length ?? 0) > (firstLine.match(/,/g)?.length ?? 0) ? ';' : ',';
  const rows: string[][] = [];
  let row: string[] = [];
  let cell = '';
  let quoted = false;
  for (let i = 0; i < src.length; i++) {
    const c = src[i];
    if (quoted) {
      if (c === '"' && src[i + 1] === '"') { cell += '"'; i++; }
      else if (c === '"') quoted = false;
      else cell += c;
    } else if (c === '"') quoted = true;
    else if (c === sep) { row.push(cell); cell = ''; }
    else if (c === '\n' || c === '\r') {
      if (c === '\r' && src[i + 1] === '\n') i++;
      row.push(cell); rows.push(row); row = []; cell = '';
    } else cell += c;
  }
  if (cell !== '' || row.length) { row.push(cell); rows.push(row); }
  return rows.filter((r) => r.some((v) => v.trim() !== ''));
}

/** Spreadsheets drop leading zeros from numbers: put them back for ID numbers and SA mobiles. */
function cellText(v: unknown, field: keyof ImportRow): string {
  if (v === null || v === undefined) return '';
  if (v instanceof Date) return v.toISOString().slice(0, 10);
  let s = typeof v === 'number' ? v.toFixed(0) : String(v).trim();
  if (typeof v === 'number' && field === 'id_number' && s.length >= 11 && s.length < 13) s = s.padStart(13, '0');
  if (typeof v === 'number' && field === 'phone' && s.length === 9) s = '0' + s;
  return s;
}

export function toRows(table: unknown[][]): { rows: ImportRow[]; missing: (keyof ImportRow)[] } {
  const [head = [], ...body] = table;
  const index = {} as Record<keyof ImportRow, number>;
  (Object.keys(HEADERS) as (keyof ImportRow)[]).forEach((field) => {
    index[field] = head.findIndex((h) => HEADERS[field].includes(norm(h)));
  });
  const missing = (['first_names', 'surname', 'id_number'] as const).filter((f) => index[f] < 0);
  const rows = body.map((r) => {
    const out = {} as ImportRow;
    (Object.keys(index) as (keyof ImportRow)[]).forEach((f) => { out[f] = index[f] < 0 ? '' : cellText(r[index[f]], f); });
    return out;
  }).filter((r) => Object.values(r).some((v) => v !== ''));
  return { rows, missing };
}

export async function readLearnerFile(file: File): Promise<{ rows: ImportRow[]; missing: (keyof ImportRow)[] }> {
  if (/\.xlsx$/i.test(file.name)) {
    const { readXlsx } = await import('./xlsx'); // only loaded when someone imports a spreadsheet
    return toRows(await readXlsx(file));
  }
  return toRows(parseCsv(await file.text()));
}
