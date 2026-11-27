import {
  BookOpen, Box, Briefcase, Building, Building2, CalendarCheck, ChartNoAxesColumn, ChartPie, Clapperboard, ClipboardCheck,
  FolderCheck, Gavel, GraduationCap, House, Inbox, KeyRound, LayoutDashboard, LifeBuoy, ListChecks, Package,
  Palette, PlayCircle, Presentation, Receipt, ScrollText, Settings, ShieldCheck, UserCog, Users, UsersRound,
  Circle, type LucideIcon,
} from 'lucide-react';

/** Icons named in api/config/navigation.php. Only these are bundled. */
const ICONS: Record<string, LucideIcon> = {
  'book-open': BookOpen, box: Box, briefcase: Briefcase, building: Building, 'building-2': Building2, 'calendar-check': CalendarCheck,
  'chart-no-axes-column': ChartNoAxesColumn, 'chart-pie': ChartPie, clapperboard: Clapperboard,
  'clipboard-check': ClipboardCheck, 'folder-check': FolderCheck, gavel: Gavel, 'graduation-cap': GraduationCap,
  house: House, inbox: Inbox, 'key-round': KeyRound, 'layout-dashboard': LayoutDashboard, 'life-buoy': LifeBuoy,
  'list-checks': ListChecks, package: Package, palette: Palette, 'play-circle': PlayCircle, presentation: Presentation,
  receipt: Receipt, 'scroll-text': ScrollText, settings: Settings, 'shield-check': ShieldCheck, 'user-cog': UserCog,
  users: Users, 'users-round': UsersRound,
};

export const navIcon = (name: string): LucideIcon => ICONS[name] ?? Circle;
