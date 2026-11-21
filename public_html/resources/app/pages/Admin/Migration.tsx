// Migration center (global, administrators only) and the From Sheets read-only
// copies. Preview and import post multipart uploads to the migration routes, which
// keep their throttles (10 previews and 5 imports a minute), the 30-minute
// approval of the exact previewed digest, the 100-row web limit and the
// current-password confirmation.
import {useState} from 'react';
import {useSearchParams} from 'react-router';
import {VStack, HStack} from '@astryxdesign/core/Layout';
import {Card} from '@astryxdesign/core/Card';
import {Heading, Text} from '@astryxdesign/core/Text';
import {Button} from '@astryxdesign/core/Button';
import {Table, type TableColumn} from '@astryxdesign/core/Table';
import {EmptyState} from '@astryxdesign/core/EmptyState';
import {TabList, Tab} from '@astryxdesign/core/TabList';
import {FileInput} from '@astryxdesign/core/FileInput';
import {TextInput} from '@astryxdesign/core/TextInput';
import {DateInput} from '@astryxdesign/core/DateInput';
import {CheckboxInput} from '@astryxdesign/core/CheckboxInput';
import {Banner} from '@astryxdesign/core/Banner';
import {CodeBlock} from '@astryxdesign/core/CodeBlock';
import {AlertDialog} from '@astryxdesign/core/AlertDialog';
import {useToast} from '@astryxdesign/core/Toast';
import type {ComponentProps} from 'react';
import {Page, PageError, PageLoading} from '../../components/PageState';
import {Metric, MetricRow} from '../../components/Metric';
import {useResource} from '../../lib/hooks';
import {ApiError} from '../../lib/api';
import {date, dateTime, number} from '../../lib/format';
import {session} from '../../lib/session';
import MigrationSheets from './MigrationSheets';

type DateValue = ComponentProps<typeof DateInput>['value'];

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

interface Report {
  valid: boolean;
  digest?: string;
  [key: string]: unknown;
}

function csrfToken(): string {
  return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
}

/** Multipart POST to a route that answers JSON; the browser sets the boundary. */
async function postForm<T>(url: string, form: FormData): Promise<T> {
  const response = await fetch(url, {
    method: 'POST',
    credentials: 'same-origin',
    headers: {Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrfToken()},
    body: form,
    redirect: 'manual',
  });
  if (response.type === 'opaqueredirect' || (response.status >= 300 && response.status < 400)) {
    throw new ApiError(0, 'The server did not confirm this. Reload to check before trying again.');
  }
  let body: {message?: string; errors?: Record<string, string[]>} & Record<string, unknown> = {};
  try {
    body = await response.json();
  } catch {
    // Non-JSON error page.
  }
  if (response.ok) return body as T;
  const fallback: Record<number, string> = {
    403: 'Only administrators can run migrations.',
    419: 'This page expired. Reload and try again; nothing was imported.',
    422: 'Some details need fixing. Nothing was imported.',
    429: 'Too many attempts. Wait a minute and try again.',
  };
  throw new ApiError(response.status, (response.status === 422 ? fallback[422] : body.message) || fallback[response.status] || 'Something went wrong. Nothing was imported.', body.errors ?? {});
}

/** Reads the cutoff from the normalized export so the approval form can prefill it. */
async function cutoffOf(file: File): Promise<string> {
  try {
    const parsed = JSON.parse(await file.text());
    const cutoff = parsed?.references?.cutoff_date;
    return typeof cutoff === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(cutoff) ? cutoff : '';
  } catch {
    return '';
  }
}

export default function Migration() {
  const [params, setParams] = useSearchParams();
  const isAdmin = session().user.isAdmin;
  const tab = params.get('tab') === 'sheets' || !isAdmin ? 'sheets' : 'center';
  return (
    <Page title={tab === 'sheets' ? 'From Sheets' : 'Migration center'} description={tab === 'sheets' ? 'Validated copies of the records your team maintains in Google Sheets.' : 'Preview, review and import an approved historical snapshot.'}>
      {isAdmin && (
        <TabList value={tab} onChange={value => setParams(value === 'center' ? {} : {tab: value})}>
          <Tab value="center" label="Migration center" />
          <Tab value="sheets" label="From Sheets" />
        </TabList>
      )}
      {tab === 'center' ? <MigrationCenter /> : <MigrationSheets />}
    </Page>
  );
}

