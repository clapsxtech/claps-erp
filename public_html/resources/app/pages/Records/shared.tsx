// Records & administration: shared types, the administrator gate and value formatting.
// The server (RecordsDatasets) describes each set's columns and filters; values arrive
// exactly as the legacy spreadsheets stored them, so formatting only happens here.
import type {ReactNode} from 'react';
import {Text} from '@astryxdesign/core/Text';
import {Link} from '@astryxdesign/core/Link';
import {EmptyState} from '@astryxdesign/core/EmptyState';
import {Button} from '@astryxdesign/core/Button';
import {Page} from '../../components/PageState';
import {Status} from '../../components/Status';
import type {ListMeta} from '../../components/DataTable';
import {date, dateTime, money} from '../../lib/format';
import {session} from '../../lib/session';

export type ColumnType = 'text' | 'number' | 'money' | 'date' | 'datetime' | 'bool' | 'link' | 'list' | 'phone';
export type Value = string | boolean | string[] | null;

export interface SetColumn {
  key: string;
  label: string;
  type: ColumnType;
  sortable: boolean;
}

export interface SetFilter {
  key: string;
  label: string;
  type: 'select' | 'enum' | 'from' | 'to';
  options: {value: string; label: string}[];
}

export interface RecordRow extends Record<string, unknown> {
  rowKey: string;
}

export interface Total {
  key: string;
  label: string;
  type: 'number' | 'money' | 'percent';
  value: string | null;
}

export interface RecordSetData {
  set: {
    slug: string;
    title: string;
    description: string;
    group: string;
    columns: SetColumn[];
    filters: SetFilter[];
    card: {title: string; subtitle?: string[]; trailing?: string[]};
  };
  filters: Record<string, string>;
  totals: Total[];
  data: RecordRow[];
  meta: ListMeta;
}

export const RECORDS_API = '/app-api/records';
export const CRUMBS = [{label: 'Records & administration', href: '/app/records'}];

/** Every filter key any set uses, so list state can live in the URL. */
export const LIST_DEFAULTS = {
  q: '', sort: '', dir: '', page: '1', per_page: '25',
  teacher: '', student: '', subject: '', team: '', status: '', category: '', type: '', medium: '', staff: '', section: '', day: '', from: '', to: '',
};
export type ListKey = keyof typeof LIST_DEFAULTS;

/** The classic screens were superadmin-only; the server refuses everyone else anyway. */
export function AdminOnly({title, children}: {title: string; children: ReactNode}) {
  if (session().user?.isAdmin) return <>{children}</>;
  return (
    <Page title={title}>
      <EmptyState
        title="Administrator access required"
        description="Historical records and site administration are available to site administrators. Your team's screens are in the menu."
        actions={<Button label="Go to my workspace" variant="secondary" href="/app" />}
      />
    </Page>
  );
}

function numeric(value: string): number | null {
  const n = Number(value.replace(/[,\s₹]/g, ''));
  return value.trim() !== '' && Number.isFinite(n) ? n : null;
}

/** Legacy figures are sheet text: format them when they are numbers, else show them as written. */
export function plain(type: ColumnType | Total['type'], value: Value | undefined): string {
  if (value === null || value === undefined || value === '') return '—';
  if (Array.isArray(value)) return value.length ? value.join(', ') : '—';
  if (typeof value === 'boolean') return value ? 'On time' : 'Late';
  switch (type) {
    case 'money': {
      const n = numeric(value);
      return n === null ? value : money(Math.round(n * 100));
    }
    case 'number': {
      const n = numeric(value);
      return n === null ? value : n.toLocaleString('en-IN', {maximumFractionDigits: 2});
    }
    case 'percent': {
      const n = numeric(value);
      return n === null ? value : `${n.toFixed(1)}%`;
    }
    case 'date':
      return /^\d{4}-\d{2}-\d{2}/.test(value) ? date(value.slice(0, 10)) : value;
    case 'datetime':
      return /^\d{4}-\d{2}-\d{2}[ T]\d/.test(value) ? dateTime(value) : /^\d{4}-\d{2}-\d{2}$/.test(value) ? date(value) : value;
    default:
      return value;
  }
}

export function Cell({column, value}: {column: SetColumn; value: Value | undefined}) {
  if (value === null || value === undefined || value === '' || (Array.isArray(value) && value.length === 0)) {
    return <Text color="secondary">—</Text>;
  }
  if (column.type === 'bool') return <Status value={value ? 'completed' : 'overdue'} label={value ? 'On time' : 'Late'} />;
  if (column.type === 'link' && typeof value === 'string') {
    return (
      <Link href={value} isExternalLink>
        Join class
      </Link>
    );
  }
  if (column.type === 'phone' && typeof value === 'string') {
    return (
      <Link href={`tel:${value.replace(/[^\d+]/g, '')}`} label={`Call ${value}`}>
        {value}
      </Link>
    );
  }
  const text = plain(column.type, value);
  if (column.type === 'number' || column.type === 'money') return <Text hasTabularNumbers>{text}</Text>;
  if (column.type === 'list') return <Text maxLines={2}>{text}</Text>;
  return <Text>{text}</Text>;
}

/** Phone card line: the named values, typed, joined with a dot. */
export function line(columns: SetColumn[], row: RecordRow, keys: string[] | undefined): string {
  return (keys ?? [])
    .map(key => {
      const column = columns.find(c => c.key === key);
      const value = row[key] as Value | undefined;
      return column && value !== null && value !== undefined && value !== '' ? plain(column.type, value) : null;
    })
    .filter(Boolean)
    .join(' · ');
}
