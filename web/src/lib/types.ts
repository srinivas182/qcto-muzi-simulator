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
