// Role-aware start screen. It greets the person, then leads with what needs them
// today in plain sentences, each opening the list it describes; quick actions and
// the figures the role may see follow, so daily work is one tap away.
import {useParams} from 'react-router';
import {VStack, HStack} from '@astryxdesign/core/Layout';
import {Card} from '@astryxdesign/core/Card';
import {Heading, Text} from '@astryxdesign/core/Text';
import {Link} from '@astryxdesign/core/Link';
import {Button} from '@astryxdesign/core/Button';
import {Icon} from '@astryxdesign/core/Icon';
import {List, ListItem} from '@astryxdesign/core/List';
import {Banner} from '@astryxdesign/core/Banner';
import {EmptyState} from '@astryxdesign/core/EmptyState';
import {Grid} from '@astryxdesign/core/Grid';
import {
  BanknotesIcon,
  CalendarDaysIcon,
  CheckCircleIcon,
  ClipboardDocumentListIcon,
  PhoneArrowUpRightIcon,
  UserPlusIcon,
  UsersIcon,
  ChartBarIcon,
  DocumentChartBarIcon,
  ExclamationTriangleIcon,
  DocumentDuplicateIcon,
  ReceiptPercentIcon,
} from '@heroicons/react/24/outline';
import type {IconComponent} from '../../nav';
import {Page, PageError, PageLoading} from '../../components/PageState';
import {Metric, MetricRow} from '../../components/Metric';
import {Status} from '../../components/Status';
import {useResource} from '../../lib/hooks';
import {date, money, number, titleCase} from '../../lib/format';
import {session, unitById, type UnitAccess} from '../../lib/session';
import {canAct, hasPage, timeIn} from './shared';

interface HomeData {
  today: string;
  timezone: string;
  metrics: {activeStudents: number | null; scheduledLessons: number | null; pendingApproval: number | null; duplicateReviews: number | null};
  dues: {count: number; paise: number} | null;
  todayLessons: {
    count: number;
    rows: {id: number; startsAt: string; studentName: string; teacherName: string; subjectName: string | null; kind: string; status: string}[];
  } | null;
  myTasks: {count: number; overdue: number; rows: {id: number; title: string; dueOn: string | null; status: string}[]};
  unitTasks: {count: number; overdue: number} | null;
  followupsDue: number | null;
  queues: {missingSubmission: number | null; studentImport: number | null; teacherApproval: number | null} | null;
}

interface Shortcut {
  label: string;
  href: string;
  icon: IconComponent;
}

/** The most useful actions for the role, in priority order; at most six. */
function shortcuts(unit: UnitAccess, base: string): Shortcut[] {
  const list: Shortcut[] = [];
  const add = (ok: boolean, label: string, path: string, icon: IconComponent) => {
    if (ok) list.push({label, href: `${base}/${path}`, icon});
  };
  add(canAct(unit, 'admission-create-lead') && hasPage(unit, 'admissions'), 'New enquiry', 'admissions?new=lead', UserPlusIcon);
  add(canAct(unit, 'payment') && hasPage(unit, 'money'), 'Record payment', 'payments?new=payment', BanknotesIcon);
  add(canAct(unit, 'approve') && hasPage(unit, 'attendance'), 'Review attendance', 'attendance', CheckCircleIcon);
  add(canAct(unit, 'student') && hasPage(unit, 'people'), 'Add student', 'students?new=student', UsersIcon);
  add(canAct(unit, 'followup') && hasPage(unit, 'followups'), 'Log follow-up', 'followups?new=followup', PhoneArrowUpRightIcon);
  add(canAct(unit, 'request-create') && hasPage(unit, 'money'), 'Request payment', 'payments?new=request', BanknotesIcon);
  add(canAct(unit, 'reschedule') && hasPage(unit, 'timetable'), 'Open timetable', 'timetable', CalendarDaysIcon);
  add(canAct(unit, 'task'), 'New task', 'tasks?new=task', ClipboardDocumentListIcon);
  add(hasPage(unit, 'admissions'), 'Sales report', 'sales-report', DocumentChartBarIcon);
  // Roles with few actions (read-only has none) get the places they work in, up to four.
  const places: [boolean, string, string, IconComponent][] = [
    [hasPage(unit, 'people'), 'Students', 'students', UsersIcon],
    [hasPage(unit, 'timetable'), 'Timetable', 'timetable', CalendarDaysIcon],
    [hasPage(unit, 'money'), 'Payments & dues', 'payments', BanknotesIcon],
    [hasPage(unit, 'accounts'), 'Accounts & tax', 'accounts', DocumentChartBarIcon],
    [hasPage(unit, 'insights'), 'Trends', 'insights', ChartBarIcon],
    [true, 'My tasks', 'tasks', ClipboardDocumentListIcon],
  ];
  for (const [ok, label, path, icon] of places) {
    if (list.length >= 4) break;
    if (!list.some(item => item.href.startsWith(`${base}/${path}`))) add(ok, label, path, icon);
  }
  return list.slice(0, 6);
}

