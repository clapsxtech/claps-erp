// Operations & audit (global, superadmin only). Classic: AdministrationController::operations.
// Read-only: health indicators and the audit trail (never audit payloads).
import {useEffect, useMemo, useState} from 'react';
import {VStack} from '@astryxdesign/core/Layout';
import {Card} from '@astryxdesign/core/Card';
import {Heading, Text} from '@astryxdesign/core/Text';
import {Button} from '@astryxdesign/core/Button';
import {Banner} from '@astryxdesign/core/Banner';
import {Grid} from '@astryxdesign/core/Grid';
import {MetadataList, MetadataListItem} from '@astryxdesign/core/MetadataList';
import {Page, PageError} from '../../components/PageState';
import {DataTable, type DataColumn, type ListMeta} from '../../components/DataTable';
import {Status} from '../../components/Status';
import {useDebounced, useListQuery, useResource} from '../../lib/hooks';
import {dateTime, titleCase} from '../../lib/format';

type CheckValue = string | number | boolean | null;

interface Health {
  status: 'ok' | 'warning' | 'error';
  checks: Record<string, Record<string, CheckValue>>;
}

interface AuditEvent extends Record<string, unknown> {
  id: number;
  createdAt: string;
  actorId: number | null;
  actorName: string | null;
  unitId: number | null;
  unitName: string | null;
  action: string;
  entityType: string;
  entityId: number | null;
}

interface OperationsData {
  health: Health;
  events: AuditEvent[];
  meta: ListMeta;
}

const DEFAULTS = {q: '', page: '1', per_page: '25'};
const HEALTH_TONE: Record<string, string> = {ok: 'active', warning: 'pending', error: 'failed'};
const HEALTH_LABEL: Record<string, string> = {ok: 'Healthy', warning: 'Needs a look', error: 'Error'};

function show(value: CheckValue): string {
  if (typeof value === 'boolean') return value ? 'Yes' : 'No';
  if (value === null || value === '') return 'None';
  return String(value);
}

export default function Operations() {
  const [query, setQuery] = useListQuery(DEFAULTS);
  const [searchText, setSearchText] = useState(query.q);
  const debounced = useDebounced(searchText);
  useEffect(() => {
    if (debounced !== query.q) setQuery({q: debounced});
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debounced]);
  const ops = useResource<OperationsData>('/app-api/admin/operations', query);

  const columns = useMemo<DataColumn<AuditEvent>[]>(
    () => [
      {key: 'createdAt', header: 'Time (UTC)', renderCell: e => <Text hasTabularNumbers>{e.createdAt ?? '—'}</Text>},
      {key: 'actorName', header: 'Who', renderCell: e => <Text color={e.actorName ? undefined : 'secondary'}>{e.actorName ?? 'System'}</Text>},
      {key: 'unitName', header: 'Team', renderCell: e => <Text color={e.unitId ? undefined : 'secondary'}>{e.unitId ? e.unitName ?? `#${e.unitId}` : 'Site'}</Text>},
      {key: 'action', header: 'Action', renderCell: e => <Text>{e.action}</Text>},
      {key: 'entityType', header: 'Record', renderCell: e => <Text color="secondary">{`${e.entityType}${e.entityId ? ` #${e.entityId}` : ''}`}</Text>},
    ],
    [],
  );

  if (ops.error && !ops.data) {
    return (
      <Page title="Operations & audit">
        <PageError error={ops.error} onRetry={ops.reload} />
      </Page>
    );
  }

  const health = ops.data?.health;

  return (
    <Page
      title="Operations & audit"
      description="Current health indicators and the account and team activity trail."
      actions={
        <>
          <Button label="Teams & accounts" variant="secondary" href="/app/admin/teams" />
          <Button label="Migration" variant="secondary" href="/app/admin/migration" />
          <Button label="Refresh" variant="primary" onClick={ops.reload} isLoading={ops.isRefreshing} />
        </>
      }>
      {health && (
        <Banner
          status={health.status === 'ok' ? 'success' : health.status === 'warning' ? 'warning' : 'error'}
          title={`Health: ${HEALTH_LABEL[health.status] ?? health.status}`}
          description="A healthy result does not certify backups or a completed recovery drill."
        />
      )}
      {health && (
        <Grid columns={{minWidth: 240, repeat: 'fill'}} gap={3}>
          {Object.entries(health.checks).map(([name, check]) => (
            <Card key={name} padding={4}>
              <VStack gap={3}>
                <VStack gap={1}>
                  <Heading level={3}>{titleCase(name)}</Heading>
                  <Status value={HEALTH_TONE[String(check.status)] ?? 'failed'} label={HEALTH_LABEL[String(check.status)] ?? String(check.status)} />
                </VStack>
                <MetadataList label={{position: 'start'}}>
                  {Object.entries(check)
                    .filter(([key]) => key !== 'status')
                    .map(([key, value]) => (
                      <MetadataListItem key={key} label={titleCase(key)}>
                        {show(value)}
                      </MetadataListItem>
                    ))}
                </MetadataList>
              </VStack>
            </Card>
          ))}
        </Grid>
      )}

      <Card padding={4}>
        <VStack gap={2}>
          <Heading level={2}>Backup & recovery</Heading>
          <Text color="secondary">
            Use the deployment operator's backup tool to export the database, verify the checksum and restore it into a disposable local database. Production
            recovery stays an explicit operator action; uploads need their own backup policy. The runbook is docs/ERP_OPERATIONS_RUNBOOK.md.
          </Text>
        </VStack>
      </Card>

      <Heading level={2}>Recent activity</Heading>
      <DataTable<AuditEvent>
        label="Activity"
        rows={ops.data?.events}
        meta={ops.data?.meta}
        columns={columns}
        idKey="id"
        isLoading={ops.isLoading}
        isRefreshing={ops.isRefreshing}
        search={searchText}
        onSearch={setSearchText}
        searchPlaceholder="Search by action or record type"
        onSort={() => undefined}
        onPage={page => setQuery({page: String(page)}, {resetPage: false})}
        onPageSize={size => setQuery({per_page: String(size)})}
        hasActiveFilters={Boolean(query.q)}
        onClearFilters={() => {
          setSearchText('');
          setQuery({q: ''});
        }}
        emptyTitle="No activity yet"
        phoneCard={{
          title: e => e.action,
          subtitle: e => `${dateTime(e.createdAt)} · ${e.actorName ?? 'System'} · ${e.unitName ?? 'Site'}`,
        }}
      />
    </Page>
  );
}
