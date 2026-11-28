/** South African ID numbers: YYMMDD SSSS C A Z (Luhn check digit). Same rules as the API's SaIdNumber. */
export type SaIdFacts = { date_of_birth: string; gender: 'female' | 'male'; citizenship: 'sa' | 'permanent_resident' };

export function checkSaId(raw: string, today: Date = new Date()): SaIdFacts | null {
  const id = raw.replace(/\s+/g, '');
  if (!/^\d{13}$/.test(id)) return null;
  let sum = 0;
  for (let i = 0; i < 13; i++) {
    let d = Number(id[12 - i]);
    if (i % 2 === 1) {
      d *= 2;
      if (d > 9) d -= 9;
    }
    sum += d;
  }
  if (sum % 10 !== 0) return null;
  const yy = Number(id.slice(0, 2));
  const mm = Number(id.slice(2, 4));
  const dd = Number(id.slice(4, 6));
  const century = 2000 + yy > today.getFullYear() ? 1900 : 2000;
  const dob = new Date(Date.UTC(century + yy, mm - 1, dd));
  if (dob.getUTCMonth() !== mm - 1 || dob.getUTCDate() !== dd) return null;
  const citizen = id[10];
  if (citizen !== '0' && citizen !== '1') return null;
  return {
    date_of_birth: dob.toISOString().slice(0, 10),
    gender: Number(id.slice(6, 10)) >= 5000 ? 'male' : 'female',
    citizenship: citizen === '0' ? 'sa' : 'permanent_resident',
  };
}

export type LearnerStatus = 'invited' | 'active' | 'inactive';

export interface LearnerItem {
  id: string;
  first_names: string;
  surname: string;
  name: string;
  phone: string | null;
  email: string | null;
  id_type: 'sa_id' | 'passport';
  id_masked: string;
  date_of_birth: string | null;
  gender: 'female' | 'male' | null;
  citizenship: string | null;
  home_language: string | null;
  qcto_learner_number: string | null;
  status: LearnerStatus;
  joined_at: string | null;
  created_at: string;
}

export interface ImportReport {
  rows: { row: number; name: string; errors: Record<string, string>; learner_id: string | null }[];
  ready: number;
  failed: number;
  created: number;
}

export const HOME_LANGUAGES = ['en', 'af', 'zu', 'xh', 'st', 'tn', 'nso', 'ts', 'ss', 've', 'nr', 'other'] as const;
