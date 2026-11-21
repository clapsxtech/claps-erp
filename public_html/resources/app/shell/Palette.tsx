// Ctrl/⌘+K (or "/") from anywhere: jump to any screen, start any new record,
// or find a student or teacher by name or code and open their profile.
import {useMemo, useRef} from 'react';
import {useNavigate} from 'react-router';
import {CommandPalette} from '@astryxdesign/core/CommandPalette';
import type {SearchableItem, SearchSource} from '@astryxdesign/core/Typeahead';
import {getJson} from '../lib/api';
import type {UnitAccess} from '../lib/session';
import {hrefFor, type NavGroup} from '../nav';
import type {QuickCreate} from './quickCreate';

type Item = SearchableItem<{href: string; keywords: string}>;

interface LookupRow {
  id: number;
  label: string;
}

function matches(item: Item, query: string): boolean {
  const haystack = `${item.label} ${item.auxiliaryData?.keywords ?? ''}`.toLowerCase();
  return query
    .toLowerCase()
    .split(/\s+/)
    .filter(Boolean)
    .every(word => haystack.includes(word));
}

export function Palette({
  isOpen,
  onOpenChange,
  unit,
  groups,
  quickCreate,
}: {
  isOpen: boolean;
  onOpenChange: (open: boolean) => void;
  unit: UnitAccess | undefined;
  groups: NavGroup[];
  quickCreate: QuickCreate[];
}) {
  const navigate = useNavigate();
  // id -> destination for every item the palette has shown.
  const hrefs = useRef(new Map<string, string>());
  const remember = (items: Item[]) => {
    for (const item of items) if (item.auxiliaryData) hrefs.current.set(item.id, item.auxiliaryData.href);
    return items;
  };

  const source = useMemo<SearchSource<Item>>(() => {
    const destinations: Item[] = groups.flatMap(group =>
      group.items.map(item => ({
        id: `go:${item.key}`,
        label: `${item.label}`,
        auxiliaryData: {href: hrefFor(item, unit?.id), keywords: `${group.title} ${item.keywords} go open`},
      })),
    );
    const creates: Item[] = quickCreate.map(entry => ({
      id: `new:${entry.key}`,
      label: `New ${entry.label.toLowerCase()}`,
      auxiliaryData: {href: entry.href, keywords: `create add ${entry.description}`},
    }));
    let controller: AbortController | null = null;

    async function people(query: string): Promise<Item[]> {
      if (!unit || query.trim().length < 2) return [];
      controller?.abort();
      controller = new AbortController();
      const base = `/erp/units/${unit.id}/lookup`;
      const canSeePeople = unit.pages.includes('people') || unit.role === 'superadmin';
      try {
        const [students, teachers] = await Promise.all([
          getJson<LookupRow[]>(base, {type: 'students', q: query}, controller.signal),
          canSeePeople ? getJson<LookupRow[]>(base, {type: 'teachers', q: query}, controller.signal) : Promise.resolve([]),
        ]);
        return [
          ...students.slice(0, 6).map(row => ({
            id: `student:${row.id}`,
            label: `Student · ${row.label.replace(/ \(#\d+\)$/, '')}`,
            // Full profiles are for managers and read-only; others land on the filtered list.
            auxiliaryData: {
              href: ['superadmin', 'team_manager', 'read_only'].includes(unit.role)
                ? `/app/u/${unit.id}/students/${row.id}`
                : unit.pages.includes('people')
                  ? `/app/u/${unit.id}/students?q=${encodeURIComponent(row.label.replace(/ \(#\d+\)$/, '').split(' · ')[0])}`
                  : `/app/u/${unit.id}/followups?new=followup&student=${row.id}`,
              keywords: '',
            },
          })),
          ...teachers.slice(0, 4).map(row => ({
            id: `teacher:${row.id}`,
            label: `Teacher · ${row.label.replace(/ \(#\d+\)$/, '')}`,
            auxiliaryData: {href: `/app/u/${unit.id}/teachers?q=${encodeURIComponent(query)}`, keywords: ''},
          })),
        ];
      } catch {
        return [];
      }
    }

    return {
      bootstrap: () => remember([...creates.slice(0, 3), ...destinations]),
      search: async (query, context) => {
        const local = [...destinations, ...creates].filter(item => matches(item, query));
        context?.update(remember(local));
        const found = await people(query);
        return remember([...local, ...found]);
      },
      cancel: () => controller?.abort(),
    };
  }, [groups, quickCreate, unit]);

  return (
    <CommandPalette<Item>
      isOpen={isOpen}
      onOpenChange={onOpenChange}
      searchSource={source}
      label="Go to a screen, start something new, or find a person"
      emptyBootstrapText="Type a screen, an action, or a student's name"
      emptySearchText="Nothing matches. Try a name, code or screen."
      value=""
      onValueChange={id => {
        onOpenChange(false);
        const href = hrefs.current.get(id);
        if (href) navigate(href);
      }}
    />
  );
}
