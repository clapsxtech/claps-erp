import {useEffect, useMemo, useState} from 'react';
import {useParams} from 'react-router';
import {VStack, HStack, StackItem} from '@astryxdesign/core/Layout';
import {Card} from '@astryxdesign/core/Card';
import {Text} from '@astryxdesign/core/Text';
import {Button} from '@astryxdesign/core/Button';
import {Icon} from '@astryxdesign/core/Icon';
import {Badge} from '@astryxdesign/core/Badge';
import {TextInput} from '@astryxdesign/core/TextInput';
import {Selector} from '@astryxdesign/core/Selector';
import {TabList, Tab} from '@astryxdesign/core/TabList';
import {Table, TableSelectionToolbar, useTableSelection, useTableSelectionState, type TableColumn} from '@astryxdesign/core/Table';
import {List, ListItem} from '@astryxdesign/core/List';
import {CheckboxInput} from '@astryxdesign/core/CheckboxInput';
import {Pagination} from '@astryxdesign/core/Pagination';
import {EmptyState} from '@astryxdesign/core/EmptyState';
import {Skeleton} from '@astryxdesign/core/Skeleton';
import {ProgressBar} from '@astryxdesign/core/ProgressBar';
import {Banner} from '@astryxdesign/core/Banner';
import {useToast} from '@astryxdesign/core/Toast';
import {MagnifyingGlassIcon} from '@heroicons/react/24/outline';
import {Page, PageError} from '../../components/PageState';
import {Status} from '../../components/Status';
import {Metric, MetricRow} from '../../components/Metric';
import {Toned} from '../../components/Amount';
import {ApiError, postJson} from '../../lib/api';
import {useDebounced, useIsPhone, useListQuery, useResource} from '../../lib/hooks';
import {number, titleCase} from '../../lib/format';
import {unitById} from '../../lib/session';
import {ActionDrawer} from './ActionDrawer';
import {lessonAction, type ActionConfig} from './lessonActions';
import {canAct, dayLabel, newReference, workspaceAction} from './shared';

interface AttendanceRow extends Record<string, unknown> {
  id: number;
  lessonId: number;
  localDate: string;
  startTime: string;
  kind: string;
  studentName: string;
  studentCode: string;
  teacherName: string;
  subjectName: string;
  actualMinutes: number;
  status: string;
  pendingReviews: number;
  studentFinanceState: string;
  teacherFinanceState: string;
}

interface MissingRow extends Record<string, unknown> {
  lessonId: number;
  localDate: string;
  startTime: string;
  kind: string;
  studentName: string;
  studentCode: string;
  teacherName: string;
  subjectName: string;
}

interface ReviewRow extends Record<string, unknown> {
  id: number;
  attendance: {id: number; label: string};
  other: {id: number; label: string};
  status: string;
}

interface Meta {
  page: number;
  perPage: number;
  total: number;
}

interface Register {
  data: AttendanceRow[];
  meta: Meta & {sort: string; dir: string};
  counts: {approval: number; import: number; missing: number; reviews: number; submitted: number};
  timezone: string;
}

type Queue = 'approval' | 'import' | 'missing' | 'duplicates' | 'all';
const QUEUES: Queue[] = ['approval', 'import', 'missing', 'duplicates', 'all'];
const DEFAULTS = {queue: 'approval', q: '', status: '', sort: 'starts_at', dir: 'desc', page: '1'};

interface BulkProgress {
  done: number;
  total: number;
  failures: {id: number; label: string; message: string}[];
  running: boolean;
}

function errorText(reason: unknown): string {
  if (reason instanceof ApiError) {
    const first = Object.values(reason.fieldErrors)[0]?.[0];
    return first ?? reason.message;
  }
  return 'Could not reach the server.';
}

