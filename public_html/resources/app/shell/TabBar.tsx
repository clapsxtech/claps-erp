// Phone-only bottom bar: the four places a role goes most, plus "More", which
// opens the searchable palette listing every destination. Anything in the app
// is at most two taps away on a phone.
import {useLocation} from 'react-router';
import * as stylex from '@stylexjs/stylex';
import {Icon} from '@astryxdesign/core/Icon';
import {Link} from '@astryxdesign/core/Link';
import {Text} from '@astryxdesign/core/Text';
import {Squares2X2Icon} from '@heroicons/react/24/outline';
import {session, type UnitAccess} from '../lib/session';
import {hrefFor, tabDestinations} from '../nav';

const styles = stylex.create({
  bar: {
    display: {default: 'none', '@media (max-width: 767px)': 'grid'},
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
    minHeight: 56,
    color: 'var(--color-text-secondary)',
    textDecoration: 'none',
    backgroundColor: 'transparent',
    borderWidth: 0,
    cursor: 'pointer',
    font: 'inherit',
  },
  active: {color: 'var(--color-text-accent)'},
});

export function TabBar({unit, onMore}: {unit: UnitAccess | undefined; onMore: () => void}) {
  const location = useLocation();
  const tabs = tabDestinations(unit, session());
  if (!unit) return null;
  return (
    <nav aria-label="Main sections" {...stylex.props(styles.bar)}>
      {tabs.map(tab => {
        const href = hrefFor(tab, unit.id);
        const active = location.pathname.startsWith(href);
        return (
          <Link key={tab.key} href={href} aria-current={active ? 'page' : undefined} {...stylex.props(styles.tab, active && styles.active)}>
            <Icon icon={tab.icon} size="md" />
            <Text size="sm" color="inherit" maxLines={1}>
              {tab.short ?? tab.label}
            </Text>
          </Link>
        );
      })}
      <button type="button" onClick={onMore} {...stylex.props(styles.tab)}>
        <Icon icon={Squares2X2Icon} size="md" />
        <Text size="sm" color="inherit">
          More
        </Text>
      </button>
    </nav>
  );
}
