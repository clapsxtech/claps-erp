// Tabs that stay usable on a phone. A strip that fits the screen stays a tab
// strip; one that would scroll sideways (hiding most choices off screen)
// becomes a full-width "View" picker that opens as a bottom sheet, so every
// view is one tap away and visible at once.
import {TabList, Tab} from '@astryxdesign/core/TabList';
import {Selector} from '@astryxdesign/core/Selector';
import {Badge} from '@astryxdesign/core/Badge';
import {useIsCompact, useIsPhone} from '../lib/hooks';
import {number} from '../lib/format';

export interface ViewTab {
  value: string;
  label: string;
  /** Shown after the label, e.g. a count of waiting items. */
  count?: number | null;
  /** Badge colour for the count; default neutral. */
  tone?: 'warning' | 'error';
}

// Rough width budget of the tab strip, in characters of tab label: a 360px
// phone, and a portrait tablet's content area beside the sidebar.
const PHONE_CHAR_BUDGET = 30;
const TABLET_CHAR_BUDGET = 48;

export function ViewTabs({value, onChange, tabs, label = 'View'}: {value: string; onChange: (value: string) => void; tabs: ViewTab[]; label?: string}) {
  const isPhone = useIsPhone();
  const isCompact = useIsCompact();
  const budget = isPhone ? PHONE_CHAR_BUDGET : isCompact ? TABLET_CHAR_BUDGET : Infinity;
  const shown = tabs.map(tab => ({...tab, count: tab.count ? tab.count : null}));
  const chars = shown.reduce((sum, tab) => sum + tab.label.length + (tab.count != null ? 3 : 0) + 4, 0);
  if (chars > budget) {
    return (
      <Selector
        label={label}
        value={value}
        onChange={next => next && onChange(next)}
        options={shown.map(tab => ({value: tab.value, label: tab.count != null ? `${tab.label} (${number(tab.count)})` : tab.label}))}
        presentation="adaptive"
        width="100%"
      />
    );
  }
  return (
    <TabList value={value} onChange={onChange}>
      {shown.map(tab => (
        <Tab key={tab.value} value={tab.value} label={tab.label} endContent={tab.count != null ? <Badge label={number(tab.count)} variant={tab.tone} /> : undefined} />
      ))}
    </TabList>
  );
}
