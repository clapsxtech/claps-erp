// From Sheets (SheetTransitionController::index): the
// validated read-only copies of the team's Google Sheets during the transition.
// The server picks which sources this user may see (by team page) and whether the
// administrator refresh review is allowed; it answers 404 while the feature is off.
import {useEffect, useState} from 'react';
import {useSearchParams} from 'react-router';
import {VStack, HStack, StackItem} from '@astryxdesign/core/Layout';
import {Card} from '@astryxdesign/core/Card';
import {Text} from '@astryxdesign/core/Text';
import {Button} from '@astryxdesign/core/Button';
import {Selector} from '@astryxdesign/core/Selector';
import {TextInput} from '@astryxdesign/core/TextInput';
import {CheckboxInput} from '@astryxdesign/core/CheckboxInput';
import {Banner} from '@astryxdesign/core/Banner';
import {type TableColumn} from '@astryxdesign/core/Table';
import {RowTable} from '../../components/RowTable';
import {EmptyState} from '@astryxdesign/core/EmptyState';
import {Pagination} from '@astryxdesign/core/Pagination';
import {MetadataList, MetadataListItem} from '@astryxdesign/core/MetadataList';
import {PageError, PageLoading} from '../../components/PageState';
import {useResource} from '../../lib/hooks';
import {ApiError} from '../../lib/api';
import {number, titleCase} from '../../lib/format';

interface SheetRow extends Record<string, unknown> {
  sourceRow: number;
  identity: string | null;
  issue?: string | null;
  data: Record<string, string>;
}

interface SheetsData {
  sources: {key: string; label: string}[];
  source: string;
  units: {id: number; name: string}[];
  unit: number;
  isAdministrator: boolean;
  review: boolean;
  sheetUrl: string;
  isStale: boolean;
  timezone: string;
  state: {lastSuccessAt: string | null; lastAttemptAt: string | null; lastStatus: string | null};
  run: {status: string; reason: string | null; rowCount?: number; issueCount?: number} | null;
  columns: string[];
  rows: SheetRow[];
  meta: {page: number; perPage: number; total: number};
}

