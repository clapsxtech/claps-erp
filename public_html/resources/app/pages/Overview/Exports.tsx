// Exports & checks (classic /business/migration): application reconciliation totals,
// a CSV download for every dataset the role may export, and the archived history.
import {useEffect, useMemo, useState} from 'react';
import {useParams} from 'react-router';
import {VStack, HStack} from '@astryxdesign/core/Layout';
import {Card} from '@astryxdesign/core/Card';
import {Heading, Text} from '@astryxdesign/core/Text';
import {Button} from '@astryxdesign/core/Button';
import {Icon} from '@astryxdesign/core/Icon';
import {Banner} from '@astryxdesign/core/Banner';
import {Selector} from '@astryxdesign/core/Selector';
import {Table, type TableColumn} from '@astryxdesign/core/Table';
import {EmptyState} from '@astryxdesign/core/EmptyState';
import {ArrowDownTrayIcon, DocumentChartBarIcon} from '@heroicons/react/24/outline';
import {Page, PageError, PageLoading} from '../../components/PageState';
import {DataTable, type DataColumn, type ListMeta} from '../../components/DataTable';
import {Metric, MetricRow} from '../../components/Metric';
import {Status} from '../../components/Status';
import {Balance} from '../../components/Amount';
import {useDebounced, useListQuery, useResource} from '../../lib/hooks';
import {dateTime, number, titleCase} from '../../lib/format';
import {unitById} from '../../lib/session';
import {dateTimeIn, hasPage} from './shared';

interface Checks {
  generatedAt: string;
  datasets: {dataset: string; count: number; canExport: boolean}[];
  ledgerByKind: {kind: string; currency: string; entries: number; amountPaise: number}[] | null;
  attendanceMinutes: number;
  archivedHistoricalMinutes: number;
  pendingDuplicateReviews: number;
  sourceReconciled: boolean;
  notice: string;
  reconciliationUrl: string;
}

interface HistoryRow extends Record<string, unknown> {
  id: number;
  externalId: string;
  enrollmentId: number;
  startsAt: string;
  actualMinutes: number;
  status: string;
  financialEffect: string;
}

interface History {
  data: HistoryRow[];
  meta: ListMeta;
  statuses: string[];
}

type DatasetRow = Checks['datasets'][number] & Record<string, unknown>;
type LedgerRow = NonNullable<Checks['ledgerByKind']>[number] & Record<string, unknown>;

const DEFAULTS = {q: '', status: '', sort: 'starts_at', dir: 'desc', page: '1', per_page: '25'};

