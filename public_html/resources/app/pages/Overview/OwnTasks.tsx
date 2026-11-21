// The signed-in user's own tasks across every team (GET /app-api/me/tasks), for
// the teacher portal and for staff whose only role is a legacy one with no team
// screens. Completion posts to the own-task routes (/erp/my-tasks/...), which
// check ownership, the checklist and the membership again.
import {useEffect, useState} from 'react';
import {VStack, HStack} from '@astryxdesign/core/Layout';
import {Text} from '@astryxdesign/core/Text';
import {Button} from '@astryxdesign/core/Button';
import {Banner} from '@astryxdesign/core/Banner';
import {EmptyState} from '@astryxdesign/core/EmptyState';
import {Dialog, DialogHeader} from '@astryxdesign/core/Dialog';
import {List, ListItem} from '@astryxdesign/core/List';
import {useToast} from '@astryxdesign/core/Toast';
import {PageError, PageLoading} from '../../components/PageState';
import {Status} from '../../components/Status';
import {useResource} from '../../lib/hooks';
import {ApiError} from '../../lib/api';
import {date, dateTime, number} from '../../lib/format';
import {postOwnTask} from './shared';

export interface OwnTaskItem {
  id: number;
  title: string;
  completedAt: string | null;
}

export interface OwnTask {
  id: number;
  unitId: number;
  unitName: string;
  title: string;
  notes: string | null;
  dueOn: string | null;
  status: string;
  remainingItems: number;
  canComplete: boolean;
  items: OwnTaskItem[];
}

interface OwnTaskList {
  data: OwnTask[];
  meta: {page: number; perPage: number; total: number; status: 'open' | 'all'};
}