function MigrationCenter() {
  const showToast = useToast();
  const {data, error, isLoading, reload} = useResource<MigrationData>('/app-api/admin/migration');
  const [source, setSource] = useState<File | null>(null);
  const [previewing, setPreviewing] = useState(false);
  const [previewError, setPreviewError] = useState<string>();
  const [report, setReport] = useState<Report | null>(null);
  const [previewed, setPreviewed] = useState<File | null>(null);
  // Approval form.
  const [again, setAgain] = useState<File | null>(null);
  const [cutoff, setCutoff] = useState('');
  const [backup, setBackup] = useState('');
  const [password, setPassword] = useState('');
  const [approved, setApproved] = useState(false);
  const [confirming, setConfirming] = useState(false);
  const [importing, setImporting] = useState(false);
  const [importErrors, setImportErrors] = useState<Record<string, string>>({});
  const [importError, setImportError] = useState<string>();

  if (isLoading) return <PageLoading />;
  if (error || !data) return <PageError error={error ?? new Error('Not found')} onRetry={reload} />;

  async function preview() {
    if (!source) {
      setPreviewError('Choose the normalized JSON export first.');
      return;
    }
    setPreviewing(true);
    setPreviewError(undefined);
    setReport(null);
    const form = new FormData();
    form.append('source', source);
    try {
      const result = await postForm<Report>('/erp/migration-center/preview', form);
      setReport(result);
      setPreviewed(source);
      setAgain(null);
      setCutoff(await cutoffOf(source));
      setBackup('');
      setPassword('');
      setApproved(false);
      setImportErrors({});
      setImportError(undefined);
    } catch (reason) {
      setPreviewError(reason instanceof ApiError ? (reason.fieldErrors.source?.[0] ?? reason.message) : 'Could not reach the server.');
    } finally {
      setPreviewing(false);
    }
  }

  function review() {
    const missing: Record<string, string> = {};
    if (!again) missing.source = 'Upload the same normalized file.';
    if (!cutoff) missing.cutoff = 'Required.';
    if (!backup.trim()) missing.backup_reference = 'Required.';
    if (!password) missing.current_password = 'Required.';
    if (!approved) missing.confirm_import = 'Confirm the approval.';
    setImportErrors(missing);
    setImportError(Object.keys(missing).length ? 'Fill in the highlighted fields. Nothing was imported.' : undefined);
    if (!Object.keys(missing).length) setConfirming(true);
  }

  async function execute() {
    if (!report?.digest || !again) return;
    setImporting(true);
    const form = new FormData();
    form.append('source', again);
    form.append('digest', report.digest);
    form.append('cutoff', cutoff);
    form.append('backup_reference', backup.trim());
    form.append('current_password', password);
    form.append('confirm_import', '1');
    try {
      const result = await postForm<{batch_id: number}>('/erp/migration-center/execute', form);
      setConfirming(false);
      showToast({body: `Import completed and reconciled. Batch #${result.batch_id}. Historical attendance was not charged again.`, uniqueID: 'migration-import'});
      setReport(null);
      setPreviewed(null);
      setSource(null);
      setAgain(null);
      setPassword('');
      reload();
    } catch (reason) {
      setConfirming(false);
      setPassword('');
      if (reason instanceof ApiError) {
        setImportErrors(Object.fromEntries(Object.entries(reason.fieldErrors).map(([key, messages]) => [key, messages[0]])));
        setImportError(reason.fieldErrors.source?.[0] ?? reason.message);
      } else setImportError('Could not reach the server. Nothing was imported.');
    } finally {
      setImporting(false);
    }
  }

  const status = (name: string) => (importErrors[name] ? {type: 'error' as const, message: importErrors[name]} : undefined);
  const columns: TableColumn<Batch>[] = [
    {key: 'id', header: 'Batch', renderCell: b => <Text hasTabularNumbers>{`#${b.id}`}</Text>},
    {key: 'sourceId', header: 'Source', renderCell: b => <Text>{b.sourceId}</Text>},
    {key: 'cutoff', header: 'Cutoff', renderCell: b => <Text hasTabularNumbers>{date(b.cutoff)}</Text>},
    {key: 'createdAt', header: 'Imported', renderCell: b => <Text hasTabularNumbers>{dateTime(b.createdAt)}</Text>},
    {key: 'actorName', header: 'Approved by', renderCell: b => <Text>{b.actorName}</Text>},
  ];

  return (
    <VStack gap={4}>
      <MetricRow>
        <Metric label="Imported batches" value={number(data.batches.length)} />
        <Metric label="Historical lessons imported" value={number(data.historyRows)} />
      </MetricRow>
      <Card padding={4}>
        <VStack gap={2}>
          <Heading level={2}>1. Prepare the source</Heading>
          <Text>
            Export and map the authoritative records into the normalized JSON format. Include stable student/teacher codes, team mappings, subject mappings, a cutoff date, and approved opening balances. Preserve the original export.
          </Text>
          <Text color="secondary">
            Only normalized JSON is accepted here, up to 5 MiB and 5,000 rows for preview. The operator format and example are documented in docs/ERP_MIGRATION.md. This screen does not convert arbitrary spreadsheets.
          </Text>
        </VStack>
      </Card>
      <Card padding={4}>
        <VStack gap={3}>
          <Heading level={2}>2. Preview without importing</Heading>
          <FileInput
            label="Normalized source file"
            accept=".json,application/json"
            maxSize={5 * 1024 * 1024}
            value={source}
            onChange={files => setSource(Array.isArray(files) ? (files[0] ?? null) : files)}
            isRequired
            status={previewError ? {type: 'error', message: previewError} : undefined}
            width="100%"
          />
          <HStack>
            <Button label="Validate and preview" variant="primary" isLoading={previewing} onClick={preview} />
          </HStack>
        </VStack>
      </Card>
      {report && (
        <Card padding={4}>
          <VStack gap={3}>
            <Heading level={2}>{report.valid ? 'Preview passed' : 'Resolve these issues before import'}</Heading>
            <Banner
              status={report.valid ? 'success' : 'error'}
              title={report.valid ? 'Nothing has been imported yet.' : 'This export cannot be imported.'}
              description={previewed ? `Previewed file: ${previewed.name}` : undefined}
              collapsible={false}
            />
            <CodeBlock code={JSON.stringify(report, null, 2)} language="json" title="Preview report" />
          </VStack>
        </Card>
      )}
      {report?.valid && report.digest && (
        <Card padding={4}>
          <VStack gap={3}>
            <Heading level={2}>3. Approve this exact snapshot</Heading>
            <Text>
              First take and verify a database backup. Confirm the opening totals and cutoff with the business owner. A typed backup reference records your confirmation; the application cannot verify an external backup from its name.
            </Text>
            <Text color="secondary">Upload the same file again. Approval expires after 30 minutes. Web execution supports snapshots up to 100 rows; larger migrations require the operator workflow.</Text>
            {importError && <Banner status="error" title={importError} collapsible={false} />}
            <FileInput
              label="Same normalized source file"
              accept=".json,application/json"
              value={again}
              onChange={files => setAgain(Array.isArray(files) ? (files[0] ?? null) : files)}
              isRequired
              status={status('source')}
              width="100%"
            />
            <DateInput label="Approved cutoff" isRequired value={(cutoff || undefined) as DateValue} onChange={value => setCutoff(value ?? '')} status={status('cutoff')} />
            <TextInput label="Verified backup reference" isRequired value={backup} onChange={value => setBackup(value.slice(0, 200))} status={status('backup_reference')} />
            <TextInput label="Your current password" isRequired type="password" autoComplete="current-password" value={password} onChange={setPassword} status={status('current_password')} />
            <CheckboxInput
              label="I approve this exact snapshot, its opening balances and cutoff for this database."
              value={approved}
              onChange={setApproved}
              isRequired
              status={status('confirm_import')}
            />
            <HStack>
              <Button label="Import approved snapshot" variant="primary" onClick={review} />
            </HStack>
          </VStack>
        </Card>
      )}
      <AlertDialog
        isOpen={confirming}
        onOpenChange={open => !open && !importing && setConfirming(false)}
        title="Import this approved snapshot?"
        description={`This writes the previewed records and opening balances into this database with cutoff ${cutoff}. It cannot be undone from this screen.`}
        actionLabel="Import snapshot"
        cancelLabel="Go back"
        isActionLoading={importing}
        onAction={execute}
      />
      <Card padding={4}>
        <VStack gap={3}>
          <Heading level={2}>Completed imports</Heading>
          <Text color="secondary">Take and verify a database backup before every import. Web imports are limited to 100 rows per reviewed snapshot.</Text>
          {data.batches.length === 0 ? <EmptyState title="No imports yet" isCompact /> : <Table<Batch> data={data.batches} columns={columns} idKey="id" hasHover textOverflow="truncate" />}
        </VStack>
      </Card>
    </VStack>
  );
}
