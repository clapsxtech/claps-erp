// The teacher's own frame: no staff sidebar. A top bar with the six sections on
// desktop and a bottom tab bar on phones, where most teachers use it.
import {Suspense, useEffect} from 'react';
import {Outlet, useLocation} from 'react-router';
import * as stylex from '@stylexjs/stylex';
import {AppShell} from '@astryxdesign/core/AppShell';
import {TopNav, TopNavHeading, TopNavItem} from '@astryxdesign/core/TopNav';
import {NavIcon} from '@astryxdesign/core/NavIcon';
import {Icon} from '@astryxdesign/core/Icon';
import {IconButton} from '@astryxdesign/core/IconButton';
import {Avatar} from '@astryxdesign/core/Avatar';
import {Link} from '@astryxdesign/core/Link';
import {Text} from '@astryxdesign/core/Text';
import {DropdownMenu, DropdownMenuItem, DropdownMenuDivider} from '@astryxdesign/core/DropdownMenu';
import {
  AcademicCapIcon,
  ArrowRightStartOnRectangleIcon,
  CalendarDaysIcon,
  ChartBarIcon,
  CheckCircleIcon,
  ClipboardDocumentCheckIcon,
  HomeIcon,
  UsersIcon,
} from '@heroicons/react/24/outline';
import {PageLoading} from '../../components/PageState';
import {useIsPhone} from '../../lib/hooks';
import {leave, signOut, teacherSession} from './shared';
import Setup from './Setup';
import Refused from './Refused';

export const SECTIONS = [
  // `short` fits six tabs on a 320px phone.
  {key: 'overview', label: 'Today', short: 'Today', icon: HomeIcon},
  {key: 'timetable', label: 'Timetable', short: 'Schedule', icon: CalendarDaysIcon},
  {key: 'attendance', label: 'Attendance', short: 'Attend', icon: CheckCircleIcon},
  {key: 'results', label: 'Results', short: 'Results', icon: ClipboardDocumentCheckIcon},
  {key: 'students', label: 'Students', short: 'Students', icon: UsersIcon},
  {key: 'reports', label: 'Reports', short: 'Reports', icon: ChartBarIcon},
] as const;

const styles = stylex.create({
  main: {
    minHeight: '100%',
    paddingBottom: {default: 0, '@media (max-width: 767px)': 'calc(72px + env(safe-area-inset-bottom))'},
  },
  bar: {
    display: {default: 'none', '@media (max-width: 767px)': 'grid'},
    gridTemplateColumns: 'repeat(6, 1fr)',
    position: 'fixed',
    insetInline: 0,
    bottom: 0,
    zIndex: 20,
    paddingBottom: 'env(safe-area-inset-bottom)',
    backgroundColor: 'var(--color-background-surface)',
    borderTopWidth: 1,
    borderTopStyle: 'solid',
    borderTopColor: 'var(--color-border)',
  },
  tab: {
    display: 'flex',
    flexDirection: 'column',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 2,
    minHeight: 58,
    minWidth: 0,
    color: 'var(--color-text-secondary)',
    textDecoration: 'none',
  },
  active: {color: 'var(--color-text-accent)'},
});

export default function TeacherLayout() {
  const sess = teacherSession();
  const location = useLocation();
  const isPhone = useIsPhone();

  // Arrived from the staff app inside the single-page app: this page needs the
  // teacher session, which only a full load of /teacher embeds.
  const isWrongSession = sess.kind !== 'teacher';
  useEffect(() => {
    if (isWrongSession) window.location.reload();
  }, [isWrongSession]);
  if (isWrongSession) return <PageLoading />;

  if (sess.mode === 'setup') return <Setup />;
  if (sess.mode === 'refused') return <Refused />;

  const current = location.pathname.split('/')[2] || 'overview';
  const teacher = sess.teacher!;

  const account = (
    <DropdownMenu
      renderTrigger={props => <IconButton {...props} label="Account" variant="ghost" icon={<Avatar name={teacher.name} size="sm" tooltip={false} />} />}
      alignment="end"
      menuWidth={240}>
      <DropdownMenuItem label={teacher.name} description={`Teacher · ${teacher.code}`} isDisabled />
      <DropdownMenuItem label="Time zone" description={teacher.timezone} isDisabled />
      <DropdownMenuDivider />
      <DropdownMenuItem label="Account security" onClick={() => leave('/account/security')} />
      <DropdownMenuItem label="Sign out" icon={ArrowRightStartOnRectangleIcon} onClick={signOut} />
    </DropdownMenu>
  );

  const topNav = (
    <TopNav
      label="Teacher bar"
      heading={
        <TopNavHeading
          heading="My teaching"
          headingHref="/teacher/overview"
          subheading={isPhone ? undefined : sess.unitName}
          logo={<NavIcon icon={<Icon icon={AcademicCapIcon} size="sm" />} />}
        />
      }
      centerContent={
        isPhone ? undefined : (
          <>
            {SECTIONS.map(section => (
              <TopNavItem key={section.key} label={section.label} href={`/teacher/${section.key}`} isSelected={current === section.key} />
            ))}
          </>
        )
      }
      endContent={account}
    />
  );

  return (
    <>
      <AppShell topNav={topNav} variant="elevated">
        <section {...stylex.props(styles.main)}>
          <Suspense fallback={<PageLoading />}>
            <Outlet />
          </Suspense>
        </section>
      </AppShell>
      <nav aria-label="Teacher sections" {...stylex.props(styles.bar)}>
        {SECTIONS.map(section => {
          const active = current === section.key;
          return (
            <Link key={section.key} href={`/teacher/${section.key}`} label={section.label} aria-current={active ? 'page' : undefined} {...stylex.props(styles.tab, active && styles.active)}>
              <Icon icon={section.icon} size="md" />
              <Text size="xsm" color="inherit" maxLines={1}>
                {section.short}
              </Text>
            </Link>
          );
        })}
      </nav>
    </>
  );
}
