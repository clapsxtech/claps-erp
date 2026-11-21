// Tabs that stay usable on any screen. A strip that fits stays a tab strip;
// one that would scroll sideways (hiding choices off screen on a phone or a
// tablet) becomes a full-width "View" picker, a bottom sheet on phones, so
// every view is one tap away and visible at once.
import {TabList, Tab} from '@astryxdesign/core/TabList';
import {Selector} from '@astryxdesign/core/Selector';
import {Badge} from '@astryxdesign/core/Badge';
import {useEffect, useState} from 'react';
import {useIsPhone} from '../lib/hooks';
import {number} from '../lib/format';

export interface ViewTab {
  value: string;
  label: string;
  /** Shown after the label, e.g. a count of waiting items. */
  count?: number | null;
  /** Badge colour for the count; default neutral. */
  tone?: 'warning' | 'error';
}

// Rough width of one character of tab label, in pixels, and the space around
// the strip: page gutters on a phone, the sidebar plus gutters otherwise.
const PX_PER_CHAR = 9;
const PHONE_CHROME = 70;
const SIDEBAR_CHROME = 340;

function useViewportWidth(): number {
  const [width, setWidth] = useState(() => window.innerWidth);
  useEffect(() => {
    const listener = () => setWidth(window.innerWidth);
    window.addEventListener('resize', listener);
    return () => window.removeEventListener('resize', listener);
  }, []);
  return width;
}

export function ViewTabs({value, onChange, tabs, label = 'View'}: {value: string; onChange: (value: string) => void; tabs: ViewTab[]; label?: string}) {
  const isPhone = useIsPhone();
  const width = useViewportWidth();
  const budget = (width - (isPhone ? PHONE_CHROME : SIDEBAR_CHROME)) / PX_PER_CHAR;
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
