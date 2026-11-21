// Payments & dues › Classes to charge. The attended classes waiting for a
// student-charge import, read from /app-api/units/{unit}/money/charge-queue (page
// "money": the same rows and fields as the Attendance import queue). Selected rows
// are imported through the existing import-batch action, as on Attendance.
import {useEffect, useState} from 'react';
import {VStack, HStack} from '@astryxdesign/core/Layout';
import {Card} from '@astryxdesign/core/Card';
import {Text} from '@astryxdesign/core/Text';
import {Button} from '@astryxdesign/core/Button';
import {Table, TableSelectionToolbar, useTableSelection, useTableSelectionState, type TableColumn} from '@astryxdesign/core/Table';
import {List, ListItem} from '@astryxdesign/core/List';
import {CheckboxInput} from '@astryxdesign/core/CheckboxInput';
import {Pagination} from '@astryxdesign/core/Pagination';
import {EmptyState} from '@astryxdesign/core/EmptyState';
import {Skeleton} from '@astryxdesign/core/Skeleton';
import {Banner} from '@astryxdesign/core/Banner';
import {PageError} from '../../components/PageState';
import {Status} from '../../components/Status';
import {Toned} from '../../components/Amount';
import {useIsPhone, useResource} from '../../lib/hooks';
import {number} from '../../lib/format';
import type {UnitAccess} from '../../lib/session';
import {ActionDrawer} from '../Classes/ActionDrawer';
import {canAct, dayLabel, newReference, workspaceAction} from '../Classes/shared';

