// My tasks, with the unit-wide register for roles that have the
// Follow-ups page. Completion posts to the existing routes.
import {useEffect, useMemo, useState} from 'react';
import {useParams, useSearchParams} from 'react-router';
import {VStack, HStack} from '@astryxdesign/core/Layout';
import {Text} from '@astryxdesign/core/Text';
import {Button} from '@astryxdesign/core/Button';
import {Icon} from '@astryxdesign/core/Icon';
import {Selector} from '@astryxdesign/core/Selector';
import {SegmentedControl, SegmentedControlItem} from '@astryxdesign/core/SegmentedControl';
import {Dialog, DialogHeader} from '@astryxdesign/core/Dialog';
import {List, ListItem} from '@astryxdesign/core/List';
import {Banner} from '@astryxdesign/core/Banner';
import {TextInput} from '@astryxdesign/core/TextInput';
import {useToast} from '@astryxdesign/core/Toast';
import {PlusIcon} from '@heroicons/react/24/outline';
import {Page, PageError} from '../../components/PageState';
import {DataTable, type DataColumn, type ListMeta} from '../../components/DataTable';
import {FormDrawer, type Field} from '../../components/FormDrawer';
import {Status} from '../../components/Status';
import {Toned} from '../../components/Amount';
import {useDebounced, useListQuery, useResource} from '../../lib/hooks';
import {ApiError, postJson} from '../../lib/api';
import {date, dateTime, number} from '../../lib/format';
import {session, unitById} from '../../lib/session';
import {canAct, hasPage, postOwnTask, todayIn} from './shared';

interface TaskItem {
  id: number;
  title: string;
  completedAt: string | null;
}

interface TaskRow extends Record<string, unknown> {
  id: number;
  title: string;
  notes: string | null;
  dueOn: string | null;
  status: string;
  remainingItems: number;
  assignedToMe: boolean;
  studentName: string | null;
  assigneeName: string | null;
  items: TaskItem[];
}

interface TaskList {
  data: TaskRow[];
  meta: ListMeta;
  scope: 'mine' | 'all';
  counts: {mineOpen: number; allOpen: number | null};
  can: {seeAll: boolean; create: boolean; complete: boolean; completeItem: boolean; completeOwn: boolean};
}

const DEFAULTS = {scope: 'mine', status: 'open', q: '', sort: 'due_on', dir: 'asc', page: '1', per_page: '25'};

