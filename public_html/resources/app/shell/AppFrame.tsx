import {useEffect, useMemo, useState} from 'react';
import {Outlet, useLocation, useNavigate, useParams} from 'react-router';
import * as stylex from '@stylexjs/stylex';
import {AppShell} from '@astryxdesign/core/AppShell';
import {SideNav, SideNavHeading, SideNavItem, SideNavSection} from '@astryxdesign/core/SideNav';
import {TopNav} from '@astryxdesign/core/TopNav';
import {NavIcon} from '@astryxdesign/core/NavIcon';
import {Icon} from '@astryxdesign/core/Icon';
import {IconButton} from '@astryxdesign/core/IconButton';
import {Button} from '@astryxdesign/core/Button';
import {Avatar} from '@astryxdesign/core/Avatar';
import {Banner} from '@astryxdesign/core/Banner';
import {Kbd} from '@astryxdesign/core/Kbd';
import {Text} from '@astryxdesign/core/Text';
import {DropdownMenu, DropdownMenuItem, DropdownMenuDivider} from '@astryxdesign/core/DropdownMenu';
import {useHotkeys} from '@astryxdesign/core/hooks';
import {
  AcademicCapIcon,
  ArrowRightStartOnRectangleIcon,
  BuildingOffice2Icon,
  MagnifyingGlassIcon,
  PlusIcon,
  WrenchScrewdriverIcon,
} from '@heroicons/react/24/outline';
import {session, unitById, rememberUnit, preferredUnit} from '../lib/session';
import {hrefFor, visibleGroups} from '../nav';
import {Palette} from './Palette';
import {useIsPhone} from '../lib/hooks';
import {TabBar} from './TabBar';
import {quickCreateFor} from './quickCreate';

const styles = stylex.create({
  // Leaves room under the content for the fixed phone tab bar.
  main: {
    minHeight: '100%',
    paddingBottom: {default: 0, '@media (max-width: 767px)': 'calc(64px + env(safe-area-inset-bottom))'},
  },
});

function signOut() {
  const form = document.createElement('form');
  form.method = 'post';
  form.action = '/logout';
  const token = document.createElement('input');
  token.type = 'hidden';
  token.name = '_token';
  token.value = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
  form.appendChild(token);
  document.body.appendChild(form);
  form.submit();
}

