import {useState} from 'react';
import {useParams} from 'react-router';
import {VStack, HStack} from '@astryxdesign/core/Layout';
import {Card} from '@astryxdesign/core/Card';
import {Heading, Text} from '@astryxdesign/core/Text';
import {Link} from '@astryxdesign/core/Link';
import {Button} from '@astryxdesign/core/Button';
import {MetadataList, MetadataListItem} from '@astryxdesign/core/MetadataList';
import {TabList, Tab} from '@astryxdesign/core/TabList';
import {Table, type TableColumn} from '@astryxdesign/core/Table';
import {EmptyState} from '@astryxdesign/core/EmptyState';
import {Page, PageError, PageLoading} from '../components/PageState';
import {Status} from '../components/Status';
import {Balance, Toned} from '../components/Amount';
import {Metric, MetricRow} from '../components/Metric';
import {useResource} from '../lib/hooks';
import {date, dateTime, money, number, titleCase} from '../lib/format';
import {unitById} from '../lib/session';

type Row = Record<string, string | number | null>;

interface Profile {
  student: {
    id: number;
    code: string;
    name: string;
    grade: string;
    syllabus: string;
    status: string;
    registeredOn: string;
    timezone: string;
    guardianContact: string | null;
    meetingUrl: string | null;
  };
  balances: {currency: string; balancePaise: number}[];
  assignments: Row[];
  agreements: Row[];
  lessons: Row[];
  attendance: Row[];
  ledger: Row[];
  followups: Row[];
  tasks: Row[];
}

const WEEKDAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

function Section<T extends Row>({rows, columns, empty}: {rows: T[]; columns: TableColumn<T>[]; empty: string}) {
  if (rows.length === 0) return <EmptyState title={empty} isCompact />;
  return <Table<T> data={rows} columns={columns} idKey={row => Number(row.id)} hasHover textOverflow="truncate" />;
}

const text = (value: unknown) => <Text>{value === null || value === undefined || value === '' ? '—' : String(value)}</Text>;

