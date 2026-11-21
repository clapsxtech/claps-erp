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
import {Pagination} from '@astryxdesign/core/Pagination';
import {Page, PageError, PageLoading} from '../components/PageState';
import {Status} from '../components/Status';
import {Balance, Toned} from '../components/Amount';
import {Metric, MetricRow} from '../components/Metric';
import {FormDrawer, type Field} from '../components/FormDrawer';
import {useResource} from '../lib/hooks';
import {date, dateTime, money, number, titleCase} from '../lib/format';
import {unitById} from '../lib/session';
import {canAct} from './Classes/shared';

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
    /** Only http(s) links; anything else arrives as null. */
    meetingUrl: string | null;
    billingState: string | null;
  };
  sheetProfile: (Record<SheetKey, string | null> & {hourRatePaise: number | null}) | null;
  admissionHandoffs: {id: number; source: string | null; joinedOn: string | null; learningRequirement: string | null; concern: string | null; aboutStudent: string | null; aboutParent: string | null}[];
  balances: {currency: string; balancePaise: number}[];
  assignments: Row[];
  agreements: Row[];
  lessons: Row[];
  attendance: Row[];
  ledger: Row[];
  requests: RequestRow[];
  followups: Row[];
  tasks: Row[];
  pages: Record<SectionKey, {page: number; perPage: number; total: number; param: string}>;
  /** The state list, for roles allowed student-update. */
  states: string[] | null;
  can: {seeMoney: boolean; updateStudent: boolean};
}

type SectionKey = 'assignments' | 'agreements' | 'lessons' | 'attendance' | 'ledger' | 'requests' | 'followups' | 'tasks';

interface RequestRow extends Row {
  id: number;
  reference: string;
  requestedOn: string;
  dueOn: string | null;
  requestedPaise: number;
  coveredPaise: number;
  outstandingPaise: number;
  status: string;
}

const SHEET_LABELS = {
  section: 'Section',
  country: 'Country',
  whatsappNumber: 'WhatsApp',
  studentContact: 'Student contact',
  email: 'Email',
  fatherName: 'Father',
  motherName: 'Mother',
  school: 'School',
  preferredMedium: 'Preferred medium',
  preferredTime: 'Preferred time',
  networkConnectivity: 'Network',
  source: 'Source',
  sourceExternalId: 'Imported source id',
} as const;
type SheetKey = keyof typeof SHEET_LABELS;

const HANDOFF_LABELS = [
  ['learningRequirement', 'Learning needs'],
  ['concern', 'Parent concerns'],
  ['aboutStudent', 'About the student'],
  ['aboutParent', 'Parent communication preferences'],
] as const;

const WEEKDAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

function Section<T extends Row>({rows, columns, empty}: {rows: T[]; columns: TableColumn<T>[]; empty: string}) {
  if (rows.length === 0) return <EmptyState title={empty} isCompact />;
  return <Table<T> data={rows} columns={columns} idKey={row => Number(row.id)} hasHover textOverflow="truncate" />;
}

/** One pager per profile section; each section pages on its own (`<section>_page`). */
function Pager({meta, onPage, label}: {meta: Profile['pages'][SectionKey] | undefined; onPage: (page: number) => void; label: string}) {
  if (!meta || meta.total <= meta.perPage) return null;
  return (
    <HStack gap={2} hAlign="between" vAlign="center">
      <Text size="sm" color="secondary">{`${number(meta.total)} in total`}</Text>
      <Pagination page={meta.page} onChange={onPage} totalItems={meta.total} pageSize={meta.perPage} size="sm" label={label} />
    </HStack>
  );
}

const text = (value: unknown) => <Text>{value === null || value === undefined || value === '' ? '—' : String(value)}</Text>;

