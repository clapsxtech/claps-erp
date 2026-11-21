// Family portal frame: centre name and sign-out on top, the child's name and ID,
// section tabs on desktop and a bottom tab bar on phones (where most families are).
import {useState} from 'react';
import {Outlet, useLocation, useNavigate} from 'react-router';
import * as stylex from '@stylexjs/stylex';
import {HStack, VStack, StackItem} from '@astryxdesign/core/Layout';
import {Heading, Text} from '@astryxdesign/core/Text';
import {Button} from '@astryxdesign/core/Button';
import {Banner} from '@astryxdesign/core/Banner';
import {Icon} from '@astryxdesign/core/Icon';
import {Link} from '@astryxdesign/core/Link';
import {TabList, Tab} from '@astryxdesign/core/TabList';
import {AcademicCapIcon, BanknotesIcon, CalendarDaysIcon, HomeIcon} from '@heroicons/react/24/outline';
import {useIsPhone} from '../../lib/hooks';
import {parentPost, parentSession} from './shared';

export const SECTIONS = [
  {key: 'overview', label: 'Overview', short: 'Home', icon: HomeIcon},
  {key: 'classes', label: 'Classes', short: 'Classes', icon: CalendarDaysIcon},
  {key: 'results', label: 'Results', short: 'Results', icon: AcademicCapIcon},
  {key: 'fees', label: 'Fees & documents', short: 'Fees', icon: BanknotesIcon},
] as const;

const styles = stylex.create({
  top: {
    borderBottomWidth: 1,
    borderBottomStyle: 'solid',
    borderBottomColor: 'var(--color-border)',
    backgroundColor: 'var(--color-background-surface)',
    display: {default: null, '@media print': 'none'},
  },
  page: {
    paddingBottom: {default: 'var(--spacing-6)', '@media (max-width: 767px)': 'calc(72px + env(safe-area-inset-bottom))'},
  },
  bar: {
    display: {default: 'none', '@media (max-width: 767px)': 'grid', '@media print': 'none'},
    gridAutoFlow: 'column',
    gridAutoColumns: '1fr',
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
    minHeight: 60,
    color: 'var(--color-text-secondary)',
    textDecoration: 'none',
  },
  active: {color: 'var(--color-text-accent)'},
});

/** Signs out through the classic route (it clears the portal session), then reloads the sign-in page. */
export function SignOutButton() {
  const [busy, setBusy] = useState(false);
  async function signOut() {
    setBusy(true);
    try {
      const result = await parentPost<{redirect: string}>('/parent/logout', {});
      window.location.assign(result.redirect);
    } catch {
      window.location.assign('/parent/login');
    }
  }
  return <Button label="Sign out" variant="ghost" size="sm" isLoading={busy} onClick={signOut} />;
}

export function TopBar() {
  const {centre} = parentSession();
  return (
    <VStack hAlign="center" xstyle={styles.top}>
    <HStack padding={3} gap={3} vAlign="center" width="100%" maxWidth={820}>
      <StackItem size="fill">
        <VStack gap={0}>
          <Text weight="semibold">{centre?.name ?? 'ClapsLearn'}</Text>
          <Text size="sm" color="secondary">
            Parent portal
          </Text>
        </VStack>
      </StackItem>
      <SignOutButton />
    </HStack>
    </VStack>
  );
}

function currentSection(pathname: string): string {
  const part = pathname.replace(/^\/parent\/?/, '').split('/')[0];
  return SECTIONS.some(s => s.key === part) ? part : 'overview';
}

export default function ParentLayout() {
  const {student, centre, status} = parentSession();
  const location = useLocation();
  const navigate = useNavigate();
  const isPhone = useIsPhone();
  const section = currentSection(location.pathname);

  return (
    <VStack gap={0}>
      <TopBar />
      <VStack hAlign="center">
            <VStack width="100%" maxWidth={820}>
            <VStack padding={4} gap={5} xstyle={styles.page}>
              <VStack gap={1}>
                <Heading level={1}>{student?.name ?? 'Parent portal'}</Heading>
                <Text color="secondary">
                  Student ID {student?.code} · {student?.grade} {student?.syllabus}
                </Text>
                <Text color="secondary" size="sm">
                  Times shown in {student?.timezone}
                  {centre?.phone ? ' · Centre ' : ''}
                  {centre?.phone && <Link href={`tel:${centre.phone.replace(/\s+/g, '')}`}>{centre.phone}</Link>}
                </Text>
              </VStack>
              {!isPhone && (
                <TabList value={section} onChange={value => navigate(value === 'overview' ? '/parent' : `/parent/${value}`)} hasDivider>
                  {SECTIONS.map(s => (
                    <Tab key={s.key} value={s.key} label={s.label} />
                  ))}
                </TabList>
              )}
              {status && <Banner status="success" title={status} isDismissable />}
              <Outlet />
            </VStack>
            </VStack>
          </VStack>
      <nav aria-label="Parent portal sections" {...stylex.props(styles.bar)}>
        {SECTIONS.map(s => {
          const active = s.key === section;
          return (
            <Link key={s.key} href={s.key === 'overview' ? '/parent' : `/parent/${s.key}`} aria-current={active ? 'page' : undefined} {...stylex.props(styles.tab, active && styles.active)}>
              <Icon icon={s.icon} size="md" />
              <Text size="sm" color="inherit">
                {s.short}
              </Text>
            </Link>
          );
        })}
      </nav>
    </VStack>
  );
}
