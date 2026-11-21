// Role-aware start screen: every tile is a count the role may see, and tapping it opens
// the filtered list it summarises, so daily work is one tap away.
import {useParams} from 'react-router';
import {VStack, HStack} from '@astryxdesign/core/Layout';
import {Card} from '@astryxdesign/core/Card';
import {Heading, Text} from '@astryxdesign/core/Text';
import {Link} from '@astryxdesign/core/Link';
import {Button} from '@astryxdesign/core/Button';
import {Icon} from '@astryxdesign/core/Icon';
import {List, ListItem} from '@astryxdesign/core/List';
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
} from '@heroicons/react/24/outline';
import type {IconComponent} from '../../nav';
import {Page, PageError, PageLoading} from '../../components/PageState';
import {Metric, MetricRow} from '../../components/Metric';
import {Status} from '../../components/Status';
import {useResource} from '../../lib/hooks';
import {date, money, number, titleCase} from '../../lib/format';
import {unitById, type UnitAccess} from '../../lib/session';
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
    tiles.push(<Metric key="missing" label="Ended classes with no attendance" value={number(queues.missingSubmission)} tone={queues.missingSubmission ? 'warning' : undefined} href={`${base}/attendance?queue=missing`} />);
  }
  if (metrics.pendingApproval !== null) {
    tiles.push(<Metric
        key="approval"
        label="Pending teacher approval"
        value={number(metrics.pendingApproval)}
        hint={queues?.teacherApproval ? `${number(queues.teacherApproval)} in the approval queue` : undefined}
        href={`${base}/attendance?queue=approval`}
      />);
  }
  if (queues?.studentImport !== null && queues?.studentImport !== undefined) {
    tiles.push(<Metric key="import" label="Classes to charge" hint="Awaiting import" value={number(queues.studentImport)} href={`${base}/attendance?queue=import`} />);
  }
  if (metrics.duplicateReviews !== null) {
    tiles.push(<Metric key="dupes" label="Duplicate reviews" value={number(metrics.duplicateReviews)} tone={metrics.duplicateReviews ? 'warning' : undefined} href={`${base}/attendance?queue=duplicates`} />);
  }
  if (dues) {
    // Dues open the list they summarise: Students for people roles, Payments for money-only roles; sales read the figure only.
    const duesHref = hasPage(unit, 'people') ? `${base}/students?due=1` : hasPage(unit, 'money') ? `${base}/payments` : undefined;
    tiles.push(<Metric key="dues" label="Families with dues" value={number(dues.count)} href={duesHref} />);
    tiles.push(<Metric key="owed" label="Total outstanding" value={money(-dues.paise, {whole: true})} tone={dues.paise < 0 ? 'error' : undefined} href={duesHref} />);
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

  return (
    <Page title={unit.name} description={`${unit.roleLabel} · ${date(data.today)}`}>
      {actions.length > 0 && (
        <VStack gap={2}>
          <Heading level={2}>Shortcuts</Heading>
          <HStack gap={2} wrap="wrap">
            {actions.map((action, i) => (
              <Button key={action.href} label={action.label} href={action.href} variant={i === 0 ? 'primary' : 'secondary'} icon={<Icon icon={action.icon} size="sm" />} />
            ))}
          </HStack>
        </VStack>
      )}

      <MetricRow>{tiles}</MetricRow>

      <Grid columns={{minWidth: 320, repeat: 'fit'}} gap={4}>
        {data.todayLessons && (
          <Card padding={4}>
            <VStack gap={3}>
              <HStack gap={2} vAlign="center" hAlign="between">
                <Heading level={2}>Today's lessons</Heading>
                <Link href={`${base}/timetable?date=${data.today}`}>Open timetable</Link>
              </HStack>
              {data.todayLessons.rows.length === 0 ? (
                <EmptyState title="No lessons today" isCompact />
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
              <EmptyState title="Nothing assigned to you" description="Tasks assigned to you in this team appear here." isCompact />
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