export default function StudentDetail() {
  const params = useParams();
  const unitId = Number(params.unit);
  const unit = unitById(unitId);
  const [pages, setPages] = useState<Partial<Record<string, number>>>({});
  const {data, error, isLoading, reload} = useResource<Profile>(`/app-api/units/${unitId}/students/${params.student}`, pages);
  const [tab, setTab] = useState('classes');
  const [editing, setEditing] = useState(false);
  const pageTo = (section: SectionKey) => (page: number) => setPages(current => ({...current, [`${section}_page`]: page}));

  if (isLoading) return <PageLoading />;
  if (error || !data) return <Page title="Student"><PageError error={error ?? new Error('Not found')} onRetry={reload} /></Page>;

  const {student} = data;
  const balance = data.balances.find(b => b.currency === 'INR')?.balancePaise ?? 0;
  const canCollect = Boolean(unit && !unit.isArchived && (unit.role === 'superadmin' || unit.actions.includes('payment')));
  const canRequest = Boolean(unit && !unit.isArchived && (unit.role === 'superadmin' || unit.actions.includes('request-create')));
  const canFollowUp = Boolean(unit && !unit.isArchived && (unit.role === 'superadmin' || unit.actions.includes('followup')));
  const canEdit = data.can.updateStudent && canAct(unit, 'student-update');
  const sheet = data.sheetProfile;
  const sheetItems = sheet ? (Object.keys(SHEET_LABELS) as SheetKey[]).filter(key => sheet[key]) : [];
  const editFields: Field[] = [
    {kind: 'text', name: 'name', label: 'Name', required: true},
    {kind: 'select', name: 'status', label: 'Status', options: ['active', 'paused', 'stopped'].map(value => ({value, label: titleCase(value)})), description: 'Cancel future classes before pausing or stopping.'},
    {kind: 'date', name: 'registered_on', label: 'Joined'},
    {kind: 'text', name: 'grade', label: 'Grade', required: true},
    {kind: 'text', name: 'syllabus', label: 'Syllabus', required: true},
    {kind: 'tel', name: 'guardian_contact', label: 'Parent contact', description: 'Phone or email.'},
    {kind: 'url', name: 'meeting_url', label: 'Class link', placeholder: 'https://'},
    {kind: 'date', name: 'date_of_birth', label: 'Date of birth (parent portal sign-in)', description: 'Leave blank to keep the saved date.'},
    {kind: 'select', name: 'billing_state', label: 'Billing state (GST place of supply)', description: "Only if it differs from the team's state.", options: (data.states ?? []).map(value => ({value, label: value}))},
  ];

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
          {canEdit && <Button label="Edit student" variant="secondary" onClick={() => setEditing(true)} />}
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
            <MetadataListItem label="Billing state">{student.billingState ?? "Team's state"}</MetadataListItem>
          </MetadataList>
          {sheet && (sheetItems.length > 0 || sheet.hourRatePaise !== null) && (
            <>
              <Heading level={3}>Registration profile</Heading>
              <MetadataList columns="multi" orientation="vertical" label={{position: 'top'}}>
                {sheetItems.map(key => (
                  <MetadataListItem key={key} label={SHEET_LABELS[key]}>{sheet[key] ?? '—'}</MetadataListItem>
                ))}
                {sheet.hourRatePaise !== null && <MetadataListItem label="Sheet hour rate">{money(sheet.hourRatePaise)}</MetadataListItem>}
              </MetadataList>
            </>
          )}
        </VStack>
      </Card>

      {data.admissionHandoffs.length > 0 && (
        <Card padding={4}>
          <VStack gap={3}>
            <Heading level={2}>Admission notes</Heading>
            {data.admissionHandoffs.map(handoff => (
              <VStack key={handoff.id} gap={2}>
                <Text size="sm" color="secondary">{`Enquiry #${handoff.id} · Source: ${handoff.source || 'Not recorded'} · Joined: ${handoff.joinedOn ? date(handoff.joinedOn) : 'Not recorded'}`}</Text>
                <MetadataList columns="multi" orientation="vertical" label={{position: 'top'}}>
                  {HANDOFF_LABELS.map(([key, label]) => (
                    <MetadataListItem key={key} label={label}>{handoff[key] || 'Not recorded'}</MetadataListItem>
                  ))}
                </MetadataList>
              </VStack>
            ))}
          </VStack>
        </Card>
      )}

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
                  // The student's hourly rate, for roles with the money page only.
                  ...(data.can.seeMoney ? [{key: 'studentRatePaise', header: 'Rate / hr', align: 'end' as const, renderCell: (r: Row) => <Text hasTabularNumbers>{money(r.studentRatePaise as number | null)}</Text>}] : []),
                  {key: 'status', header: 'Status', renderCell: r => <Status value={r.status as string} />},
                ]}
              />
              <Pager meta={data.pages.assignments} onPage={pageTo('assignments')} label="Assignment pages" />
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
              <Pager meta={data.pages.agreements} onPage={pageTo('agreements')} label="Weekly schedule pages" />
            </VStack>
          )}
          {tab === 'lessons' && (
            <VStack gap={3}>
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
              {data.attendance.length ? (
                <Pager meta={data.pages.attendance} onPage={pageTo('attendance')} label="Attendance pages" />
              ) : (
                <Pager meta={data.pages.lessons} onPage={pageTo('lessons')} label="Lesson pages" />
              )}
            </VStack>
          )}
          {tab === 'money' && (
            <VStack gap={4}>
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
                      ['payment', 'refund'].includes(String(r.kind)) ? <Link href={`/print/u/${unitId}/receipt/${r.id}`} target="_blank">Receipt</Link> : <Text> </Text>,
                  },
                ]}
              />
              <Pager meta={data.pages.ledger} onPage={pageTo('ledger')} label="Ledger pages" />
              <VStack gap={1}>
                <Heading level={3}>Payment requests</Heading>
                <Text size="sm" color="secondary">A request is not a receipt and does not change the balance.</Text>
              </VStack>
              <Section<RequestRow>
                rows={data.requests}
                empty="No payment requests"
                columns={[
                  {key: 'reference', header: 'Reference', renderCell: r => <Link href={`/print/u/${unitId}/request/${r.id}`} target="_blank">{r.reference}</Link>},
                  {key: 'requestedOn', header: 'Requested', renderCell: r => text(date(r.requestedOn))},
                  {key: 'dueOn', header: 'Due', renderCell: r => text(r.dueOn ? date(r.dueOn) : 'No due date')},
                  {key: 'requestedPaise', header: 'Amount', align: 'end', renderCell: r => <Toned>{money(r.requestedPaise)}</Toned>},
                  {key: 'outstandingPaise', header: 'Outstanding', align: 'end', renderCell: r => <Toned strong={r.outstandingPaise > 0}>{money(r.outstandingPaise)}</Toned>},
                  {key: 'status', header: 'Status', renderCell: r => <Status value={r.status} />},
                ]}
              />
              <Pager meta={data.pages.requests} onPage={pageTo('requests')} label="Payment request pages" />
            </VStack>
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
          {tab === 'followups' && <Pager meta={data.pages.followups} onPage={pageTo('followups')} label="Follow-up pages" />}
          {tab === 'tasks' && (
            <VStack gap={3}>
              <Section
                rows={data.tasks}
                empty="No tasks for this student"
                columns={[
                  {key: 'title', header: 'Task', renderCell: r => <VStack gap={0}>{text(r.title)}{r.notes ? <Text size="sm" color="secondary">{String(r.notes)}</Text> : null}</VStack>},
                  {key: 'assignedTo', header: 'Assigned to', renderCell: r => text(r.assignedTo ?? 'Unassigned')},
                  {key: 'due_on', header: 'Due', renderCell: r => text(r.due_on ? date(r.due_on as string) : '—')},
                  {key: 'status', header: 'Status', renderCell: r => <Status value={r.status as string} />},
                ]}
              />
              <Pager meta={data.pages.tasks} onPage={pageTo('tasks')} label="Task pages" />
            </VStack>
          )}
        </VStack>
      </Card>
      <HStack>
        <Toned>{' '}</Toned>
      </HStack>
      {canEdit && (
        <FormDrawer
          isOpen={editing}
          onOpenChange={setEditing}
          title="Edit student"
          description="Only changed fields are saved; blank fields keep their saved values. Changes are audited."
          sendChangedOnly
          clearable={['guardian_contact', 'meeting_url']}
          fields={editFields}
          initial={{
            name: student.name,
            status: student.status,
            registered_on: student.registeredOn,
            grade: student.grade,
            syllabus: student.syllabus,
            guardian_contact: student.guardianContact ?? '',
            meeting_url: student.meetingUrl ?? '',
            billing_state: student.billingState ?? '',
          }}
          hidden={{student_id: student.id}}
          action={`/erp/units/${unitId}/business-actions/student-update`}
          submitLabel="Save student"
          successMessage="Student updated."
          onSaved={reload}
        />
      )}
    </Page>
  );
}
