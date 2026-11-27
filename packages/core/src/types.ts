export interface Branding {
  display_name: string;
  logo_url: string | null;
  primary: string;
  accent: string;
  nav: string;
  default_locale: string;
}

export interface PublicTenant {
  id: string;
  name: string;
  slug: string;
  status: string;
  branding: Branding;
  locales: Record<string, string>;
}

export interface User {
  id: string;
  name: string;
  email: string | null;
  phone: string | null;
  locale: string | null;
  tenant_id: string | null;
  is_platform_staff: boolean;
  two_factor_enabled: boolean;
  consent_required: string[];
}

export interface NavItem {
  id: string;
  section: string;
  label: string;
  icon: string;
  path: string;
}

export interface SupportInfo {
  id: string;
  staff_name: string;
  reason: string;
  expires_at: string;
}

export interface Session {
  area: 'platform' | 'tenant';
  user: User;
  permissions: string[];
  navigation: NavItem[];
  tenant: { id: string; name: string; slug: string; branding: Branding } | null;
  support: SupportInfo | null;
  locales: Record<string, string>;
}

export interface Role {
  id: string;
  key: string;
  name: string;
  description: string | null;
  scope: 'platform' | 'tenant' | 'external';
  is_system: boolean;
  permissions: string[];
  permission_labels: string[];
  people: number;
}

export interface PermissionGroup {
  group: string;
  permissions: { key: string; label: string }[];
}

export interface RolePreview {
  role: string;
  permissions: string[];
  navigation: NavItem[];
}

export interface AuditEntry {
  id: string;
  tenant_id: string | null;
  actor_id: string | null;
  actor_name: string | null;
  support_session_id: string | null;
  action: string;
  subject_type: string | null;
  subject_id: string | null;
  changes: Record<string, unknown> | null;
  meta: Record<string, unknown> | null;
  ip_address: string | null;
  user_agent: string | null;
  created_at: string;
}

export interface CursorPage<T> {
  data: T[];
  next_cursor: string | null;
}

export interface SupportSessionRow {
  id: string;
  staff_name: string;
  reason: string;
  expires_at: string;
  ended_at: string | null;
  ended_by: string | null;
  active: boolean;
}

export interface ProgrammeSummary {
  code: string;
  prefix: string;
  title: string;
  family: string;
  summary: string | null;
  version: number | null;
  published_at: string | null;
  counts: { duty_areas: number; outcomes: number; evidence: number } | null;
  status?: string;
  draft?: { version: number; status: 'draft' | 'in_review' } | null;
}

export type EvidenceType = 'document' | 'photo' | 'observation' | 'sign_off' | 'feedback' | 'record';
export type QualificationKind = 'occupational' | 'legacy' | 'skills_programme' | 'related' | 'note';
export type VersionStatus = 'draft' | 'in_review' | 'published' | 'retired';

export interface OutcomeItem {
  code: string;
  statement: string;
  plain_words: string | null;
}

export interface EvidenceItem {
  id: string;
  description: string;
  type: EvidenceType;
  min_count: number;
}

export interface DutyAreaItem {
  number: number;
  title: string;
  outcomes: OutcomeItem[];
  evidence: EvidenceItem[];
}

export interface ProgrammeVersionContent {
  programme: { code: string; prefix: string; title: string; family: string };
  version: number;
  status: VersionStatus;
  summary: string;
  purpose: string;
  occupations: string[];
  workplace_requirements: string[];
  tools: string[];
  to_confirm: string[];
  source: { document?: string; publisher?: string; edition?: string; page?: number } | null;
  change_notes: string | null;
  published_at: string | null;
  qualifications: { kind: QualificationKind; saqa_id: string | null; title: string | null; nqf_level: number | null; credits: number | null; source_text: string }[];
  duty_areas: DutyAreaItem[];
  review?: {
    submitted_at: string | null;
    submitted_by: string | null;
    reviewed_at: string | null;
    reviewed_by: string | null;
    comment: string | null;
    published_by: string | null;
  };
}

export interface ProgrammeStudioData {
  programme: { code: string; prefix: string; title: string; family: string; status: string };
  versions: { version: number; status: VersionStatus; change_notes: string | null; published_at: string | null; created_at: string | null }[];
  current: ProgrammeVersionContent | null;
}

export interface VersionChange {
  area: 'overview' | 'duty_areas' | 'outcomes' | 'evidence';
  change: 'added' | 'removed' | 'changed';
  item: string;
  before?: string | null;
  after?: string | null;
}