function FinanceState({value}: {value: string}) {
  const tone = value === 'Imported' || value === 'Accrued' ? 'success' : value.startsWith('Rejected') ? 'error' : value.startsWith('Pending') || value.startsWith('Approved,') ? 'warning' : undefined;
  return <Toned tone={tone}>{value}</Toned>;
}

export default function Attendance() {
  const unitId = Number(useParams().unit);
  const unit = unitById(unitId);
  const isPhone = useIsPhone();
  const showToast = useToast();
  const [query, setQuery] = useListQuery(DEFAULTS);
  const queue: Queue = QUEUES.includes(query.queue as Queue) ? (query.queue as Queue) : 'approval';
  const [searchText, setSearchText] = useState(query.q);
  const debounced = useDebounced(searchText);
  useEffect(() => {
    if (debounced !== query.q) setQuery({q: debounced});
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debounced]);

  // The register also carries the queue counts, so it always loads.
  const register = useResource<Register>(`/app-api/units/${unitId}/attendance`, queue === 'all' ? {q: query.q, status: query.status, sort: query.sort, dir: query.dir, page: query.page} : {});
  const queuePath = queue === 'approval' || queue === 'import' || queue === 'missing' ? `/app-api/units/${unitId}/attendance/queues/${queue}` : null;
  const pageParam = `${queue}_queue_page`;
  const queueData = useResource<{data: AttendanceRow[] | MissingRow[]; meta: Meta & {pageParam: string}}>(queuePath, queuePath ? {[pageParam]: query.page} : undefined);
  // The resource keeps the last result while the next loads; never show one queue's rows under another's tab.
  const current = queueData.data && queueData.data.meta.pageParam === pageParam ? queueData.data : undefined;
  const queueLoading = queuePath !== null && current === undefined;
  const reviews = useResource<{data: ReviewRow[]; meta: Meta}>(queue === 'duplicates' ? `/app-api/units/${unitId}/attendance/reviews` : null, {page: query.page});

  const [drawer, setDrawer] = useState<{config: ActionConfig; isOpen: boolean} | null>(null);
  const [selectedKeys, setSelectedKeys] = useState<Set<string>>(new Set());
  const [bulk, setBulk] = useState<BulkProgress | null>(null);
  const [rowBusy, setRowBusy] = useState<number | null>(null);
  useEffect(() => setSelectedKeys(new Set()), [queue, query.page]);

  const canApprove = canAct(unit, 'approve');
  const canCorrect = canAct(unit, 'correct');
  const canImport = canAct(unit, 'import-batch');
  const reloadAll = () => {
    register.reload();
    queueData.reload();
    reviews.reload();
  };

  const rows: AttendanceRow[] = queue === 'all' ? register.data?.data ?? [] : queue === 'approval' || queue === 'import' ? ((current?.data as AttendanceRow[] | undefined) ?? []) : [];
  const selectable = (row: AttendanceRow) =>
    queue === 'approval' ? canApprove && row.status === 'submitted' && row.pendingReviews === 0 : queue === 'import' ? canImport : false;
  const {selectionConfig, selectionState} = useTableSelectionState<AttendanceRow>({
    data: rows,
    idKey: row => String(row.id),
    getIsItemSelectable: selectable,
    selectedKeys,
    setSelectedKeys,
  });
  const selectionPlugin = useTableSelection<AttendanceRow>({...selectionConfig, getRowLabel: row => `${row.studentName} ${row.localDate}`});

  const openDrawer = (config: ActionConfig) => setDrawer({config, isOpen: true});

  async function approveOne(row: AttendanceRow) {
    setRowBusy(row.id);
    try {
      const result = await postJson<{message?: string}>(workspaceAction(unitId, 'approve'), {attendance_id: row.id});
      showToast({body: result?.message ?? 'Attendance approved.', uniqueID: `approve-${row.id}`});
      reloadAll();
    } catch (reason) {
      showToast({body: `${row.studentName}: ${errorText(reason)}`, uniqueID: `approve-${row.id}`});
    } finally {
      setRowBusy(null);
    }
  }

  // Bulk approval loops the single-row POST, one at a time, so every
  // row gets the same duplicate, parent-review and policy checks.
  async function approveSelected() {
    const chosen = rows.filter(row => selectedKeys.has(String(row.id)) && selectable(row));
    if (!chosen.length) return;
    const progress: BulkProgress = {done: 0, total: chosen.length, failures: [], running: true};
    setBulk({...progress});
    for (const row of chosen) {
      try {
        await postJson(workspaceAction(unitId, 'approve'), {attendance_id: row.id});
      } catch (reason) {
        progress.failures.push({id: row.id, label: `${row.studentName} · ${dayLabel(row.localDate)} ${row.startTime}`, message: errorText(reason)});
      }
      progress.done += 1;
      setBulk({...progress});
    }
    progress.running = false;
    setBulk({...progress});
    setSelectedKeys(new Set());
    const ok = progress.total - progress.failures.length;
    showToast({body: `${number(ok)} of ${number(progress.total)} approved. No money was transferred.`, uniqueID: 'bulk-approve'});
    reloadAll();
  }

  function importSelected() {
    const ids = rows.filter(row => selectedKeys.has(String(row.id))).map(row => row.id);
    if (!ids.length) return;
    openDrawer({
      action: workspaceAction(unitId, 'import-batch'),
      permission: 'import-batch',
      title: `Import ${ids.length} class${ids.length === 1 ? '' : 'es'} for student charge`,
      description: 'Posts the student consumption charge for each selected attended class. Teacher approval stays separate. No money is transferred.',
      submitLabel: 'Import for charge',
      successMessage: 'Imported for student charge.',
      fields: [{kind: 'text', name: 'reference', label: 'Stable import reference', required: true, description: 'Unique reviewed batch reference. Re-using it with the same rows is safe; with other rows it is refused.'}],
      fixed: {attendance_ids: ids},
      initial: {reference: newReference('import')},
      confirmText: `This charges ${ids.length} student class${ids.length === 1 ? '' : 'es'} at the agreed rates.`,
    });
  }

  function correct(row: AttendanceRow) {
    openDrawer({
      action: workspaceAction(unitId, 'correct'),
      permission: 'correct',
      title: 'Correct attended minutes',
      description: `${row.studentName} · ${row.subjectName} · ${dayLabel(row.localDate)} ${row.startTime}. Reverses any posted charge and accrual; the row goes back to submitted and needs import and approval again.`,
      submitLabel: 'Correct minutes',
      successMessage: 'Attendance corrected.',
      fields: [
        {kind: 'number', name: 'actual_minutes', label: 'Actual minutes', required: true, min: 1},
        {kind: 'textarea', name: 'reason', label: 'Audit reason', required: true},
      ],
      fixed: {attendance_id: row.id},
      initial: {actual_minutes: String(row.actualMinutes)},
      confirmText: 'Posted student charges and teacher accruals for this class are reversed until it is imported and approved again.',
      isDestructive: true,
    });
  }

  function resolve(review: ReviewRow) {
    openDrawer({
      action: workspaceAction(unitId, 'duplicate'),
      permission: 'duplicate',
      title: 'Resolve duplicate review',
      description: 'Record the teacher reply from Gallabox and decide whether both classes happened.',
      submitLabel: 'Resolve review',
      successMessage: 'Review resolved.',
      fields: [
        {kind: 'select', name: 'decision', label: 'Decision', required: true, options: [{value: 'genuine', label: 'Genuine: both classes happened'}, {value: 'duplicate', label: 'Duplicate: one record is wrong'}]},
        {kind: 'select', name: 'duplicate_attendance_id', label: 'Which record is the duplicate', description: 'Only for a duplicate decision.', options: [review.attendance, review.other].map(r => ({value: String(r.id), label: r.label}))},
        {kind: 'textarea', name: 'teacher_reply', label: 'Teacher reply', required: true},
        {kind: 'textarea', name: 'reason', label: 'Reason', required: true},
      ],
      fixed: {review_id: review.id},
      confirmText: 'A duplicate decision rejects the chosen record and reverses its charge and accrual.',
      isDestructive: true,
    });
  }

  const counts = register.data?.counts;
  const setQueue = (next: string) => setQuery({queue: next, page: '1'});

  const rowActions = (row: AttendanceRow) => {
    const buttons = [];
    if (row.pendingReviews > 0) {
      buttons.push(<Button key="review" label="Resolve duplicate first" size="sm" variant="secondary" onClick={() => setQueue('duplicates')} />);
    } else if (canApprove && (row.status === 'submitted' || (queue === 'approval' && row.status === 'approved'))) {
      const label = row.kind === 'demo' ? 'Approve free demo' : row.status === 'approved' ? 'Recheck accrual' : 'Approve';
      buttons.push(<Button key="approve" label={label} size="sm" variant="primary" isLoading={rowBusy === row.id} isDisabled={bulk?.running} onClick={() => approveOne(row)} />);
    }
    if (canCorrect && row.status !== 'rejected') buttons.push(<Button key="correct" label="Correct" size="sm" variant="ghost" onClick={() => correct(row)} />);
    return buttons.length ? <HStack gap={1} hAlign="end">{buttons}</HStack> : <Text color="secondary" size="sm">View only</Text>;
  };

  const columns = useMemo<TableColumn<AttendanceRow>[]>(
    () => [
      {key: 'studentName', header: 'Student', renderCell: r => <VStack gap={0}><Text maxLines={1}>{r.studentName}</Text><Text size="sm" color="secondary">{`${r.studentCode} · ${r.subjectName}${r.kind === 'demo' ? ' · demo' : ''}`}</Text></VStack>},
      {key: 'teacherName', header: 'Teacher', renderCell: r => <Text maxLines={1}>{r.teacherName}</Text>},
      {key: 'localDate', header: 'Class', renderCell: r => <Text hasTabularNumbers>{`${dayLabel(r.localDate, {day: '2-digit', month: 'short', year: 'numeric'})} ${r.startTime}`}</Text>},
      {key: 'actualMinutes', header: 'Minutes', align: 'end', renderCell: r => <Text hasTabularNumbers>{number(r.actualMinutes)}</Text>},
      {key: 'status', header: 'Status', renderCell: r => <Status value={r.status} />},
      {key: 'studentFinanceState', header: 'Student charge', renderCell: r => <FinanceState value={r.studentFinanceState} />},
      {key: 'teacherFinanceState', header: 'Teacher pay', renderCell: r => <FinanceState value={r.teacherFinanceState} />},
      {key: 'id', header: '', align: 'end', renderCell: r => (queue === 'import' ? <Text size="sm" color="secondary">{`#${r.id}`}</Text> : rowActions(r))},
    ],
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [queue, canApprove, canCorrect, rowBusy, bulk?.running],
  );

  if (register.error && !register.data) {
    return (
      <Page title="Attendance">
        <PageError error={register.error} onRetry={register.reload} />
      </Page>
    );
  }

  const pager = (meta: Meta | undefined) =>
    meta && meta.total > meta.perPage ? (
      <HStack hAlign="end">
        <Pagination page={meta.page} onChange={page => setQuery({page: String(page)}, {resetPage: false})} totalItems={meta.total} pageSize={meta.perPage} variant={isPhone ? 'compact' : 'pages'} size="sm" label="Attendance pages" />
      </HStack>
    ) : null;

  const loading = <VStack gap={2}>{Array.from({length: 5}, (_, i) => <Skeleton key={i} height={36} width="100%" />)}</VStack>;

  const attendanceList = (meta: Meta | undefined, isLoading: boolean, emptyTitle: string, withSelection: boolean) => {
    if (isLoading) return loading;
    if (rows.length === 0) return <EmptyState title={emptyTitle} />;
    const toolbar = withSelection && selectionState.hasSelection && (
      <TableSelectionToolbar
        selection={selectionState}
        renderSelectionLabel={count => `${number(count)} selected`}
        startContent={
          queue === 'approval' ? (
            <Button label={`Approve selected (${selectionState.selectedCount})`} variant="primary" size="sm" isLoading={bulk?.running} onClick={approveSelected} />
          ) : (
            <Button label={`Import selected (${selectionState.selectedCount})`} variant="primary" size="sm" onClick={importSelected} />
          )
        }
      />
    );
    const body = isPhone ? (
      <List hasDividers edge="flush">
        {rows.map(row => (
          <ListItem
            key={row.id}
            startContent={
              withSelection && selectable(row) ? (
                <CheckboxInput
                  label={`Select ${row.studentName}`}
                  isLabelHidden
                  value={selectedKeys.has(String(row.id))}
                  onChange={checked =>
                    setSelectedKeys(current => {
                      const next = new Set(current);
                      if (checked) next.add(String(row.id));
                      else next.delete(String(row.id));
                      return next;
                    })
                  }
                />
              ) : undefined
            }
            label={`${row.studentName} · ${row.actualMinutes} min`}
            description={
              <VStack gap={1}>
                <Text size="sm" color="secondary">{`${dayLabel(row.localDate)} ${row.startTime} · ${row.subjectName} · ${row.teacherName}`}</Text>
                <Text size="sm">{`Student: ${row.studentFinanceState} · Teacher: ${row.teacherFinanceState}`}</Text>
              </VStack>
            }
            endContent={queue === 'import' ? <Status value={row.status} /> : rowActions(row)}
          />
        ))}
      </List>
    ) : (
      <Table<AttendanceRow>
        data={rows}
        columns={columns}
        idKey={row => String(row.id)}
        density="balanced"
        hasHover
        textOverflow="truncate"
        plugins={withSelection ? {selection: selectionPlugin} : undefined}
      />
    );
    return (
      <VStack gap={3}>
        {toolbar}
        {body}
        {pager(meta)}
      </VStack>
    );
  };

  let content;
  if (queue === 'approval') {
    content = (
      <VStack gap={3}>
        <Text color="secondary">Approving accrues the teacher's earnings for attended classes. Demos are free and unpaid. Rows with a pending duplicate review must be resolved first. No money is transferred.</Text>
        {attendanceList(current?.meta, queueLoading, 'Nothing waiting for approval', canApprove)}
      </VStack>
    );
  } else if (queue === 'import') {
    content = (
      <VStack gap={3}>
        <Text color="secondary">Importing posts the student's charge for attended non-demo classes. It is separate from teacher approval.</Text>
        {!canImport && <Banner status="info" title="View only for your current access" description="Importing for charge is a finance administrator action." collapsible={false} />}
        {attendanceList(current?.meta, queueLoading, 'Nothing waiting for import', canImport)}
      </VStack>
    );
  } else if (queue === 'missing') {
    const missing = (current?.data as MissingRow[] | undefined) ?? [];
    const canRecord = canAct(unit, 'attendance');
    const canNoShow = canAct(unit, 'no-show');
    const lessonFor = (row: MissingRow) => ({id: row.lessonId, enrollmentId: 0, studentId: 0, studentName: row.studentName, teacherId: 0, teacherName: row.teacherName, subjectName: row.subjectName, localDate: row.localDate, startTime: row.startTime, durationMinutes: 0});
    content = (
      <VStack gap={3}>
        <Text color="secondary">Ended classes with no attendance and no no-show, grouped by teacher. Chase the teacher, or record minutes or a no-show yourself.</Text>
        {queueLoading ? (
          loading
        ) : missing.length === 0 ? (
          <EmptyState title="Every ended class has attendance or a no-show" />
        ) : (
          <List hasDividers edge="flush">
            {missing.map(row => (
              <ListItem
                key={row.lessonId}
                label={`${row.teacherName} · ${row.studentName}`}
                description={`${dayLabel(row.localDate)} ${row.startTime} · ${row.subjectName}${row.kind === 'demo' ? ' (demo)' : ''} · Lesson #${row.lessonId}`}
                endContent={
                  canRecord || canNoShow ? (
                    <HStack gap={1}>
                      {canRecord && <Button label="Record minutes" size="sm" variant="secondary" onClick={() => openDrawer({...lessonAction('attendance', unitId, register.data?.timezone ?? 'UTC', lessonFor(row)), initial: {}})} />}
                      {canNoShow && <Button label="No-show" size="sm" variant="ghost" onClick={() => openDrawer(lessonAction('no-show', unitId, register.data?.timezone ?? 'UTC', lessonFor(row)))} />}
                    </HStack>
                  ) : (
                    <Text size="sm" color="secondary">Waiting for the teacher</Text>
                  )
                }
              />
            ))}
          </List>
        )}
        {pager(current?.meta)}
      </VStack>
    );
  } else if (queue === 'duplicates') {
    const canResolve = canAct(unit, 'duplicate');
    content = (
      <VStack gap={3}>
        <Text color="secondary">Two attendance records for the same student and teacher on one day. Ask the teacher on Gallabox, then record the reply.</Text>
        {reviews.isLoading ? (
          loading
        ) : (reviews.data?.data ?? []).length === 0 ? (
          <EmptyState title="No pending duplicate reviews" />
        ) : (
          <List hasDividers edge="flush">
            {(reviews.data?.data ?? []).map(review => (
              <ListItem
                key={review.id}
                label={`Review #${review.id}`}
                description={
                  <VStack gap={1}>
                    <Text size="sm">{review.attendance.label}</Text>
                    <Text size="sm">{review.other.label}</Text>
                  </VStack>
                }
                endContent={canResolve ? <Button label="Resolve" size="sm" variant="primary" onClick={() => resolve(review)} /> : <Status value={review.status} />}
              />
            ))}
          </List>
        )}
        {pager(reviews.data?.meta)}
      </VStack>
    );
  } else {
    content = (
      <VStack gap={3}>
        <HStack gap={2} wrap="wrap" vAlign="center">
          <StackItem size="fill">
            <TextInput label="Search attendance" isLabelHidden value={searchText} onChange={setSearchText} placeholder="Student, teacher, code or #id" startIcon={<Icon icon={MagnifyingGlassIcon} size="sm" />} width="100%" />
          </StackItem>
          <Selector
            label="Status"
            isLabelHidden
            placeholder="Any status"
            hasClear
            value={query.status || null}
            onChange={value => setQuery({status: value ?? ''})}
            options={['submitted', 'approved', 'rejected'].map(value => ({value, label: titleCase(value)}))}
          />
          <Selector
            label="Order"
            isLabelHidden
            value={`${query.sort}:${query.dir}`}
            onChange={value => {
              const [sort, dir] = (value ?? 'starts_at:desc').split(':');
              setQuery({sort, dir});
            }}
            options={[
              {value: 'starts_at:desc', label: 'Newest class first'},
              {value: 'starts_at:asc', label: 'Oldest class first'},
              {value: 'student:asc', label: 'Student A–Z'},
              {value: 'teacher:asc', label: 'Teacher A–Z'},
              {value: 'minutes:desc', label: 'Most minutes'},
            ]}
          />
          {unit?.exports.includes('attendance') && <Button label="Export CSV" variant="secondary" href={`/erp/units/${unitId}/exports/attendance`} {...{download: true}} />}
        </HStack>
        {register.isRefreshing && <ProgressBar label="Updating" isIndeterminate />}
        {attendanceList(register.data?.meta, register.isLoading, query.q || query.status ? 'No matching attendance' : 'No attendance yet', false)}
        {register.data && <Text color="secondary" hasTabularNumbers>{`${number(register.data.meta.total)} records`}</Text>}
      </VStack>
    );
  }

  return (
    <Page title="Attendance" description={counts ? `${number(counts.approval)} waiting for approval · times in ${register.data?.timezone}` : undefined}>
      {counts && (
        <MetricRow>
          <Metric label="Waiting for approval" value={number(counts.approval)} onClick={() => setQueue('approval')} isActive={queue === 'approval'} />
          <Metric label="Waiting for import" value={number(counts.import)} onClick={() => setQueue('import')} isActive={queue === 'import'} />
          <Metric label="Ended without attendance" value={number(counts.missing)} tone={counts.missing > 0 ? 'warning' : undefined} onClick={() => setQueue('missing')} isActive={queue === 'missing'} />
          <Metric label="Duplicate reviews" value={number(counts.reviews)} tone={counts.reviews > 0 ? 'error' : undefined} onClick={() => setQueue('duplicates')} isActive={queue === 'duplicates'} />
        </MetricRow>
      )}
      {bulk && (
        <Card padding={4}>
          <VStack gap={2}>
            <ProgressBar label={bulk.running ? 'Approving selected attendance' : 'Approval finished'} value={bulk.done} max={bulk.total} hasValueLabel formatValueLabel={(value, max) => `${value} of ${max}`} variant={bulk.failures.length ? 'warning' : 'success'} />
            {bulk.failures.length > 0 && (
              <Banner status="error" title={`${bulk.failures.length} could not be approved`} collapsible={false}>
                <VStack gap={1}>
                  {bulk.failures.map(failure => (
                    <Text key={failure.id} size="sm">{`${failure.label}: ${failure.message}`}</Text>
                  ))}
                </VStack>
              </Banner>
            )}
            {!bulk.running && (
              <HStack hAlign="end">
                <Button label="Dismiss" variant="ghost" size="sm" onClick={() => setBulk(null)} />
              </HStack>
            )}
          </VStack>
        </Card>
      )}
      <Card padding={4}>
        <VStack gap={3}>
          <TabList value={queue} onChange={setQueue} overflow="auto">
            <Tab value="approval" label="Approve" endContent={counts?.approval ? <Badge label={number(counts.approval)} /> : undefined} />
            <Tab value="import" label="Import for charge" endContent={counts?.import ? <Badge label={number(counts.import)} /> : undefined} />
            <Tab value="missing" label="Missing" endContent={counts?.missing ? <Badge variant="warning" label={number(counts.missing)} /> : undefined} />
            <Tab value="duplicates" label="Duplicates" endContent={counts?.reviews ? <Badge variant="error" label={number(counts.reviews)} /> : undefined} />
            <Tab value="all" label="All attendance" />
          </TabList>
          {queueData.isRefreshing && queue !== 'all' && <ProgressBar label="Updating" isIndeterminate />}
          {content}
        </VStack>
      </Card>
      {drawer && (
        <ActionDrawer
          isOpen={drawer.isOpen}
          onOpenChange={isOpen => setDrawer(current => (current ? {...current, isOpen} : null))}
          unitId={unitId}
          title={drawer.config.title}
          description={drawer.config.description}
          fields={drawer.config.fields}
          fixed={drawer.config.fixed}
          initial={drawer.config.initial}
          alwaysSend={drawer.config.alwaysSend}
          action={drawer.config.action}
          submitLabel={drawer.config.submitLabel}
          successMessage={drawer.config.successMessage}
          confirmText={drawer.config.confirmText}
          isDestructive={drawer.config.isDestructive}
          onSaved={() => {
            setSelectedKeys(new Set());
            reloadAll();
          }}
        />
      )}
    </Page>
  );
}
