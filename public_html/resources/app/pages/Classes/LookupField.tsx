// Async record picker over the GET /erp/units/{unit}/lookup endpoint,
// which returns [{id, label}] already scoped to the team and the role's lookups.
import {useMemo, useState} from 'react';
import {Typeahead} from '@astryxdesign/core/Typeahead';
import type {SearchableItem, SearchSource} from '@astryxdesign/core/Typeahead';
import {getJson} from '../../lib/api';

export type LookupType = 'students' | 'teachers' | 'lessons' | 'agreements' | 'attendance' | 'enrollments' | 'reviews';

function lookupSource(unitId: number, type: LookupType): SearchSource<SearchableItem> {
  let controller: AbortController | null = null;
  const fetchItems = async (q: string) => {
    controller?.abort();
    controller = new AbortController();
    try {
      const rows = await getJson<{id: number; label: string}[]>(`/erp/units/${unitId}/lookup`, {type, q}, controller.signal);
      return rows.map(row => ({id: String(row.id), label: row.label}));
    } catch {
      return [];
    }
  };
  return {
    search: query => fetchItems(query),
    bootstrap: () => fetchItems(''),
    cancel: () => controller?.abort(),
  };
}

export function LookupField({
  unitId,
  type,
  label,
  value,
  onChange,
  isRequired,
  description,
  status,
}: {
  unitId: number;
  type: LookupType;
  label: string;
  value: string;
  onChange: (id: string) => void;
  isRequired?: boolean;
  description?: string;
  status?: {type: 'error'; message: string};
}) {
  const source = useMemo(() => lookupSource(unitId, type), [unitId, type]);
  const [item, setItem] = useState<SearchableItem | null>(null);
  const selected = value && item?.id === value ? item : null;
  return (
    <Typeahead
      label={label}
      searchSource={source}
      value={selected}
      onChange={next => {
        setItem(next);
        onChange(next?.id ?? '');
      }}
      placeholder="Search by name, code or #id"
      hasEntriesOnFocus
      minQueryLength={0}
      maxMenuItems={30}
      isRequired={isRequired}
      isOptional={!isRequired}
      description={description}
      status={status}
      width="100%"
    />
  );
}
