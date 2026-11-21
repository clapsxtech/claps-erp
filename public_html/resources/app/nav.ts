// The one map of where everything lives. Each destination names the server
// permission page it needs (from TeamPermissions::PAGES); the session already
// lists the pages a user may open in each unit, so filtering here only hides
// links the server would refuse.
import type {ComponentType, SVGProps} from 'react';
import {
  AcademicCapIcon,
  BanknotesIcon,
  CalendarDaysIcon,
  ChartBarIcon,
  CheckCircleIcon,
  ClipboardDocumentCheckIcon,
  HomeIcon,
  InboxStackIcon,
  ArrowsRightLeftIcon,
  DocumentChartBarIcon,
  PhoneArrowUpRightIcon,
  UserGroupIcon,
  UserPlusIcon,
  UsersIcon,
  BriefcaseIcon,
  ShieldCheckIcon,
  CloudArrowDownIcon,
  Cog6ToothIcon,
  BuildingOffice2Icon,
  ArrowUpTrayIcon,
  PresentationChartLineIcon,
} from '@heroicons/react/24/outline';
import type {Session, UnitAccess} from './lib/session';

export type IconComponent = ComponentType<SVGProps<SVGSVGElement>>;

export interface Destination {
  key: string;
  label: string;
  /** Short label for the phone tab bar. */
  short?: string;
  /** Words people search for in the command palette. */
  keywords: string;
  icon: IconComponent;
  /** Server page permission; null means every member of the unit. */
  page: string | null;
  /** Unit-scoped path segment, or an absolute path for global screens. */
  path: string;
  global?: boolean;
  adminOnly?: boolean;
}

export interface NavGroup {
  title: string;
  items: Destination[];
}

export const GROUPS: NavGroup[] = [
  {
    title: 'Overview',
    items: [
      {key: 'home', label: 'Home', keywords: 'dashboard today overview kpi', icon: HomeIcon, page: null, path: 'home'},
      {key: 'tasks', label: 'My tasks', short: 'Tasks', keywords: 'todo assigned work inbox', icon: InboxStackIcon, page: null, path: 'tasks'},
    ],
  },
  {
    title: 'People',
    items: [
      {key: 'students', label: 'Students', keywords: 'learners children families dues balance', icon: UsersIcon, page: 'people', path: 'students'},
      {key: 'teachers', label: 'Teachers', keywords: 'tutors staff salary earnings', icon: AcademicCapIcon, page: 'people', path: 'teachers'},
      {key: 'admissions', label: 'Admissions', short: 'Leads', keywords: 'leads crm enquiries demo trial sales pipeline', icon: UserPlusIcon, page: 'admissions', path: 'admissions'},
      {key: 'followups', label: 'Follow-ups', keywords: 'calls reminders retention priority', icon: PhoneArrowUpRightIcon, page: 'followups', path: 'followups'},
      {key: 'recruitment', label: 'Teacher hiring', keywords: 'recruitment candidates onboarding', icon: BriefcaseIcon, page: 'teacher-recruitment', path: 'recruitment'},
    ],
  },
  {
    title: 'Classes',
    items: [
      {key: 'timetable', label: 'Timetable', short: 'Classes', keywords: 'schedule lessons calendar week reschedule', icon: CalendarDaysIcon, page: 'timetable', path: 'timetable'},
      {key: 'attendance', label: 'Attendance', keywords: 'approve submitted present absent no show', icon: CheckCircleIcon, page: 'attendance', path: 'attendance'},
      {key: 'matching', label: 'Teacher matching', keywords: 'allocation assign slot window demo', icon: ArrowsRightLeftIcon, page: 'allocation', path: 'matching'},
      {key: 'academics', label: 'Results', keywords: 'academics marks assessment eligibility subjects', icon: ClipboardDocumentCheckIcon, page: 'academics', path: 'academics'},
    ],
  },
  {
    title: 'Money',
    items: [
      {key: 'payments', label: 'Payments & dues', short: 'Money', keywords: 'receipts ledger fees collect payment requests refunds salary', icon: BanknotesIcon, page: 'money', path: 'payments'},
      {key: 'accounts', label: 'Accounts & tax', keywords: 'books journal invoices gst trial balance', icon: DocumentChartBarIcon, page: 'accounts', path: 'accounts'},
    ],
  },
  {
    title: 'Reports',
    items: [
      {key: 'sales-report', label: 'Sales report', keywords: 'counsellor conversions campaigns collections activity', icon: PresentationChartLineIcon, page: 'admissions', path: 'sales-report'},
      {key: 'insights', label: 'Trends', keywords: 'insights analytics charts growth', icon: ChartBarIcon, page: 'insights', path: 'insights'},
      {key: 'exports', label: 'Exports & checks', keywords: 'migration csv download reconciliation', icon: CloudArrowDownIcon, page: 'migration', path: 'exports'},
    ],
  },
  {
    title: 'Admin',
    items: [
      {key: 'team', label: 'Team & access', keywords: 'members roles permissions staff accounts', icon: UserGroupIcon, page: 'staff', path: 'team', adminOnly: true},
      {key: 'teams', label: 'Teams & accounts', keywords: 'create team onboard staff accounts archive', icon: BuildingOffice2Icon, page: null, path: '/app/admin/teams', global: true, adminOnly: true},
      {key: 'operations', label: 'Operations & audit', keywords: 'audit log health outbox', icon: ShieldCheckIcon, page: null, path: '/app/admin/operations', global: true, adminOnly: true},
      {key: 'migration', label: 'Migration center', keywords: 'import sheets batches cutover', icon: ArrowUpTrayIcon, page: null, path: '/app/admin/migration', global: true, adminOnly: true},
      {key: 'security', label: 'Account security', keywords: 'password two factor 2fa', icon: Cog6ToothIcon, page: null, path: '/app/account/security', global: true},
    ],
  },
];

export function allows(item: Destination, unit: UnitAccess | undefined, sess: Session): boolean {
  if (item.adminOnly && !sess.user.isAdmin) return false;
  if (item.page === null) return true;
  if (!unit) return false;
  if (sess.user.isAdmin) return true;
  return unit.pages.includes(item.page);
}

export function visibleGroups(unit: UnitAccess | undefined, sess: Session): NavGroup[] {
  return GROUPS.map(group => ({...group, items: group.items.filter(item => allows(item, unit, sess))})).filter(
    group => group.items.length > 0,
  );
}

export function hrefFor(item: Destination, unitId: number | undefined): string {
  if (item.global || unitId === undefined) return item.path.startsWith('/') ? item.path : '/app';
  return `/app/u/${unitId}/${item.path}`;
}

/** The four destinations a role uses most, for the phone tab bar. */
const TAB_PRIORITY = ['home', 'students', 'admissions', 'timetable', 'attendance', 'payments', 'tasks', 'followups'];

export function tabDestinations(unit: UnitAccess | undefined, sess: Session): Destination[] {
  const all = visibleGroups(unit, sess).flatMap(group => group.items);
  return TAB_PRIORITY.map(key => all.find(item => item.key === key))
    .filter((item): item is Destination => item !== undefined)
    .slice(0, 4);
}
