/** QCTO code lists (Learner Enrolment and Readiness for EISA file). Same values as the API's QctoCodes. */
export const QCTO_CODES = {
  titles: ['Mr', 'Mrs', 'Ms', 'Miss', 'Dr', 'Prof'],
  alt_id_types: ['527', '565', '538', '540'],
  equity: ['BA', 'BC', 'BI', 'Wh', 'Oth', 'U'],
  nationality: ['SA', 'SDC', 'NAM', 'BOT', 'ZIM', 'ANG', 'MOZ', 'LES', 'SWA', 'MAL', 'ZAM', 'MAU', 'TAN', 'SEY', 'ZAI', 'ROA', 'EUR', 'AIS', 'NOR', 'SOU', 'AUS', 'OOC', 'U'],
  citizen: ['SA', 'PR', 'D', 'O', 'U'],
  socioeconomic: ['01', '02', '03', '04', '06', '07', '08', '09', '10', 'U'],
  disability: ['N', '01', '02', '03', '04', '05', '06', '07', '09'],
  disability_rating: ['01', '02', '03', '04', '06'],
  immigrant: ['03', '01', '02'],
  province: ['1', '2', '3', '4', '5', '6', '7', '8', '9', 'X'],
  readiness: ['1', '2', '3', '4', '5', '6'],
  flc: ['01', '02', '03', '04', '05', '06', '07', '08'],
} as const;

export type QctoList = keyof typeof QCTO_CODES;

export interface QctoAddress { line1: string; line2: string; line3: string | null; postal_code: string }

export type QctoField = 'title' | 'equity' | 'nationality_code' | 'home_language' | 'citizen_status' | 'socioeconomic_code' | 'disability_status'
  | 'immigrant_status' | 'date_of_birth' | 'home_address' | 'province_code' | 'stats_area_code' | 'popi' | 'flc_code';

export interface QctoDetails {
  title: string | null;
  middle_name: string | null;
  alt_id_type: string | null;
  nationality_code: string | null;
  citizen_status: string | null;
  immigrant_status: string | null;
  socioeconomic_code: string | null;
  home_address: QctoAddress | null;
  postal_address: QctoAddress | null;
  province_code: string | null;
  stats_area_code: string | null;
  flc_code: string | null;
  flc_sor_number: string | null;
  popi_agreed_at: string | null;
  /** Special personal information: only for staff who manage learners (null otherwise). */
  equity: string | null;
  disability_status: string | null;
  disability_rating: string | null;
  missing: QctoField[];
}

export interface QctoCheck {
  cohort: ('sdp_code' | 'qualification_id' | 'ends_on')[];
  learners: { learner_id: string; name: string; missing: QctoField[] }[];
  complete: number;
  total: number;
}