export function OwnTasks({status = 'all', showTeam = true, emptyDescription}: {status?: 'open' | 'all'; showTeam?: boolean; emptyDescription?: string}) {
  const showToast = useToast();
  const [paging, setPaging] = useState({status, page: 1});
  // A new status filter starts again at page 1.
  const page = paging.status === status ? paging.page : 1;
  const setPage = (next: number) => setPaging({status, page: next});
  const list = useResource<OwnTaskList>('/app-api/me/tasks', {status, page});
  const [busy, setBusy] = useState<string>();
  const [actionError, setActionError] = useState<string>();
  const [checklistFor, setChecklistFor] = useState<number>();
  const checklistTask = list.data?.data.find(task => task.id === checklistFor);
  // Completing the last task on a later page empties it: go back to the last page that has tasks.
  const lastPage = list.data ? Math.max(1, Math.ceil(list.data.meta.total / list.data.meta.perPage)) : 1;
  useEffect(() => {
    if (list.data && list.data.meta.page > lastPage) setPaging({status, page: lastPage});
  }, [list.data, lastPage, status]);

  // 403: no current team membership at all.
  if (list.error && !list.data && list.error instanceof ApiError && list.error.status === 403) {
    return <EmptyState isCompact title="No team assigned" description="Ask an administrator to add you to a team." />;
  }
  if (list.error && !list.data) return <PageError error={list.error} onRetry={list.reload} />;
  if (!list.data) return <PageLoading />;

  async function run(key: string, url: string, message: string) {
    setBusy(key);
    setActionError(undefined);
    try {
      await postOwnTask(url);
      showToast({body: message, uniqueID: `own-task-${key}`});
    } catch (reason) {
      setActionError(reason instanceof ApiError ? reason.message : 'Could not reach the server. Nothing was changed.');
    } finally {
      setBusy(undefined);
      list.reload();
    }
  }
  const completeTask = (task: OwnTask) => run(`task-${task.id}`, `/erp/my-tasks/${task.id}/complete`, 'Task completed.');
  const completeItem = (task: OwnTask, item: OwnTaskItem) => run(`item-${item.id}`, `/erp/my-tasks/${task.id}/items/${item.id}/complete`, 'Checklist item completed.');
  const isOpen = (task: OwnTask) => task.status !== 'completed';
  const {meta} = list.data;
  const pages = Math.max(1, Math.ceil(meta.total / meta.perPage));

  return (
    <VStack gap={3}>
      {actionError && <Banner status="error" title={actionError} isDismissable onDismiss={() => setActionError(undefined)} />}
      {list.data.data.length === 0 ? (
        <EmptyState isCompact title={status === 'open' ? 'No open tasks' : 'Nothing assigned to you'} description={emptyDescription ?? 'Tasks your team assigns to you appear here.'} />
      ) : (
        <List hasDividers edge="flush">
          {list.data.data.map(task => (
            <ListItem
              key={task.id}
              label={task.title}
              description={
                <VStack gap={0.5}>
                  {task.notes && <Text type="supporting">{task.notes}</Text>}
                  <Text color="secondary" size="sm">
                    {[
                      task.dueOn ? `Due ${date(task.dueOn)}` : 'No due date',
                      task.items.length ? (task.remainingItems ? `${number(task.remainingItems)} of ${number(task.items.length)} checklist left` : `Checklist done`) : null,
                      showTeam ? task.unitName : null,
                    ]
                      .filter(Boolean)
                      .join(' · ')}
                  </Text>
                </VStack>
              }
              endContent={
                <HStack gap={2} vAlign="center">
                  {!isOpen(task) || !task.canComplete ? (
                    <Status value={task.status} />
                  ) : task.items.length > 0 ? (
                    <Button label={task.remainingItems ? 'Open checklist' : 'Checklist'} variant="secondary" size="sm" onClick={() => setChecklistFor(task.id)} />
                  ) : null}
                  {isOpen(task) && task.canComplete && task.remainingItems === 0 && (
                    <Button label="Complete" variant="secondary" size="sm" isLoading={busy === `task-${task.id}`} onClick={() => completeTask(task)} />
                  )}
                </HStack>
              }
            />
          ))}
        </List>
      )}
      {pages > 1 && (
        <HStack gap={2} vAlign="center" hAlign="end">
          <Text color="secondary" size="sm">{`Page ${number(meta.page)} of ${number(pages)} · ${number(meta.total)} tasks`}</Text>
          <Button label="Previous" variant="ghost" size="sm" isDisabled={meta.page <= 1} onClick={() => setPage(meta.page - 1)} />
          <Button label="Next" variant="ghost" size="sm" isDisabled={meta.page >= pages} onClick={() => setPage(meta.page + 1)} />
        </HStack>
      )}

      <Dialog isOpen={checklistTask !== undefined} onOpenChange={open => !open && setChecklistFor(undefined)} width={480} padding={5}>
        {checklistTask && (
          <VStack gap={4}>
            <DialogHeader title={checklistTask.title} subtitle="Complete every checklist item before closing the task." onOpenChange={open => !open && setChecklistFor(undefined)} />
            <List hasDividers edge="flush">
              {checklistTask.items.map(item => (
                <ListItem
                  key={item.id}
                  label={item.title}
                  description={item.completedAt ? `Done ${dateTime(item.completedAt)}` : 'Not done yet'}
                  endContent={
                    item.completedAt ? (
                      <Status value="done" />
                    ) : isOpen(checklistTask) && checklistTask.canComplete ? (
                      <Button label="Mark done" variant="secondary" size="sm" isLoading={busy === `item-${item.id}`} onClick={() => completeItem(checklistTask, item)} />
                    ) : (
                      <Status value="open" label="Open" />
                    )
                  }
                />
              ))}
            </List>
            <HStack gap={2} hAlign="end">
              <Button label="Close" variant="ghost" onClick={() => setChecklistFor(undefined)} />
              {isOpen(checklistTask) && checklistTask.canComplete && (
                <Button
                  label="Complete task"
                  variant="primary"
                  isDisabled={checklistTask.remainingItems > 0}
                  isLoading={busy === `task-${checklistTask.id}`}
                  onClick={() => completeTask(checklistTask).then(() => setChecklistFor(undefined))}
                />
              )}
            </HStack>
          </VStack>
        )}
      </Dialog>
    </VStack>
  );
}