interface ChargeRow extends Record<string, unknown> {
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

interface Queue {
  data: ChargeRow[];
  meta: {page: number; perPage: number; total: number; pageParam: string};
  timezone: string;
}

export function ChargeQueueTab({unitId, unit, onImported}: {unitId: number; unit: UnitAccess | undefined; onImported: () => void}) {
  const isPhone = useIsPhone();
  const [page, setPage] = useState(1);
  const queue = useResource<Queue>(`/app-api/units/${unitId}/money/charge-queue`, {import_queue_page: page});
  const [selectedKeys, setSelectedKeys] = useState<Set<string>>(new Set());
  const [drawerOpen, setDrawerOpen] = useState(false);
  const [importIds, setImportIds] = useState<number[]>([]);
  useEffect(() => setSelectedKeys(new Set()), [page]);

  const canImport = canAct(unit, 'import-batch');
  const rows = queue.data?.data ?? [];
  const {selectionConfig, selectionState} = useTableSelectionState<ChargeRow>({
    data: rows,
    idKey: row => String(row.id),
    getIsItemSelectable: () => canImport,
    selectedKeys,
    setSelectedKeys,
  });
  const selectionPlugin = useTableSelection<ChargeRow>({...selectionConfig, getRowLabel: row => `${row.studentName} ${row.localDate}`});

  const importSelected = () => {
    const ids = rows.filter(row => selectedKeys.has(String(row.id))).map(row => row.id);
    if (!ids.length) return;
    setImportIds(ids);
    setDrawerOpen(true);
  };

  if (queue.error && !queue.data) return <PageError error={queue.error} onRetry={queue.reload} />;

  const columns: TableColumn<ChargeRow>[] = [
    {key: 'studentName', header: 'Student', renderCell: r => <VStack gap={0}><Text maxLines={1}>{r.studentName}</Text><Text size="sm" color="secondary">{`${r.studentCode} · ${r.subjectName}`}</Text></VStack>},
    {key: 'teacherName', header: 'Teacher', renderCell: r => <Text maxLines={1}>{r.teacherName}</Text>},
    {key: 'localDate', header: 'Class', renderCell: r => <Text hasTabularNumbers>{`${dayLabel(r.localDate, {day: '2-digit', month: 'short', year: 'numeric'})} ${r.startTime}`}</Text>},
    {key: 'actualMinutes', header: 'Minutes', align: 'end', renderCell: r => <Text hasTabularNumbers>{number(r.actualMinutes)}</Text>},
    {key: 'status', header: 'Attendance', renderCell: r => <Status value={r.status} />},
    {key: 'studentFinanceState', header: 'Student charge', renderCell: r => <Toned tone="warning">{r.studentFinanceState}</Toned>},
  ];

  const toggle = (id: number, checked: boolean) =>
    setSelectedKeys(current => {
      const next = new Set(current);
      if (checked) next.add(String(id));
      else next.delete(String(id));
      return next;
    });

  let body;
  if (queue.isLoading) body = <VStack gap={2}>{Array.from({length: 4}, (_, i) => <Skeleton key={i} height={36} width="100%" />)}</VStack>;
  else if (rows.length === 0) body = <EmptyState title="No classes waiting to be charged" description="Attended classes appear here until they are imported for the student charge." />;
  else if (isPhone)
    body = (
      <List hasDividers edge="flush">
        {rows.map(row => (
          <ListItem
            key={row.id}
            startContent={canImport ? <CheckboxInput label={`Select ${row.studentName}`} isLabelHidden value={selectedKeys.has(String(row.id))} onChange={checked => toggle(row.id, checked)} /> : undefined}
            label={`${row.studentName} · ${row.actualMinutes} min`}
            description={`${dayLabel(row.localDate)} ${row.startTime} · ${row.subjectName} · ${row.teacherName}`}
            endContent={<Status value={row.status} />}
          />
        ))}
      </List>
    );
  else body = <Table<ChargeRow> data={rows} columns={columns} idKey={row => String(row.id)} density="balanced" hasHover textOverflow="truncate" plugins={canImport ? {selection: selectionPlugin} : undefined} />;

  const meta = queue.data?.meta;
  return (
    <VStack gap={3}>
      <Card padding={3}>
        <Text color="secondary" size="sm">
          Attended non-demo classes not yet charged to the student. Importing posts each class's charge at the agreed hourly rate; teacher approval stays separate. No money is transferred.
        </Text>
      </Card>
      {!canImport && <Banner status="info" title="View only for your current access" description="Importing for charge is a finance action." collapsible={false} />}
      {canImport && selectionState.hasSelection && (
        <TableSelectionToolbar
          selection={selectionState}
          renderSelectionLabel={count => `${number(count)} selected`}
          startContent={<Button label={`Import selected (${selectionState.selectedCount})`} variant="primary" size="sm" onClick={importSelected} />}
        />
      )}
      {body}
      {meta && (
        <HStack gap={2} hAlign="between" vAlign="center">
          <Text size="sm" color="secondary">{`${number(meta.total)} class${meta.total === 1 ? '' : 'es'} waiting`}</Text>
          {meta.total > meta.perPage && <Pagination page={meta.page} onChange={setPage} totalItems={meta.total} pageSize={meta.perPage} variant={isPhone ? 'compact' : 'pages'} size="sm" label="Classes to charge pages" />}
        </HStack>
      )}
      <ActionDrawer
        isOpen={drawerOpen}
        onOpenChange={setDrawerOpen}
        unitId={unitId}
        action={workspaceAction(unitId, 'import-batch')}
        title={`Import ${importIds.length} class${importIds.length === 1 ? '' : 'es'} for student charge`}
        description="Posts the student consumption charge for each selected attended class. Teacher approval stays separate. No money is transferred."
        submitLabel="Import for charge"
        successMessage="Imported for student charge."
        fields={[{kind: 'text', name: 'reference', label: 'Stable import reference', required: true, description: 'Unique reviewed batch reference. Re-using it with the same rows is safe; with other rows it is refused.'}]}
        fixed={{attendance_ids: importIds}}
        initial={{reference: newReference('import')}}
        confirmText={`This charges ${importIds.length} student class${importIds.length === 1 ? '' : 'es'} at the agreed rates.`}
        onSaved={() => {
          setSelectedKeys(new Set());
          queue.reload();
          onImported();
        }}
      />
    </VStack>
  );
}