export function AppFrame() {
  const sess = session();
  const params = useParams();
  const location = useLocation();
  const navigate = useNavigate();
  const unitId = params.unit ? Number(params.unit) : undefined;
  // Screens outside a team (admin, account) keep the last team as context, so
  // the sidebar and tab bar stay complete.
  const unit = unitId ? unitById(unitId) : preferredUnit();
  const groups = useMemo(() => visibleGroups(unit, sess), [unit, sess]);
  const [paletteOpen, setPaletteOpen] = useState(false);
  const isPhone = useIsPhone();
  const quickCreate = quickCreateFor(unit, sess);

  useEffect(() => {
    if (unit) rememberUnit(unit.id);
  }, [unit]);

  const openPalette = () => setPaletteOpen(true);
  useHotkeys([
    {keys: 'meta+k', onPress: openPalette, allowInInputs: true},
    {keys: 'ctrl+k', onPress: openPalette, allowInInputs: true},
    {keys: '/', onPress: openPalette},
  ]);

  const isCurrent = (href: string) => location.pathname === href || location.pathname.startsWith(`${href}/`);

  const unitSwitcher = (
    <DropdownMenu
      button={{label: unit ? unit.name : 'Choose team', variant: 'ghost', size: 'sm', icon: <Icon icon={BuildingOffice2Icon} size="sm" />}}
      menuWidth={260}>
      {sess.units.map(option => (
        <DropdownMenuItem
          key={option.id}
          label={option.name}
          description={`${option.roleLabel}${option.isArchived ? ' · archived' : ''}`}
          onClick={() => navigate(`/app/u/${option.id}/home`)}
        />
      ))}
    </DropdownMenu>
  );

  const sideNav = (
    <SideNav
      collapsible
      header={
        <SideNavHeading
          heading="ClapsLearn"
          headingHref={unit ? `/app/u/${unit.id}/home` : '/app'}
          icon={<NavIcon icon={<Icon icon={AcademicCapIcon} size="sm" />} />}
        />
      }
      footer={
        <SideNavSection title="More" isHeaderHidden>
          {sess.user.isTeacher && <SideNavItem label="My lessons" icon={AcademicCapIcon} href="/teacher" />}
          {sess.user.isAdmin && <SideNavItem label="Records & administration" icon={WrenchScrewdriverIcon} href="/app/records" />}
        </SideNavSection>
      }>
      {groups.map(group => (
        <SideNavSection key={group.title} title={group.title} isHeaderHidden={group.title === 'Overview'}>
          {group.items.map(item => {
            const href = hrefFor(item, unit?.id);
            return <SideNavItem key={item.key} label={item.label} icon={item.icon} href={href} isSelected={isCurrent(href)} />;
          })}
        </SideNavSection>
      ))}
    </SideNav>
  );

  const topNav = (
    <TopNav
      label="Workspace bar"
      startContent={sess.units.length > 1 ? unitSwitcher : unit ? <Text weight="semibold">{unit.name}</Text> : null}
      endContent={
        <>
          {isPhone ? (
            <IconButton label="Search" variant="ghost" icon={<Icon icon={MagnifyingGlassIcon} size="sm" />} onClick={openPalette} />
          ) : (
            <Button
              label="Search"
              variant="secondary"
              size="sm"
              icon={<Icon icon={MagnifyingGlassIcon} size="sm" />}
              endContent={<Kbd keys="⌘K" />}
              onClick={openPalette}
            />
          )}
          {quickCreate.length > 0 && (
            <DropdownMenu
              button={{label: isPhone ? '' : 'New', 'aria-label': 'New', variant: 'primary', size: 'sm', icon: <Icon icon={PlusIcon} size="sm" />}}
              hasChevron={false}
              alignment="end"
              menuWidth={240}>
              {quickCreate.map(action => (
                <DropdownMenuItem key={action.key} label={action.label} description={action.description} onClick={() => navigate(action.href)} />
              ))}
            </DropdownMenu>
          )}
          <DropdownMenu
            renderTrigger={props => (
              <IconButton {...props} label="Account" variant="ghost" icon={<Avatar name={sess.user.name} size="sm" tooltip={false} />} />
            )}
            alignment="end"
            menuWidth={220}>
            <DropdownMenuItem label={sess.user.name} description={unit ? unit.roleLabel : 'Signed in'} isDisabled />
            <DropdownMenuDivider />
            <DropdownMenuItem label="Account security" onClick={() => navigate('/app/account/security')} />
            <DropdownMenuItem label="Use the classic screens" href={unit ? `/erp/units/${unit.id}/business/dashboard` : '/'} />
            <DropdownMenuDivider />
            <DropdownMenuItem label="Sign out" icon={ArrowRightStartOnRectangleIcon} onClick={signOut} />
          </DropdownMenu>
        </>
      }
    />
  );

  const banner = sess.sheetsAuthority ? (
    <Banner
      status="warning"
      title="Sheets are the source of truth right now"
      description="Make operational changes in the original Sheets. Screens here show ERP records, which may differ."
    />
  ) : unit?.isArchived ? (
    <Banner status="info" title="This team is archived" description="Everything is read-only until an administrator restores it." />
  ) : undefined;

  return (
    <>
      <AppShell topNav={topNav} sideNav={sideNav} banner={banner} variant="elevated">
        <section {...stylex.props(styles.main)}>
          <Outlet />
        </section>
      </AppShell>
      <TabBar unit={unit} onMore={openPalette} />
      <Palette isOpen={paletteOpen} onOpenChange={setPaletteOpen} unit={unit} groups={groups} quickCreate={quickCreate} />
    </>
  );
}