/** "Good morning" etc. for the hour in the team's time zone. */
function greeting(timezone: string): string {
  let hour = new Date().getHours();
  try {
    hour = Number(new Intl.DateTimeFormat('en-GB', {hour: 'numeric', hourCycle: 'h23', timeZone: timezone}).format(new Date()));
  } catch {
    // Unknown zone: use the device clock.
  }
  return hour < 12 ? 'Good morning' : hour < 17 ? 'Good afternoon' : 'Good evening';
}

const plural = (count: number, one: string, many: string) => `${number(count)} ${count === 1 ? one : many}`;

interface Todo {
  key: string;
  label: string;
  description: string;
  href?: string;
  icon: IconComponent;
}

export default function Home() {
  const unitId = Number(useParams().unit);
  const unit = unitById(unitId);
  const {data, error, isLoading, reload} = useResource<HomeData>(`/app-api/units/${unitId}/overview/home`);
  const base = `/app/u/${unitId}`;

  if (isLoading) return <PageLoading />;
  if (error || !data || !unit) {
    return (
      <Page title={unit?.name ?? 'Home'}>
        <PageError error={error ?? new Error('This team is not available to you.')} onRetry={reload} />
      </Page>
    );
  }

  const {metrics, dues, queues} = data;
  const tiles = [];
  if (data.todayLessons) {
    tiles.push(<Metric key="today" label="Lessons today" value={number(data.todayLessons.count)} href={`${base}/timetable?date=${data.today}`} />);
  }
  tiles.push(
    <Metric
      key="mine"
      label="My open tasks"
      value={number(data.myTasks.count)}
      hint={data.myTasks.overdue ? `${number(data.myTasks.overdue)} overdue` : undefined}
      tone={data.myTasks.overdue ? 'warning' : undefined}
      href={`${base}/tasks`}
    />,
  );
  if (queues?.missingSubmission !== null && queues?.missingSubmission !== undefined) {
    tiles.push(<Metric key="missing" label="Classes missing attendance" value={number(queues.missingSubmission)} tone={queues.missingSubmission ? 'warning' : undefined} href={`${base}/attendance?queue=missing`} />);
  }
  if (metrics.pendingApproval !== null) {
    tiles.push(<Metric
        key="approval"
        label="Attendance to approve"
        value={number(metrics.pendingApproval)}
        hint={queues?.teacherApproval ? `${number(queues.teacherApproval)} ready now` : undefined}
        href={`${base}/attendance?queue=approval`}
      />);
  }
  if (queues?.studentImport !== null && queues?.studentImport !== undefined) {
    tiles.push(<Metric key="import" label="Classes to bill" hint="Not billed to families yet" value={number(queues.studentImport)} href={`${base}/attendance?queue=import`} />);
  }
  if (metrics.duplicateReviews !== null) {
    tiles.push(<Metric key="dupes" label="Possible duplicates" value={number(metrics.duplicateReviews)} tone={metrics.duplicateReviews ? 'warning' : undefined} href={`${base}/attendance?queue=duplicates`} />);
  }
  if (dues) {
    // Dues open the list they summarise: Students for people roles, Payments for money-only roles; sales read the figure only.
    const duesHref = hasPage(unit, 'people') ? `${base}/students?due=1` : hasPage(unit, 'money') ? `${base}/payments` : undefined;
    tiles.push(<Metric key="dues" label="Families with dues" value={number(dues.count)} href={duesHref} />);
    tiles.push(<Metric key="owed" label="Fees still owed" value={money(-dues.paise, {whole: true})} tone={dues.paise < 0 ? 'error' : undefined} href={duesHref} />);
  }
  if (data.followupsDue !== null) {
    tiles.push(<Metric key="followups" label="Follow-ups due" value={number(data.followupsDue)} tone={data.followupsDue ? 'warning' : undefined} href={`${base}/followups`} />);
  }
  if (data.unitTasks) {
    tiles.push(
      <Metric
        key="unitTasks"
        label="Open team tasks"
        value={number(data.unitTasks.count)}
        hint={data.unitTasks.overdue ? `${number(data.unitTasks.overdue)} overdue` : undefined}
        href={`${base}/tasks?scope=all`}
      />,
    );
  }
  if (metrics.activeStudents !== null) {
    tiles.push(<Metric key="active" label="Active students" value={number(metrics.activeStudents)} href={`${base}/students?status=active`} />);
  }
  if (metrics.scheduledLessons !== null) {
    tiles.push(<Metric key="scheduled" label="Scheduled lessons" value={number(metrics.scheduledLessons)} href={`${base}/timetable`} />);
  }

  const actions = shortcuts(unit, base);

  // What needs this person today, most urgent first. Only counts above zero.
  const todos: Todo[] = [];
  const todo = (count: number | null | undefined, item: Omit<Todo, 'label'> & {label: (n: number) => string}) => {
    if (count) todos.push({...item, label: item.label(count)});
  };
  todo(data.myTasks.overdue, {key: 'overdue', label: n => plural(n, 'of your tasks is overdue', 'of your tasks are overdue'), description: 'Finish them or move the due date.', href: `${base}/tasks`, icon: ExclamationTriangleIcon});
  todo(data.followupsDue, {key: 'calls', label: n => plural(n, 'family to call today', 'families to call today'), description: 'See who to call and what was said last time.', href: `${base}/followups`, icon: PhoneArrowUpRightIcon});
  todo(queues?.teacherApproval, {key: 'approve', label: n => plural(n, 'class is waiting for your approval', 'classes are waiting for your approval'), description: 'Teachers have sent their attendance. Check and approve it.', href: `${base}/attendance?queue=approval`, icon: CheckCircleIcon});
  todo(queues?.missingSubmission, {key: 'missing', label: n => plural(n, 'class has no attendance yet', 'classes have no attendance yet'), description: 'The class has ended but the teacher has not recorded it.', href: `${base}/attendance?queue=missing`, icon: CalendarDaysIcon});
  todo(metrics.duplicateReviews, {key: 'dupes', label: n => plural(n, 'class may be recorded twice', 'classes may be recorded twice'), description: 'Check with the teacher which record is right.', href: `${base}/attendance?queue=duplicates`, icon: DocumentDuplicateIcon});
  todo(queues?.studentImport, {key: 'bill', label: n => plural(n, 'class is ready to bill', 'classes are ready to bill'), description: 'Add them to the family\'s account.', href: `${base}/attendance?queue=import`, icon: ReceiptPercentIcon});
  todo(data.myTasks.count - data.myTasks.overdue, {key: 'tasks', label: n => plural(n, 'task assigned to you', 'tasks assigned to you'), description: 'Open your task list.', href: `${base}/tasks`, icon: ClipboardDocumentListIcon});

  const firstName = session().user.name.split(/\s+/)[0];

  return (
    <Page title={`${greeting(data.timezone)}, ${firstName}`} description={`${new Intl.DateTimeFormat('en-GB', {weekday: 'long', day: 'numeric', month: 'long', timeZone: data.timezone}).format(new Date())} · ${unit.name}`}>
      <Card padding={4}>
        <VStack gap={2}>
          <Heading level={2}>Needs you today</Heading>
          {todos.length === 0 ? (
            <Banner status="success" title="You're all caught up" description="Nothing is waiting for you. New work will show up here." collapsible={false} />
          ) : (
            <List hasDividers edge="flush">
              {todos.map(item => (
                <ListItem
                  key={item.key}
                  align="first-line"
                  startContent={<Icon icon={item.icon} size="md" color="accent" />}
                  label={<Text weight="semibold">{item.label}</Text>}
                  description={<Text type="supporting">{item.description}</Text>}
                  href={item.href}
                />
              ))}
            </List>
          )}
        </VStack>
      </Card>

      {actions.length > 0 && (
        <VStack gap={2}>
          <Heading level={2}>Quick actions</Heading>
          <HStack gap={2} wrap="wrap">
            {actions.map((action, i) => (
              <Button key={action.href} label={action.label} href={action.href} variant={i === 0 ? 'primary' : 'secondary'} icon={<Icon icon={action.icon} size="sm" />} />
            ))}
          </HStack>
        </VStack>
      )}

      <VStack gap={2}>
        <Heading level={2}>At a glance</Heading>
        <MetricRow>{tiles}</MetricRow>
      </VStack>

      <Grid columns={{minWidth: 320, repeat: 'fit'}} gap={4}>
        {data.todayLessons && (
          <Card padding={4}>
            <VStack gap={3}>
              <HStack gap={2} vAlign="center" hAlign="between">
                <Heading level={2}>Today's lessons</Heading>
                <Link href={`${base}/timetable?date=${data.today}`}>Open timetable</Link>
              </HStack>
              {data.todayLessons.rows.length === 0 ? (
                <EmptyState title="No lessons today" description="Enjoy the quiet. Upcoming lessons are on the timetable." isCompact />
              ) : (
                <List hasDividers edge="flush">
                  {data.todayLessons.rows.map(lesson => (
                    <ListItem
                      key={lesson.id}
                      label={`${timeIn(lesson.startsAt, data.timezone)} · ${lesson.studentName}`}
                      description={`${lesson.subjectName ?? 'Class'} with ${lesson.teacherName}${lesson.kind === 'regular' ? '' : ` · ${titleCase(lesson.kind)}`}`}
                      endContent={<Status value={lesson.status} />}
                    />
                  ))}
                </List>
              )}
              {data.todayLessons.count > data.todayLessons.rows.length && (
                <Text color="secondary" size="sm">{`${number(data.todayLessons.count - data.todayLessons.rows.length)} more in the timetable`}</Text>
              )}
            </VStack>
          </Card>
        )}
        <Card padding={4}>
          <VStack gap={3}>
            <HStack gap={2} vAlign="center" hAlign="between">
              <Heading level={2}>My tasks</Heading>
              <Link href={`${base}/tasks`}>All my tasks</Link>
            </HStack>
            {data.myTasks.rows.length === 0 ? (
              <EmptyState
                title="Nothing assigned to you"
                description="When someone gives you a task, it shows up here."
                actions={canAct(unit, 'task') ? <Button label="Add a task" variant="secondary" href={`${base}/tasks?new=task`} /> : undefined}
                isCompact
              />
            ) : (
              <List hasDividers edge="flush">
                {data.myTasks.rows.map(task => (
                  <ListItem
                    key={task.id}
                    label={task.title}
                    description={task.dueOn ? `Due ${date(task.dueOn)}${task.dueOn < data.today ? ' · overdue' : ''}` : 'No due date'}
                    endContent={<Status value={task.dueOn && task.dueOn < data.today ? 'overdue' : task.status} label={task.dueOn && task.dueOn < data.today ? 'Overdue' : undefined} />}
                    href={`${base}/tasks`}
                  />
                ))}
              </List>
            )}
          </VStack>
        </Card>
      </Grid>
    </Page>
  );
}