export default function Exports() {
  const unitId = Number(useParams().unit);
  const unit = unitById(unitId);
  const checks = useResource<Checks>(`/app-api/units/${unitId}/overview/exports`);
  const [query, setQuery] = useListQuery(DEFAULTS);
  const [searchText, setSearchText] = useState(query.q);
  const debounced = useDebounced(searchText);
  useEffect(() => {
    if (debounced !== query.q) setQuery({q: debounced});
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debounced]);
  const history = useResource<History>(`/app-api/units/${unitId}/overview/exports/history`, query);
  const timezone = unit?.timezone ?? 'UTC';

  const historyColumns = useMemo<DataColumn<HistoryRow>[]>(
    () => [
      {key: 'externalId', header: 'Source ID', sortKey: 'external_id', renderCell: row => <Text>{row.externalId}</Text>},
      {key: 'startsAt', header: 'Class time', sortKey: 'starts_at', renderCell: row => <Text hasTabularNumbers>{dateTimeIn(row.startsAt, timezone)}</Text>},
      {key: 'enrollmentId', header: 'Assignment', align: 'end', renderCell: row => <Text hasTabularNumbers>{`#${row.enrollmentId}`}</Text>},
      {key: 'actualMinutes', header: 'Minutes', sortKey: 'actual_minutes', align: 'end', renderCell: row => <Text hasTabularNumbers>{number(row.actualMinutes)}</Text>},
      {key: 'status', header: 'Status', sortKey: 'status', renderCell: row => <Status value={row.status} />},
      {key: 'financialEffect', header: 'Money effect', renderCell: row => <Text>{titleCase(row.financialEffect)}</Text>},
    ],
    [timezone],
  );

  if (checks.isLoading) return <PageLoading />;
  if (checks.error || !checks.data) {
    return (
      <Page title="Exports & checks">
        <PageError error={checks.error ?? new Error('Not available')} onRetry={checks.reload} />
      </Page>
    );
  }
  const data = checks.data;
  const exportable = data.datasets.filter(d => d.canExport);

  const datasetColumns: TableColumn<DatasetRow>[] = [
    {key: 'dataset', header: 'Register', renderCell: r => <Text>{titleCase(r.dataset)}</Text>},
    {key: 'count', header: 'Records', align: 'end', renderCell: r => <Text hasTabularNumbers>{number(r.count)}</Text>},
    {
      key: 'canExport',
      header: '',
      align: 'end',
      renderCell: r => (
        <Button label="CSV" variant="ghost" size="sm" href={`/erp/units/${unitId}/exports/${r.dataset}`} icon={<Icon icon={ArrowDownTrayIcon} size="sm" />} {...{download: true}} />
      ),
    },
  ];
  const ledgerColumns: TableColumn<LedgerRow>[] = [
    {key: 'kind', header: 'Entry type', renderCell: r => <Text>{titleCase(r.kind)}</Text>},
    {key: 'currency', header: 'Currency', renderCell: r => <Text>{r.currency}</Text>},
    {key: 'entries', header: 'Entries', align: 'end', renderCell: r => <Text hasTabularNumbers>{number(r.entries)}</Text>},
    {key: 'amountPaise', header: 'Total', align: 'end', renderCell: r => <Balance paise={r.amountPaise} />},
  ];

  const hasFilters = Boolean(query.q || query.status);

  return (
    <Page
      title="Exports & checks"
      description={`Application totals at ${dateTime(data.generatedAt)}`}
      actions={
        <>
          <Button label="Reconciliation JSON" variant="secondary" href={data.reconciliationUrl} icon={<Icon icon={ArrowDownTrayIcon} size="sm" />} {...{download: true}} />
          {hasPage(unit, 'admissions') && <Button label="Sales report" variant="secondary" href={`/app/u/${unitId}/sales-report`} icon={<Icon icon={DocumentChartBarIcon} size="sm" />} />}
        </>
      }>
      <Banner status={data.sourceReconciled ? 'success' : 'info'} title={data.notice} />
      <MetricRow>
        <Metric label="Attendance minutes" value={number(data.attendanceMinutes)} hint="Not rejected" />
        <Metric label="Archived historical minutes" value={number(data.archivedHistoricalMinutes)} hint="No money posted" />
        <Metric
          label="Pending duplicate reviews"
          value={number(data.pendingDuplicateReviews)}
          tone={data.pendingDuplicateReviews ? 'warning' : undefined}
          href={hasPage(unit, 'attendance') ? `/app/u/${unitId}/attendance?queue=duplicates` : undefined}
        />
      </MetricRow>

      <Card padding={4}>
        <VStack gap={3}>
          <HStack gap={2} vAlign="center" hAlign="between">
            <Heading level={2}>Registers and CSV exports</Heading>
            <Text color="secondary">{`${number(exportable.length)} of ${number(data.datasets.length)} downloadable`}</Text>
          </HStack>
          {exportable.length === 0 ? (
            <EmptyState title="No exports for your role" isCompact />
          ) : (
            <Table<DatasetRow> data={exportable as DatasetRow[]} columns={datasetColumns} idKey="dataset" hasHover textOverflow="truncate" />
          )}
        </VStack>
      </Card>

      {data.ledgerByKind && (
        <Card padding={4}>
          <VStack gap={3}>
            <Heading level={2}>Ledger totals by type</Heading>
            {data.ledgerByKind.length === 0 ? (
              <EmptyState title="No ledger entries yet" isCompact />
            ) : (
              <Table<LedgerRow> data={data.ledgerByKind as LedgerRow[]} columns={ledgerColumns} idKey={r => `${r.kind}-${r.currency}`} hasHover textOverflow="truncate" />
            )}
          </VStack>
        </Card>
      )}

      <VStack gap={2}>
        <Heading level={2}>Archived class history</Heading>
        <Text color="secondary">Historical attendance imported without financial posting. Times in {timezone}.</Text>
      </VStack>
      <DataTable<HistoryRow>
        label="Archived history"
        rows={history.data?.data}
        meta={history.data?.meta}
        columns={historyColumns}
        idKey="id"
        isLoading={history.isLoading}
        isRefreshing={history.isRefreshing}
        search={searchText}
        onSearch={setSearchText}
        searchPlaceholder="Search source ID"
        filters={
          <Selector
            label="Status"
            isLabelHidden
            placeholder="Any status"
            hasClear
            value={query.status || null}
            onChange={value => setQuery({status: value ?? ''})}
            options={(history.data?.statuses ?? []).map(s => ({value: s, label: titleCase(s)}))}
          />
        }
        onSort={(sort, dir) => setQuery({sort, dir}, {resetPage: true})}
        onPage={page => setQuery({page: String(page)}, {resetPage: false})}
        onPageSize={size => setQuery({per_page: String(size)})}
        hasActiveFilters={hasFilters}
        onClearFilters={() => {
          setSearchText('');
          setQuery({q: '', status: ''});
        }}
        emptyTitle="No archived history"
        emptyDescription="Rows appear here after an approved migration import."
        phoneCard={{
          title: row => row.externalId,
          subtitle: row => `${dateTimeIn(row.startsAt, timezone)} · ${number(row.actualMinutes)} min`,
          trailing: row => <Status value={row.status} />,
        }}
      />
    </Page>
  );
}