export default function Tasks() {
  const unitId = Number(useParams().unit);
  const unit = unitById(unitId);
  const showToast = useToast();
  const [urlQuery, setQuery] = useListQuery(DEFAULTS);
  // The team-wide view is the Follow-ups register; other roles always get their own tasks.
  const query = urlQuery.scope === 'all' && !hasPage(unit, 'followups') ? {...urlQuery, scope: 'mine'} : urlQuery;
  const [params, setParams] = useSearchParams();
  const [searchText, setSearchText] = useState(query.q);
  const debounced = useDebounced(searchText);
  useEffect(() => {
    if (debounced !== query.q) setQuery({q: debounced});
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debounced]);

  const list = useResource<TaskList>(`/app-api/units/${unitId}/overview/tasks`, query);
  const can = list.data?.can;
  const today = unit ? todayIn(unit.timezone) : '';
  const [busy, setBusy] = useState<string>();
  const [checklistFor, setChecklistFor] = useState<number>();
  const [actionError, setActionError] = useState<string>();
  const checklistTask = list.data?.data.find(row => row.id === checklistFor);

  const creating = params.get('new') === 'task';
  const setCreating = (open: boolean) =>
    setParams(current => {
      const next = new URLSearchParams(current);
      if (open) next.set('new', 'task');
      else next.delete('new');
      return next;
    });
  // Assignees come from the unit lookup endpoint (type "members").
  const members = useResource<{id: number; label: string}[]>(creating && can?.create ? `/erp/units/${unitId}/lookup` : null, {type: 'members'});

  const canCompleteTask = (row: TaskRow) => Boolean(can && row.status !== 'completed' && (can.complete || (row.assignedToMe && can.completeOwn)));
  // Adding checklist items is the task-item action (BusinessController); the server refuses it on completed tasks.
  const canAddItems = canAct(unit, 'task-item') && !session().sheetsAuthority;
  const canAddItem = (row: TaskRow) => canAddItems && row.status !== 'completed';
  const [newItem, setNewItem] = useState('');
  const [itemError, setItemError] = useState<string>();
  const [addingItem, setAddingItem] = useState(false);
  const openChecklist = (id: number | undefined) => {
    setChecklistFor(id);
    setNewItem('');
    setItemError(undefined);
  };
  async function addItem(row: TaskRow) {
    const title = newItem.trim();
    if (!title) {
      setItemError('Enter the checklist item.');
      return;
    }
    setAddingItem(true);
    setItemError(undefined);
    try {
      await postJson(`/erp/units/${unitId}/business-actions/task-item`, {task_id: row.id, title});
      showToast({body: 'Checklist item added.', uniqueID: `task-item-${row.id}`});
      setNewItem('');
      list.reload();
    } catch (reason) {
      setItemError(reason instanceof ApiError ? Object.values(reason.fieldErrors)[0]?.[0] ?? reason.message : 'Could not reach the server. Nothing was saved.');
    } finally {
      setAddingItem(false);
    }
  }
  const canCompleteItem = (row: TaskRow) => Boolean(can && row.status !== 'completed' && (can.completeItem || (row.assignedToMe && can.completeOwn)));

  async function run(key: string, work: () => Promise<unknown>, message: string) {
    setBusy(key);
    setActionError(undefined);
    try {
      await work();
      showToast({body: message, uniqueID: `task-${key}`});
    } catch (reason) {
      setActionError(reason instanceof ApiError ? reason.message : 'Could not reach the server. Nothing was changed.');
    } finally {
      setBusy(undefined);
      list.reload();
    }
  }

  const completeTask = (row: TaskRow) =>
    run(
      `task-${row.id}`,
      () =>
        can?.complete
          ? postJson(`/erp/units/${unitId}/business-actions/task-update`, {task_id: row.id, status: 'completed'})
          : postOwnTask(`/erp/my-tasks/${row.id}/complete`),
      'Task completed.',
    );

  const completeItem = (row: TaskRow, item: TaskItem) =>
    run(
      `item-${item.id}`,
      () =>
        can?.completeItem
          ? postJson(`/erp/units/${unitId}/business-actions/task-item-complete`, {item_id: item.id})
          : postOwnTask(`/erp/my-tasks/${row.id}/items/${item.id}/complete`),
      'Checklist item completed.',
    );

  const columns = useMemo<DataColumn<TaskRow>[]>(() => {
    const cols: DataColumn<TaskRow>[] = [
      {
        key: 'title',
        header: 'Task',
        sortKey: 'title',
        renderCell: row => (
          <VStack gap={0.5}>
            <Text maxLines={1}>{row.title}</Text>
            {row.notes && (
              <Text color="secondary" size="sm" maxLines={1}>
                {row.notes}
              </Text>
            )}
          </VStack>
        ),
      },
      {
        key: 'dueOn',
        header: 'Due',
        sortKey: 'due_on',
        renderCell: row =>
          row.dueOn && row.status !== 'completed' && row.dueOn < today ? <Toned tone="error">{`${date(row.dueOn)} · overdue`}</Toned> : <Text hasTabularNumbers>{date(row.dueOn)}</Text>,
      },
    ];
    if (query.scope === 'all') {
      cols.push(
        {key: 'studentName', header: 'Student', renderCell: row => <Text>{row.studentName ?? '—'}</Text>},
        {key: 'assigneeName', header: 'Assigned to', renderCell: row => <Text>{row.assignedToMe ? 'Me' : row.assigneeName ?? 'Unassigned'}</Text>},
      );
    }
    cols.push(
      {
        key: 'items',
        header: 'Checklist',
        renderCell: row =>
          row.items.length === 0 ? (
            canAddItem(row) ? (
              <Button label="Add checklist" variant="ghost" size="sm" onClick={() => openChecklist(row.id)} />
            ) : (
              <Text color="secondary">—</Text>
            )
          ) : (
            <Button
              label={row.remainingItems ? `${number(row.remainingItems)} of ${number(row.items.length)} left` : `All ${number(row.items.length)} done`}
              variant="ghost"
              size="sm"
              onClick={() => openChecklist(row.id)}
            />
          ),
      },
      {key: 'status', header: 'Status', sortKey: 'status', renderCell: row => <Status value={row.status} />},
      {
        key: 'id',
        header: '',
        align: 'end',
        renderCell: row => {
          if (!canCompleteTask(row)) return <Text> </Text>;
          // A task with open checklist items is finished through its checklist first.
          if (row.remainingItems) return <Button label="Open checklist" variant="secondary" size="sm" onClick={() => openChecklist(row.id)} />;
          return <Button label="Complete" variant="secondary" size="sm" isLoading={busy === `task-${row.id}`} onClick={() => completeTask(row)} />;
        },
      },
    );
    return cols;
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [can, query.scope, busy, today, canAddItems]);

  const hasFilters = Boolean(query.q || query.status !== 'open');
  const clear = () => {
    setSearchText('');
    setQuery({q: '', status: 'open'});
  };

  const fields: Field[] = [
    {kind: 'text', name: 'title', label: 'Task', required: true},
    {kind: 'textarea', name: 'notes', label: 'Notes'},
    {kind: 'date', name: 'due_on', label: 'Due on', min: today || undefined},
    {kind: 'select', name: 'assigned_to', label: 'Assign to', options: (members.data ?? []).map(m => ({value: String(m.id), label: m.label.replace(/ · Unit staff \(#\d+\)$/, '')}))},
  ];

  if (list.error && !list.data) {
    return (
      <Page title="My tasks">
        <PageError error={list.error} onRetry={list.reload} />
      </Page>
    );
  }

  const exportHref = query.scope === 'all' && unit?.exports.includes('tasks') ? `/erp/units/${unitId}/exports/tasks` : undefined;
  const counts = list.data?.counts;

  return (
    <Page
      title={query.scope === 'all' ? 'Team tasks' : 'My tasks'}
      description={query.scope === 'all' ? 'Every task in this team' : 'Tasks assigned to you in this team'}
      actions={
        <>
          {/* Your own tasks in every team, including teams where your role has no screens. */}
          <Button label="All my teams" variant="ghost" href="/app/my-tasks" />
          {can?.create && <Button label="New task" variant="primary" icon={<Icon icon={PlusIcon} size="sm" />} onClick={() => setCreating(true)} />}
        </>
      }>
      {can?.seeAll && (
        <SegmentedControl label="Whose tasks" value={query.scope} onChange={value => setQuery({scope: value})}>
          <SegmentedControlItem value="mine" label={counts ? `Mine · ${number(counts.mineOpen)} open` : 'Mine'} />
          <SegmentedControlItem value="all" label={counts?.allOpen !== null && counts ? `Whole team · ${number(counts.allOpen)} open` : 'Whole team'} />
        </SegmentedControl>
      )}
      {actionError && <Banner status="error" title={actionError} isDismissable onDismiss={() => setActionError(undefined)} />}
      <DataTable<TaskRow>
        label="Tasks"
        rows={list.data?.data}
        meta={list.data?.meta}
        columns={columns}
        idKey="id"
        isLoading={list.isLoading}
        isRefreshing={list.isRefreshing}
        search={searchText}
        onSearch={setSearchText}
        searchPlaceholder="Search tasks and notes"
        filters={
          <Selector
            label="Status"
            isLabelHidden
            value={query.status}
            onChange={value => setQuery({status: value ?? 'open'})}
            options={[
              {value: 'open', label: 'Open'},
              {value: 'completed', label: 'Completed'},
              {value: 'all', label: 'All statuses'},
            ]}
          />
        }
        onSort={(sort, dir) => setQuery({sort, dir}, {resetPage: true})}
        onPage={page => setQuery({page: String(page)}, {resetPage: false})}
        onPageSize={size => setQuery({per_page: String(size)})}
        exportHref={exportHref}
        hasActiveFilters={hasFilters}
        onClearFilters={clear}
        emptyTitle={query.scope === 'all' ? 'No open tasks' : 'Nothing assigned to you'}
        emptyDescription={query.scope === 'all' ? 'New tasks appear here as the team adds them.' : 'Tasks assigned to you in this team appear here.'}
        emptyAction={can?.create ? <Button label="New task" variant="secondary" onClick={() => setCreating(true)} /> : undefined}
        phoneCard={{
          title: row => row.title,
          subtitle: row => [row.dueOn ? `Due ${date(row.dueOn)}` : 'No due date', row.remainingItems ? `${row.remainingItems} checklist left` : null, query.scope === 'all' ? row.assigneeName : null].filter(Boolean).join(' · '),
          trailing: row =>
            canCompleteTask(row) || canAddItem(row) ? (
              row.remainingItems || canAddItem(row) ? (
                <Button label="Checklist" variant="secondary" size="sm" onClick={() => openChecklist(row.id)} />
              ) : (
                <Button label="Complete" variant="secondary" size="sm" isLoading={busy === `task-${row.id}`} onClick={() => completeTask(row)} />
              )
            ) : (
              <Status value={row.status} />
            ),
        }}
      />

      <Dialog isOpen={checklistTask !== undefined} onOpenChange={open => !open && openChecklist(undefined)} width={480} padding={5}>
        {checklistTask && (
          <VStack gap={4}>
            <DialogHeader title={checklistTask.title} subtitle="Complete every checklist item before closing the task." onOpenChange={open => !open && openChecklist(undefined)} />
            {checklistTask.items.length === 0 && <Text color="secondary">No checklist items yet.</Text>}
            <List hasDividers edge="flush">
              {checklistTask.items.map(item => (
                <ListItem
                  key={item.id}
                  label={item.title}
                  description={item.completedAt ? `Done ${dateTime(item.completedAt)}` : 'Not done yet'}
                  endContent={
                    item.completedAt ? (
                      <Status value="done" />
                    ) : canCompleteItem(checklistTask) ? (
                      <Button label="Mark done" variant="secondary" size="sm" isLoading={busy === `item-${item.id}`} onClick={() => completeItem(checklistTask, item)} />
                    ) : (
                      <Status value="open" label="Open" />
                    )
                  }
                />
              ))}
            </List>
            {canAddItem(checklistTask) && (
              <HStack gap={2} vAlign="end">
                <TextInput
                  label="New checklist item"
                  value={newItem}
                  onChange={value => {
                    setNewItem(value);
                    setItemError(undefined);
                  }}
                  onEnter={() => addItem(checklistTask)}
                  placeholder="e.g. Share the demo class link"
                  status={itemError ? {type: 'error', message: itemError} : undefined}
                  containerStyle={{flex: 1, minWidth: 0}}
                />
                <Button label="Add" variant="secondary" isLoading={addingItem} onClick={() => addItem(checklistTask)} />
              </HStack>
            )}
            <HStack gap={2} hAlign="end">
              <Button label="Close" variant="ghost" onClick={() => openChecklist(undefined)} />
              {canCompleteTask(checklistTask) && (
                <Button
                  label="Complete task"
                  variant="primary"
                  isDisabled={checklistTask.remainingItems > 0}
                  isLoading={busy === `task-${checklistTask.id}`}
                  onClick={() => completeTask(checklistTask).then(() => openChecklist(undefined))}
                />
              )}
            </HStack>
          </VStack>
        )}
      </Dialog>

      {can?.create && unit && (
        <FormDrawer
          isOpen={creating}
          onOpenChange={setCreating}
          title="New task"
          description="Assign it to a team member. They see it under My tasks."
          fields={fields}
          initial={unit.role === 'superadmin' ? {} : {assigned_to: String(session().user.id)}}
          action={`/erp/units/${unitId}/task`}
          submitLabel="Add task"
          successMessage="Task added."
          onSaved={() => list.reload()}
        />
      )}
    </Page>
  );
}