export default function MigrationSheets() {
  const [params, setParams] = useSearchParams();
  const query = {unit: params.get('unit') ?? '', source: params.get('source') ?? '', review: params.get('review') ?? '', q: params.get('q') ?? '', page: params.get('page') ?? ''};
  const {data, error, isLoading, isRefreshing, reload} = useResource<SheetsData>('/erp/from-sheets', query);
  const [code, setCode] = useState(query.q);
  useEffect(() => setCode(query.q), [query.q]);
  const update = (patch: Record<string, string>) =>
    setParams(current => {
      const next = new URLSearchParams(current);
      for (const [key, value] of Object.entries(patch)) {
        if (value) next.set(key, value);
        else next.delete(key);
      }
      if (!('page' in patch)) next.delete('page');
      return next;
    });

  if (error && !data) {
    if (error instanceof ApiError && error.status === 404) {
      return <EmptyState title="From Sheets is not enabled" description="The Sheets transition copy is turned off on this server. Use the original Sheets or the screens in this app." />;
    }
    if (error instanceof ApiError && error.status === 403) {
      return <EmptyState title="No Sheets copies for your role" description="None of the Sheets sources belong to a page your team role can open." />;
    }
    return <PageError error={error} onRetry={reload} />;
  }
  if (isLoading || !data) return <PageLoading />;

  const columns: TableColumn<SheetRow>[] = [
    {key: 'sourceRow', header: 'Source row', align: 'end', renderCell: r => <Text hasTabularNumbers>{String(r.sourceRow)}</Text>},
    {key: 'identity', header: 'Identity', renderCell: r => <Text>{r.identity ?? '—'}</Text>},
    ...(data.review ? [{key: 'issue', header: 'Review reason', renderCell: (r: SheetRow) => <Text>{r.issue ?? 'Passed'}</Text>}] : []),
    ...data.columns.map(column => ({key: `data.${column}`, header: titleCase(column).replace(/\b\w/g, c => c.toUpperCase()), renderCell: (r: SheetRow) => <Text>{r.data[column] ?? ''}</Text>})),
  ];

  return (
    <VStack gap={4}>
      <Text color="secondary">Update the original Sheet while the transition is in progress. These records do not create ERP charges, receipts, salary accruals or scheduled lessons.</Text>
      <Card padding={4}>
        <VStack gap={3}>
          <HStack gap={2} wrap="wrap" vAlign="end">
            <Selector label="Team" value={String(data.unit)} onChange={value => update({unit: value ?? ''})} options={data.units.map(u => ({value: String(u.id), label: u.name}))} />
            <Selector label="Source" value={data.source} onChange={value => update({source: value ?? ''})} options={data.sources.map(s => ({value: s.key, label: s.label}))} />
            <StackItem size="fill">
              <TextInput label="Exact record code" placeholder="CCA…, CT… or attendance ID" value={code} onChange={value => setCode(value.slice(0, 100))} width="100%" />
            </StackItem>
            <Button label="Show records" variant="secondary" onClick={() => update({q: code.trim()})} />
          </HStack>
          {data.isAdministrator && (
            <CheckboxInput label="Latest refresh review (all teams)" value={data.review} onChange={checked => update({review: checked ? '1' : ''})} />
          )}
          <HStack>
            <Button label="Open original Sheet" variant="ghost" href={data.sheetUrl} {...{target: '_blank', rel: 'noopener noreferrer'}} />
          </HStack>
        </VStack>
      </Card>
      {data.review && <Banner status="warning" title="Administrator review" description="This refresh may contain unresolved rows. It has not replaced the published copy unless its status is ready." collapsible={false} />}
      {data.isStale && (
        <Banner status="error" title={data.run ? 'This copy is older than the refresh target. Check the original Sheet before acting.' : 'No validated copy is available yet.'} collapsible={false} />
      )}
      <MetadataList orientation="horizontal">
        <MetadataListItem label={`Last successful refresh (${data.timezone})`}>{data.state.lastSuccessAt ?? 'Never'}</MetadataListItem>
        <MetadataListItem label={`Latest attempt (${data.timezone})`}>{`${data.state.lastAttemptAt ?? 'Never'} · ${data.state.lastStatus ?? 'Not started'}`}</MetadataListItem>
      </MetadataList>
      {data.run && (
        <Text>
          {`Showing ${data.run.status} snapshot · ${number(data.meta.total)} matching rows.`}
          {data.run.rowCount !== undefined ? ` ${number(data.run.rowCount)} source rows · ${number(data.run.issueCount ?? 0)} rows requiring review.` : ''}
          {data.run.reason ? ` Reason: ${data.run.reason}.` : ''}
        </Text>
      )}
      {data.review && <Text color="secondary">Correct unresolved rows in the source Sheet, then refresh again. A removed identity requires an operator to review the exact preview digest. Refreshes are published only when all rows pass.</Text>}
      {data.source === 'timetable' && <Text color="secondary">Times and teacher labels are displayed as written in the Sheet. They have not been converted into dated lessons or checked for timetable conflicts.</Text>}
      {data.source === 'balances' && <Text color="secondary">Balances are reported by the source Sheet. They are not reconciled ERP ledger balances.</Text>}
      <Card padding={4}>
        <VStack gap={3} isScrollable>
          {data.rows.length === 0 ? (
            <EmptyState title="No records to show for this selection." isCompact />
          ) : (
            <RowTable<SheetRow> data={data.rows} columns={columns} idKey={r => r.sourceRow} density="compact" textOverflow="truncate" />
          )}
          {isRefreshing && <Text color="secondary" size="sm">Updating…</Text>}
          {data.meta.total > data.meta.perPage && (
            <Pagination page={data.meta.page} onChange={page => update({page: String(page)})} totalItems={data.meta.total} pageSize={data.meta.perPage} size="sm" label="Record pages" />
          )}
        </VStack>
      </Card>
    </VStack>
  );
}
