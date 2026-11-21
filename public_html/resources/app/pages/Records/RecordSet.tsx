// One read-only record set (topics, work hours, dues, …) at /app/records/:set. The server
// describes columns, filters and totals; search, filters, sort and page live in the URL and
// the export downloads exactly the filtered rows. No sync or edit actions by design.
import {useEffect, useMemo, useState} from 'react';
import {useParams} from 'react-router';
import {HStack} from '@astryxdesign/core/Layout';
import {proportional} from '@astryxdesign/core/Table';
import {Selector} from '@astryxdesign/core/Selector';
import {DateInput} from '@astryxdesign/core/DateInput';
import {Page, PageError} from '../../components/PageState';
import {DataTable, type DataColumn} from '../../components/DataTable';
import {Metric, MetricRow} from '../../components/Metric';
import {useDebounced, useListQuery, useResource} from '../../lib/hooks';
import {withQuery} from '../../lib/api';
import {AdminOnly, CRUMBS, Cell, LIST_DEFAULTS, RECORDS_API, line, plain, type ListKey, type RecordRow, type RecordSetData, type SetFilter, type Value} from './shared';

const MIN_WIDTH: Partial<Record<string, number>> = {number: 96, money: 120, bool: 110, date: 120, datetime: 150, list: 320, link: 110, phone: 140};

type ISODate = `${number}${number}${number}${number}-${number}${number}-${number}${number}`;

export function useRecordSet(slug: string, extra: Record<string, string> = {}) {
  const [query, setQuery] = useListQuery(LIST_DEFAULTS);
  const [searchText, setSearchText] = useState(query.q);
  const debounced = useDebounced(searchText);
  useEffect(() => {
    if (debounced !== query.q) setQuery({q: debounced});
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debounced]);
  const request = {...query, ...extra};
  const list = useResource<RecordSetData>(`${RECORDS_API}/${slug}`, request);
  return {query, setQuery, searchText, setSearchText, list, request};
}

export function FilterControls({
  filters,
  query,
  setQuery,
}: {
  filters: SetFilter[];
  query: Record<ListKey, string>;
  setQuery: (patch: Partial<Record<ListKey | 'page', string>>) => void;
}) {
  return (
    <HStack gap={2} wrap="wrap" vAlign="end">
      {filters.map(filter => {
        const key = filter.key as ListKey;
        if (filter.type === 'from' || filter.type === 'to') {
          return (
            <DateInput
              key={key}
              label={filter.label}
              isLabelHidden
              placeholder={filter.label}
              hasClear
              value={(query[key] || undefined) as ISODate | undefined}
              min={filter.type === 'to' ? ((query.from || undefined) as ISODate | undefined) : undefined}
              max={filter.type === 'from' ? ((query.to || undefined) as ISODate | undefined) : undefined}
              onChange={value => setQuery({[key]: value ?? ''})}
            />
          );
        }
        return (
          <Selector
            key={key}
            label={filter.label}
            isLabelHidden
            placeholder={`Any ${filter.label.toLowerCase()}`}
            hasClear
            hasSearch={filter.options.length > 8}
            value={query[key] || null}
            onChange={value => setQuery({[key]: value ?? ''})}
            options={filter.options}
          />
        );
      })}
    </HStack>
  );
}

export function exportHref(slug: string, request: Record<string, string>): string {
  const {page: _page, per_page: _perPage, ...rest} = request;
  return withQuery(`${RECORDS_API}/${slug}/export`, rest);
}

function RecordSetPage({slug}: {slug: string}) {
  const {query, setQuery, searchText, setSearchText, list, request} = useRecordSet(slug);
  const set = list.data?.set;

  const columns = useMemo<DataColumn<RecordRow>[]>(
    () =>
      (set?.columns ?? []).map(column => ({
        key: column.key,
        header: column.label,
        sortKey: column.sortable ? column.key : undefined,
        align: column.type === 'number' || column.type === 'money' ? 'end' : undefined,
        // Wide legacy sets (work hours has 17 columns) scroll sideways instead of truncating to "Me…".
        width: proportional(1, {minWidth: MIN_WIDTH[column.type] ?? 140}),
        renderCell: (row: RecordRow) => <Cell column={column} value={row[column.key] as Value} />,
      })),
    [set],
  );

  const filterKeys = (set?.filters ?? []).map(f => f.key as ListKey);
  const hasFilters = Boolean(query.q) || filterKeys.some(key => Boolean(query[key]));
  const clear = () => {
    setSearchText('');
    setQuery(Object.fromEntries([['q', ''], ...filterKeys.map(key => [key, ''])]));
  };

  if (list.error && !list.data) {
    return (
      <Page title="Records" crumbs={CRUMBS}>
        <PageError error={list.error} onRetry={list.reload} />
      </Page>
    );
  }

  return (
    <Page title={set?.title ?? 'Records'} description={set?.description} crumbs={CRUMBS}>
      {list.data && list.data.totals.length > 0 && (
        <MetricRow>
          {list.data.totals.map(total => (
            <Metric key={total.key} label={total.label} value={plain(total.type, total.value)} hint={hasFilters ? 'For the current filters' : 'All records'} />
          ))}
        </MetricRow>
      )}
      <DataTable<RecordRow>
        label={set?.title ?? 'Records'}
        rows={list.data?.data}
        meta={list.data?.meta}
        columns={columns}
        idKey="rowKey"
        isLoading={list.isLoading}
        isRefreshing={list.isRefreshing}
        search={searchText}
        onSearch={setSearchText}
        searchPlaceholder="Search"
        filters={set && set.filters.length > 0 ? <FilterControls filters={set.filters} query={query} setQuery={setQuery} /> : undefined}
        onSort={(sort, dir) => setQuery({sort, dir})}
        onPage={page => setQuery({page: String(page)}, {resetPage: false})}
        onPageSize={size => setQuery({per_page: String(size)})}
        exportHref={exportHref(slug, request)}
        hasActiveFilters={hasFilters}
        onClearFilters={clear}
        emptyTitle="No records"
        emptyDescription="Nothing was imported into this record set."
        phoneCard={{
          title: row => (set ? line(set.columns, row, [set.card.title]) || '—' : ''),
          subtitle: row => (set ? line(set.columns, row, set.card.subtitle) : ''),
          trailing: row => (set ? line(set.columns, row, set.card.trailing).replace(' · ', ' / ') : ''),
        }}
      />
    </Page>
  );
}

export default function RecordSet() {
  const slug = useParams().set ?? '';
  return (
    <AdminOnly title="Records">
      <RecordSetPage key={slug} slug={slug} />
    </AdminOnly>
  );
}