export default function StudentDetail() {
  const params = useParams();
  const unitId = Number(params.unit);
  const unit = unitById(unitId);
  const {data, error, isLoading, reload} = useResource<Profile>(`/app-api/units/${unitId}/students/${params.student}`);
  const [tab, setTab] = useState('classes');

  if (isLoading) return <PageLoading />;
  if (error || !data) return <Page title="Student"><PageError error={error ?? new Error('Not found')} onRetry={reload} /></Page>;

  const {student} = data;
  const balance = data.balances.find(b => b.currency === 'INR')?.balancePaise ?? 0;
  const canCollect = Boolean(unit && !unit.isArchived && (unit.role === 'superadmin' || unit.actions.includes('payment')));
  const canRequest = Boolean(unit && !unit.isArchived && (unit.role === 'superadmin' || unit.actions.includes('request-create')));
  const canFollowUp = Boolean(unit && !unit.isArchived && (unit.role === 'superadmin' || unit.actions.includes('followup')));

  return (
    <Page
      title={student.name}
      description={`${student.code} · Grade ${student.grade} · ${student.syllabus}`}
      crumbs={[{label: 'Students', href: `/app/u/${unitId}/students`}, {label: student.name}]}
      actions={
        <>
          {canCollect && <Button label="Record payment" variant="primary" href={`/app/u/${unitId}/payments?new=payment&student=${student.id}`} />}
          {canRequest && <Button label="Request payment" variant="secondary" href={`/app/u/${unitId}/payments?new=request&student=${student.id}`} />}
          {canFollowUp && <Button label="Log follow-up" variant="secondary" href={`/app/u/${unitId}/followups?new=followup&student=${student.id}`} />}
        </>
      }>
      <MetricRow>
        <Metric label="Balance" value={money(balance)} tone={balance < 0 ? 'error' : undefined} hint={balance < 0 ? 'Owes the school' : 'Nothing due'} />
        <Metric label="Active classes" value={number(data.assignments.filter(a => a.status === 'active').length)} />
        <Metric label="Recent lessons" value={number(data.lessons.length)} />
        <Metric label="Open tasks" value={number(data.tasks.filter(t => t.status !== 'completed').length)} />
      </MetricRow>

      <Card padding={4}>
        <VStack gap={3}>
          <Heading level={2}>Details</Heading>
          <MetadataList columns="multi" orientation="vertical" label={{position: 'top'}}>
            <MetadataListItem label="Status"><Status value={student.status} /></MetadataListItem>
            <MetadataListItem label="Joined">{date(student.registeredOn)}</MetadataListItem>
            <MetadataListItem label="Time zone">{student.timezone}</MetadataListItem>
            <MetadataListItem label="Parent contact">
              {student.guardianContact ? <Link href={student.guardianContact.includes('@') ? `mailto:${student.guardianContact}` : `tel:${student.guardianContact}`}>{student.guardianContact}</Link> : '—'}
            </MetadataListItem>
            <MetadataListItem label="Class link">
              {student.meetingUrl ? <Link href={student.meetingUrl} target="_blank">Open meeting</Link> : '—'}
            </MetadataListItem>
          </MetadataList>
        </VStack>
      </Card>

      <Card padding={4}>
        <VStack gap={3}>
          <TabList value={tab} onChange={setTab}>
            <Tab value="classes" label="Classes" />
            <Tab value="lessons" label="Lessons" />
            <Tab value="money" label="Payments" />
            <Tab value="followups" label="Follow-ups" />
            <Tab value="tasks" label="Tasks" />
          </TabList>
          {tab === 'classes' && (
            <VStack gap={4}>
              <Section
                rows={data.assignments}
                empty="No teacher assigned yet"
                columns={[
                  {key: 'subject_name', header: 'Subject', renderCell: r => text(r.subject_name)},
                  {key: 'teacher_name', header: 'Teacher', renderCell: r => text(r.teacher_name)},
                  {key: 'starts_on', header: 'From', renderCell: r => text(date(r.starts_on as string))},
                  {key: 'ends_on', header: 'Until', renderCell: r => text(r.ends_on ? date(r.ends_on as string) : 'Ongoing')},
                  {key: 'status', header: 'Status', renderCell: r => <Status value={r.status as string} />},
                ]}
              />
              <Heading level={3}>Weekly schedule</Heading>
              <Section
                rows={data.agreements}
                empty="No weekly slot agreed yet"
                columns={[
                  {key: 'weekday', header: 'Day', renderCell: r => text(WEEKDAYS[Number(r.weekday) % 7])},
                  {key: 'start_time', header: 'Time', renderCell: r => text(`${r.start_time} · ${r.duration_minutes} min`)},
                  {key: 'subject_name', header: 'Subject', renderCell: r => text(r.subject_name)},
                  {key: 'teacher_name', header: 'Teacher', renderCell: r => text(r.teacher_name)},
                  {key: 'status', header: 'Status', renderCell: r => <Status value={r.status as string} />},
                ]}
              />
            </VStack>
          )}
          {tab === 'lessons' && (
            <Section
              rows={data.attendance.length ? data.attendance : data.lessons}
              empty="No lessons yet"
              columns={[
                {key: 'starts_at', header: 'When', renderCell: r => text(dateTime(r.starts_at as string))},
                {key: 'subject_name', header: 'Subject', renderCell: r => text(r.subject_name)},
                {key: 'teacher_name', header: 'Teacher', renderCell: r => text(r.teacher_name)},
                {key: 'actual_minutes', header: 'Minutes', align: 'end', renderCell: r => <Text hasTabularNumbers>{r.actual_minutes ?? '—'}</Text>},
                {key: 'status', header: 'Status', renderCell: r => <Status value={r.status as string} />},
              ]}
            />
          )}
          {tab === 'money' && (
            <Section
              rows={data.ledger}
              empty="No charges or payments yet"
              columns={[
                {key: 'occurred_at', header: 'Date', renderCell: r => text(date(String(r.occurred_at).slice(0, 10)))},
                {key: 'kind', header: 'Type', renderCell: r => text(titleCase(r.kind as string))},
                {key: 'reference', header: 'Reference', renderCell: r => <Text color="secondary">{String(r.reference)}</Text>},
                {key: 'amount_paise', header: 'Amount', align: 'end', renderCell: r => <Balance paise={Number(r.amount_paise)} />},
                {
                  key: 'id',
                  header: '',
                  align: 'end',
                  renderCell: r =>
                    ['payment', 'refund'].includes(String(r.kind)) ? <Link href={`/erp/units/${unitId}/receipts/${r.id}`} target="_blank">Receipt</Link> : <Text> </Text>,
                },
              ]}
            />
          )}
          {tab === 'followups' && (
            <Section
              rows={data.followups}
              empty="No follow-ups logged"
              columns={[
                {key: 'created_at', header: 'When', renderCell: r => text(dateTime(r.created_at as string))},
                {key: 'contact_channel', header: 'Channel', renderCell: r => text(titleCase(r.contact_channel as string))},
                {key: 'note', header: 'Note', renderCell: r => text(r.note)},
                {key: 'outcome', header: 'Outcome', renderCell: r => text(r.outcome)},
                {key: 'next_due_on', header: 'Next', renderCell: r => text(r.next_due_on ? date(r.next_due_on as string) : '—')},
              ]}
            />
          )}
          {tab === 'tasks' && (
            <Section
              rows={data.tasks}
              empty="No tasks for this student"
              columns={[
                {key: 'title', header: 'Task', renderCell: r => text(r.title)},
                {key: 'due_on', header: 'Due', renderCell: r => text(r.due_on ? date(r.due_on as string) : '—')},
                {key: 'status', header: 'Status', renderCell: r => <Status value={r.status as string} />},
              ]}
            />
          )}
        </VStack>
      </Card>
      <HStack>
        <Toned>{' '}</Toned>
      </HStack>
    </Page>
  );
}
