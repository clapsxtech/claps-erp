// The one table every list screen uses. It follows the usual data-grid
// conventions: search and filters above, sortable headers, numbers right-aligned
// with tabular figures, sticky header, page size and count below, CSV export of
// the full filtered set, and a card list on phones instead of a sideways scroll.
// Rows come pre-sorted and pre-paged from the server; the table only renders.
import {useMemo, type ReactNode} from 'react';
import * as stylex from '@stylexjs/stylex';
import {Table, useTableSortable, type TableColumn} from '@astryxdesign/core/Table';
import {VStack, HStack, StackItem} from '@astryxdesign/core/Layout';
import {Text} from '@astryxdesign/core/Text';
import {TextInput} from '@astryxdesign/core/TextInput';
import {Pagination} from '@astryxdesign/core/Pagination';
import {List, ListItem} from '@astryxdesign/core/List';
import {EmptyState} from '@astryxdesign/core/EmptyState';
import {Button} from '@astryxdesign/core/Button';
import {Icon} from '@astryxdesign/core/Icon';
import {Skeleton} from '@astryxdesign/core/Skeleton';
import {Card} from '@astryxdesign/core/Card';
import {ProgressBar} from '@astryxdesign/core/ProgressBar';
import {ArrowDownTrayIcon, MagnifyingGlassIcon} from '@heroicons/react/24/outline';
import {useIsPhone} from '../lib/hooks';
import {number} from '../lib/format';

export interface DataColumn<T extends Record<string, unknown>> extends TableColumn<T> {
  /** Server sort key; omit for an unsortable column. */
  sortKey?: string;
  /** Hidden from the phone card (the card shows title/subtitle/trailing). */
  hideOnPhone?: boolean;
}

export interface PhoneCard<T extends Record<string, unknown>> {
  title: (row: T) => ReactNode;
  subtitle?: (row: T) => ReactNode;
  trailing?: (row: T) => ReactNode;
  href?: (row: T) => string | undefined;
}

export interface ListMeta {
  page: number;
  perPage: number;
  total: number;
  sort: string;
  dir: 'asc' | 'desc' | string;
}

const styles = stylex.create({
  // Header stays visible while long lists scroll.
  sticky: {position: 'relative'},
});

export function DataTable<T extends Record<string, unknown>>({
  label,
  rows,
  meta,
  columns,
  idKey,
  phoneCard,
  isLoading,
  isRefreshing,
  search,
  onSearch,
  searchPlaceholder = 'Search…',
  filters,
  onSort,
  onPage,
  onPageSize,
  exportHref,
  emptyTitle = 'Nothing here yet',
  emptyDescription,
  emptyAction,
  hasActiveFilters,
  onClearFilters,
  summary,
}: {
  label: string;
  rows: T[] | undefined;
  meta: ListMeta | undefined;
  columns: DataColumn<T>[];
  idKey: keyof T & string;
  phoneCard: PhoneCard<T>;
  isLoading: boolean;
  isRefreshing?: boolean;
  search: string;
  onSearch: (value: string) => void;
  searchPlaceholder?: string;
  filters?: ReactNode;
  onSort: (sort: string, dir: 'asc' | 'desc') => void;
  onPage: (page: number) => void;
  onPageSize: (size: number) => void;
  exportHref?: string;
  emptyTitle?: string;
  emptyDescription?: string;
  emptyAction?: ReactNode;
  hasActiveFilters: boolean;
  onClearFilters: () => void;
  summary?: ReactNode;
}) {
  const isPhone = useIsPhone();

  const tableColumns = useMemo(
    () => columns.map(column => ({...column, sortable: column.sortKey ? {sortKey: column.sortKey} : false})),
    [columns],
  );
  const sortPlugin = useTableSortable<T>({
    sort: meta ? [{sortKey: meta.sort, direction: meta.dir === 'desc' ? 'descending' : 'ascending'}] : [],
    onSortChange: next => {
      const first = next[0];
      if (!first) onSort('', 'asc');
      else onSort(String(first.sortKey), first.direction === 'descending' ? 'desc' : 'asc');
    },
    allowUnsortedState: false,
  });

  const toolbar = (
    <HStack gap={2} vAlign="center" wrap="wrap">
      <StackItem size="fill">
        <TextInput
          label={`Search ${label.toLowerCase()}`}
          isLabelHidden
          value={search}
          onChange={onSearch}
          placeholder={searchPlaceholder}
          startIcon={<Icon icon={MagnifyingGlassIcon} size="sm" />}
          width="100%"
        />
      </StackItem>
      {filters}
      {exportHref && !isPhone && (
        <Button
          label="Export CSV"
          variant="secondary"
          href={exportHref}
          icon={<Icon icon={ArrowDownTrayIcon} size="sm" />}
          {...{download: true}}
        />
      )}
    </HStack>
  );

  const empty = (
    <EmptyState
      title={hasActiveFilters ? 'No matches' : emptyTitle}
      description={hasActiveFilters ? 'Nothing matches the search and filters.' : emptyDescription}
      actions={hasActiveFilters ? <Button label="Clear search and filters" variant="secondary" onClick={onClearFilters} /> : emptyAction}
    />
  );

  let body: ReactNode;
  if (isLoading || !rows || !meta) {
    body = (
      <VStack gap={2}>
        {Array.from({length: 6}, (_, i) => (
          <Skeleton key={i} height={36} width="100%" />
        ))}
      </VStack>
    );
  } else if (rows.length === 0) {
    body = empty;
  } else if (isPhone) {
    body = (
      <List hasDividers edge="flush">
        {rows.map(row => (
          <ListItem
            key={String(row[idKey])}
            label={phoneCard.title(row)}
            description={phoneCard.subtitle?.(row)}
            endContent={phoneCard.trailing?.(row)}
            href={phoneCard.href?.(row)}
          />
        ))}
      </List>
    );
  } else {
    body = (
      <Table<T>
        data={rows}
        columns={tableColumns}
        idKey={idKey}
        density="balanced"
        hasHover
        textOverflow="truncate"
        rowCount={meta.total}
        rowIndexStart={(meta.page - 1) * meta.perPage + 1}
        plugins={{sort: sortPlugin}}
        xstyle={styles.sticky}
      />
    );
  }

  const first = meta && meta.total > 0 ? (meta.page - 1) * meta.perPage + 1 : 0;
  const last = meta ? Math.min(meta.page * meta.perPage, meta.total) : 0;

  return (
    <Card padding={0}>
      <VStack gap={3} padding={4}>
        {toolbar}
        {summary}
        {isRefreshing ? <ProgressBar label="Updating" isIndeterminate /> : null}
        {body}
        {meta && meta.total > 0 && (
          <HStack gap={3} vAlign="center" hAlign="between" wrap="wrap">
            <Text color="secondary" hasTabularNumbers>
              {`${number(first)}–${number(last)} of ${number(meta.total)}`}
            </Text>
            <Pagination
              page={meta.page}
              onChange={onPage}
              totalItems={meta.total}
              pageSize={meta.perPage}
              pageSizeOptions={[25, 50, 100]}
              onPageSizeChange={onPageSize}
              variant={isPhone ? 'compact' : 'pages'}
              size="sm"
              label={`${label} pages`}
            />
          </HStack>
        )}
      </VStack>
    </Card>
  );
}
