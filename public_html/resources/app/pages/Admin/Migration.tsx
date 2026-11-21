// Migration center status (global, superadmin only). Preview and import are throttled,
// password-confirmed file uploads, so they stay on the classic screen; this only lists
// completed import batches and links there.
import {VStack} from '@astryxdesign/core/Layout';
import {Card} from '@astryxdesign/core/Card';
import {Heading, Text} from '@astryxdesign/core/Text';
import {Button} from '@astryxdesign/core/Button';
import {Table, type TableColumn} from '@astryxdesign/core/Table';
import {EmptyState} from '@astryxdesign/core/EmptyState';
import {Page, PageError, PageLoading} from '../../components/PageState';
import {Metric, MetricRow} from '../../components/Metric';
import {useResource} from '../../lib/hooks';
import {date, dateTime, number} from '../../lib/format';

interface Batch extends Record<string, unknown> {
  id: number;
  sourceId: string;
  cutoff: string;
  createdAt: string | null;
  actorName: string;
}

interface MigrationData {
  batches: Batch[];
  historyRows: number;
}

const CLASSIC = '/erp/migration-center';

export default function Migration() {
  const {data, error, isLoading, reload} = useResource<MigrationData>('/app-api/admin/migration');
  if (isLoading) return <PageLoading />;
  if (error || !data) {
    return (
      <Page title="Migration center">
        <PageError error={error ?? new Error('Not found')} onRetry={reload} />
      </Page>
    );
  }
  const columns: TableColumn<Batch>[] = [
    {key: 'id', header: 'Batch', renderCell: b => <Text hasTabularNumbers>{`#${b.id}`}</Text>},
    {key: 'sourceId', header: 'Source', renderCell: b => <Text>{b.sourceId}</Text>},
    {key: 'cutoff', header: 'Cutoff', renderCell: b => <Text hasTabularNumbers>{date(b.cutoff)}</Text>},
    {key: 'createdAt', header: 'Imported', renderCell: b => <Text hasTabularNumbers>{dateTime(b.createdAt)}</Text>},
    {key: 'actorName', header: 'Approved by', renderCell: b => <Text>{b.actorName}</Text>},
  ];
  return (
    <Page
      title="Migration center"
      description="Historical snapshot imports. Preview and import run on the classic screen."
      actions={<Button label="Open migration center" variant="primary" href={CLASSIC} />}>
      <MetricRow>
        <Metric label="Imported batches" value={number(data.batches.length)} />
        <Metric label="Historical lessons imported" value={number(data.historyRows)} />
      </MetricRow>
      <Card padding={4}>
        <VStack gap={3}>
          <Heading level={2}>Completed imports</Heading>
          <Text color="secondary">Take and verify a database backup before every import. Web imports are limited to 100 rows per reviewed snapshot.</Text>
          {data.batches.length === 0 ? (
            <EmptyState title="No imports yet" isCompact actions={<Button label="Open migration center" variant="secondary" href={CLASSIC} />} />
          ) : (
            <Table<Batch> data={data.batches} columns={columns} idKey="id" hasHover textOverflow="truncate" />
          )}
        </VStack>
      </Card>
    </Page>
  );
}
