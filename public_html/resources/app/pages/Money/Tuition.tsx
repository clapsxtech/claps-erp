// Class follow-up and HR: the private tuition records (parent queries,
// monthly parent statements, parent review of attendance, internal notes and
// candidate interviews). Only administrators and team managers may open it; the
// server enforces an explicit role check (TuitionController).
import {useState} from 'react';
import {useParams, useSearchParams} from 'react-router';
import {VStack, HStack, StackItem} from '@astryxdesign/core/Layout';
import {Card} from '@astryxdesign/core/Card';
import {Heading, Text} from '@astryxdesign/core/Text';
import {Link} from '@astryxdesign/core/Link';
import {Button} from '@astryxdesign/core/Button';
import {Selector} from '@astryxdesign/core/Selector';
import {TabList, Tab} from '@astryxdesign/core/TabList';
import {Table, type TableColumn} from '@astryxdesign/core/Table';
import {EmptyState} from '@astryxdesign/core/EmptyState';
import {Pagination} from '@astryxdesign/core/Pagination';
import {List, ListItem} from '@astryxdesign/core/List';
import {Page, PageError, PageLoading} from '../../components/PageState';
import {FormDrawer} from '../../components/FormDrawer';
import {Status} from '../../components/Status';
import {Metric, MetricRow} from '../../components/Metric';
import {useResource} from '../../lib/hooks';
import {dateTime, number, titleCase} from '../../lib/format';
import {session, unitById} from '../../lib/session';
import {actionUrl, ConfirmActionDialog, monthOptions, today} from './shared';
import {RecordSharingDialog} from '../Print/RecordSharing';
import type {StatementData} from '../Print/Statement';

type Row = Record<string, unknown>;

interface ParentRequest {
  id: number;
  receivedAt: string;
  studentName: string;
  studentCode: string;
  note: string;
  attendanceId: number | null;
  lessonStartsAt: string | null;
  actualMinutes: number | null;
  attendanceStatus: string | null;
}

interface Pending {
  id: number;
  lessonId: number;
  studentName: string;
  actualMinutes: number;
  actualStartsAt: string;
  submittedLate: boolean;
  parentReviewStatus: string | null;
  parentDeadlineAt: string | null;
  changeReason: string | null;
}

interface Candidate {
  id: number;
  name: string;
  contact: string;
  qualification: string;
  subjects: string;
  levels: string;
  languages: string | null;
  availability: string | null;
  status: string;
  interviews: {id: number; score: number; decision: string; strengths: string | null; concerns: string | null; recordingUrl: string | null; createdAt: string}[];
}

interface TuitionData {
  timezone: string;
  parentRequests: ParentRequest[];
  students: {id: number; name: string; code: string}[];
  pending: Pending[];
  pendingMeta: {page: number; perPage: number; total: number};
  lessons: {id: number; startsAt: string; status: string; kind: string; studentName: string; teacherName: string; needsFollowUp: boolean}[];
  shortfalls: {lessonId: number; studentName: string; startsAt: string; plannedMinutes: number; actualMinutes: number}[];
  notes: {id: number; lessonId: number; kind: string; outcome: string | null; note: string | null; createdAt: string; actorName: string}[];
  candidates: Candidate[];
  can: {approve: boolean; write: boolean};
}

type TabKey = 'review' | 'notes' | 'hr';

/** Stored times are UTC ("Y-m-d H:i:s"); show them in the team's time zone. */
function utc(value: string | null | undefined): string {
  if (!value) return '—';
  return dateTime(new Date(`${value.replace(' ', 'T')}Z`).toISOString());
}

function Rows<T extends Row>({rows, columns, empty}: {rows: T[]; columns: TableColumn<T>[]; empty: string}) {
  if (rows.length === 0) return <EmptyState title={empty} isCompact />;
  return <Table<T> data={rows} columns={columns} idKey={row => String(row.id ?? row.lessonId)} hasHover textOverflow="truncate" density="balanced" />;
}

type Acting = {kind: 'resolve'; row: ParentRequest} | {kind: 'review' | 'approve'; row: Pending} | null;

export default function Tuition() {
  const unitId = Number(useParams().unit);
  const unit = unitById(unitId);
  const [params, setParams] = useSearchParams();
  const rawTab = params.get('tab');
  const tab: TabKey = rawTab === 'notes' || rawTab === 'hr' ? rawTab : 'review';
  const [page, setPage] = useState(1);
  const tuition = useResource<TuitionData>(`/app-api/units/${unitId}/tuition`, {page});
  const [acting, setActing] = useState<Acting>(null);
  const [creating, setCreating] = useState<'note' | 'candidate' | 'interview' | null>(null);
  const timezone = unit?.timezone ?? 'Asia/Kolkata';
  const [statementStudent, setStatementStudent] = useState<string>();
  const [statementMonth, setStatementMonth] = useState(today(timezone).slice(0, 7));
  const [sharing, setSharing] = useState(false);
  // The statement read supplies the submitted entries and their review snapshots.
  const statement = useResource<StatementData>(statementStudent ? `/app-api/units/${unitId}/print/statement` : null, {student_id: statementStudent, month: statementMonth});

  if (tuition.isLoading) return <PageLoading />;
  if (tuition.error || !tuition.data) {
    return (
      <Page title="Class follow-up & HR" crumbs={[{label: 'Payments & dues', href: `/app/u/${unitId}/payments`}, {label: 'Class follow-up & HR'}]}>
        <PageError error={tuition.error ?? new Error('Not found')} onRetry={tuition.reload} />
      </Page>
    );
  }
  const data = tuition.data;
  const canWrite = data.can.write && Boolean(unit && !unit.isArchived) && !session().sheetsAuthority;
  const tuitionUrl = (operation: string) => `/erp/units/${unitId}/tuition/${operation}`;
  const followUps = data.lessons.filter(lesson => lesson.needsFollowUp);

  return (
    <Page
      title="Class follow-up & HR"
      description={`Private to administrators and team managers. Times are shown in your browser's time zone; the team uses ${data.timezone}.`}
      crumbs={[{label: 'Payments & dues', href: `/app/u/${unitId}/payments`}, {label: 'Class follow-up & HR'}]}
      actions={
        canWrite && (
          <>
            <Button label="Add candidate" variant="secondary" onClick={() => setCreating('candidate')} />
            <Button label="Add private note" variant="primary" onClick={() => setCreating('note')} />
          </>
        )
      }>
      <MetricRow>
        <Metric label="Open parent queries" value={number(data.parentRequests.length)} tone={data.parentRequests.length ? 'warning' : undefined} onClick={() => setParams({})} />
        <Metric label="Attendance awaiting review" value={number(data.pendingMeta.total)} onClick={() => setParams({})} />
        <Metric label="Classes to follow up" value={number(followUps.length)} onClick={() => setParams({tab: 'notes'})} />
        <Metric label="Candidates" value={number(data.candidates.length)} onClick={() => setParams({tab: 'hr'})} />
      </MetricRow>
      <TabList value={tab} onChange={value => setParams(value === 'review' ? {} : {tab: value})}>
        <Tab value="review" label="Parent review" />
        <Tab value="notes" label="Notes & follow-up" />
        <Tab value="hr" label="Teacher recruitment" />
      </TabList>

      {tab === 'review' && (
        <VStack gap={4}>
          <Card padding={4}>
            <VStack gap={3}>
              <Heading level={2}>Parent queries from the portal</Heading>
              <Text color="secondary" size="sm">
                Families can flag a class record from the parent portal. Unapproved classes they flag are held as disputed. Correct the attendance or record acceptance, then mark the query answered.
              </Text>
              <Rows<ParentRequest & Row>
                rows={data.parentRequests as (ParentRequest & Row)[]}
                empty="No open parent queries"
                columns={[
                  {key: 'receivedAt', header: 'Received', renderCell: r => <Text hasTabularNumbers>{utc(r.receivedAt)}</Text>},
                  {key: 'studentName', header: 'Student', renderCell: r => <Text>{`${r.studentName} · ${r.studentCode}`}</Text>},
                  {
                    key: 'lessonStartsAt',
                    header: 'Class',
                    renderCell: r => <Text>{r.lessonStartsAt ? `${utc(r.lessonStartsAt)} · ${r.actualMinutes ?? '—'} min · ${titleCase(r.attendanceStatus)}` : '—'}</Text>,
                  },
                  {key: 'note', header: "Parent's message", renderCell: r => <Text>{r.note}</Text>},
                  {key: 'id', header: '', align: 'end', renderCell: r => (canWrite ? <Button label="Mark answered" size="sm" variant="secondary" onClick={() => setActing({kind: 'resolve', row: r})} /> : <Text> </Text>)},
                ]}
              />
            </VStack>
          </Card>

          <Card padding={4}>
            <VStack gap={3}>
              <Heading level={2}>Monthly parent statement</Heading>
              <HStack gap={2} wrap="wrap" vAlign="end">
                <StackItem size="fill">
                  <Selector
                    label="Student"
                    hasSearch
                    placeholder="Choose a student"
                    value={statementStudent}
                    onChange={value => setStatementStudent(value ?? undefined)}
                    options={data.students.map(s => ({value: String(s.id), label: `${s.name} · ${s.code}`}))}
                  />
                </StackItem>
                <Selector label="Month" value={statementMonth} onChange={value => value && setStatementMonth(value)} options={monthOptions(timezone)} />
                <Button
                  label="Prepare parent statement"
                  variant="secondary"
                  isDisabled={!statementStudent}
                  href={statementStudent ? `/print/u/${unitId}/statement?student_id=${statementStudent}&month=${statementMonth}` : undefined}
                  {...{target: '_blank', rel: 'noopener'}}
                />
                {canWrite && (
                  <Button
                    label="Record statement sharing"
                    variant="primary"
                    isDisabled={!statementStudent || statement.isLoading}
                    isLoading={sharing && !statement.data && !statement.error}
                    onClick={() => setSharing(true)}
                  />
                )}
              </HStack>
              {sharing && statement.error && <Text color="secondary" size="sm">{statement.error.message}</Text>}
              {sharing && statement.data && statement.data.review.entries.length === 0 && (
                <Text color="secondary" size="sm">No submitted entries are waiting for parent review for this student and month. Nothing to record.</Text>
              )}
              <Text color="secondary" size="sm">
                Opens the printable statement in a new tab. After you send it to the parent, record the sharing evidence and objection deadline for every submitted entry of that month.
              </Text>
            </VStack>
          </Card>

          <Card padding={4}>
            <VStack gap={3}>
              <Heading level={2}>Attendance awaiting review</Heading>
              <Text color="secondary" size="sm">
                Share the monthly statement through your usual parent channel, then record the sharing evidence and objection deadline here. Silence counts only after that deadline. Disputes block approval. Nothing here sends messages or transfers money.
              </Text>
              <Rows<Pending & Row>
                rows={data.pending as (Pending & Row)[]}
                empty="No attendance waiting for review"
                columns={[
                  {key: 'studentName', header: 'Student', renderCell: r => <Text>{`${r.studentName} · lesson #${r.lessonId}`}</Text>},
                  {key: 'actualStartsAt', header: 'Started', renderCell: r => <Text hasTabularNumbers>{utc(r.actualStartsAt)}</Text>},
                  {key: 'actualMinutes', header: 'Minutes', align: 'end', renderCell: r => <Text hasTabularNumbers>{number(r.actualMinutes)}</Text>},
                  {key: 'submittedLate', header: 'Submitted', renderCell: r => <Text>{r.submittedLate ? 'After the teaching day' : 'On the teaching day'}</Text>},
                  {
                    key: 'parentReviewStatus',
                    header: 'Parent review',
                    renderCell: r => (
                      <VStack gap={0.5}>
                        <Status value={r.parentReviewStatus ?? 'pending'} />
                        {r.parentDeadlineAt && <Text size="sm" color="secondary">{`Deadline ${utc(r.parentDeadlineAt)}`}</Text>}
                        {r.changeReason && <Text size="sm" color="secondary">{`Time change: ${r.changeReason}`}</Text>}
                      </VStack>
                    ),
                  },
                  {
                    key: 'id',
                    header: '',
                    align: 'end',
                    renderCell: r => (
                      <HStack gap={1} hAlign="end">
                        {canWrite && <Button label="Record review" size="sm" variant="secondary" onClick={() => setActing({kind: 'review', row: r})} />}
                        {canWrite && data.can.approve && <Button label="Approve" size="sm" variant="ghost" onClick={() => setActing({kind: 'approve', row: r})} />}
                      </HStack>
                    ),
                  },
                ]}
              />
              {data.pendingMeta.total > data.pendingMeta.perPage && (
                <Pagination page={data.pendingMeta.page} onChange={setPage} totalItems={data.pendingMeta.total} pageSize={data.pendingMeta.perPage} size="sm" label="Attendance pages" />
              )}
            </VStack>
          </Card>
        </VStack>
      )}

      {tab === 'notes' && (
        <VStack gap={4}>
          <Card padding={4}>
            <VStack gap={3}>
              <Heading level={2}>Internal notes and demo outcomes</Heading>
              <Text color="secondary" size="sm">
                Notes stay private. “Try another teacher” records a fit preference, not a performance complaint.
              </Text>
              {data.notes.length === 0 ? (
                <EmptyState title="No private notes yet" isCompact />
              ) : (
                <List hasDividers edge="flush">
                  {data.notes.map(note => (
                    <ListItem
                      key={note.id}
                      label={`Lesson #${note.lessonId} · ${titleCase(note.outcome ?? note.kind)}`}
                      description={`${note.note ?? ''}${note.note ? ' — ' : ''}${note.actorName} · ${utc(note.createdAt)}`}
                    />
                  ))}
                </List>
              )}
            </VStack>
          </Card>
          <Card padding={4}>
            <VStack gap={3}>
              <Heading level={2}>Shortened classes to check for makeup time</Heading>
              <Rows<Row>
                rows={data.shortfalls.map(s => ({...s, id: s.lessonId}))}
                empty="No shortened classes in the latest 200 attendance records"
                columns={[
                  {key: 'studentName', header: 'Student', renderCell: r => <Text>{`${r.studentName} · lesson #${r.lessonId}`}</Text>},
                  {key: 'startsAt', header: 'Class', renderCell: r => <Text hasTabularNumbers>{utc(String(r.startsAt))}</Text>},
                  {key: 'plannedMinutes', header: 'Minutes short', align: 'end', renderCell: r => <Text hasTabularNumbers>{number(Number(r.plannedMinutes) - Number(r.actualMinutes))}</Text>},
                ]}
              />
            </VStack>
          </Card>
          <Card padding={4}>
            <VStack gap={3}>
              <Heading level={2}>Recent classes needing follow-up</Heading>
              <Text color="secondary" size="sm">
                Cancelled, no-show, or still scheduled after the class ended. A missing submission does not prove a missed class; check what happened.
              </Text>
              <Rows<Row>
                rows={followUps as unknown as Row[]}
                empty="Nothing to follow up in the latest 200 classes"
                columns={[
                  {key: 'startsAt', header: 'Class', renderCell: r => <Text hasTabularNumbers>{`#${r.id} · ${utc(String(r.startsAt))}`}</Text>},
                  {key: 'studentName', header: 'Student / teacher', renderCell: r => <Text>{`${r.studentName} / ${r.teacherName}`}</Text>},
                  {key: 'kind', header: 'Kind', renderCell: r => <Text>{titleCase(String(r.kind))}</Text>},
                  {key: 'status', header: 'Status', renderCell: r => <Status value={String(r.status)} />},
                ]}
              />
              <Link href={`/app/u/${unitId}/timetable`}>Manage the timetable</Link>
            </VStack>
          </Card>
        </VStack>
      )}

      {tab === 'hr' && (
        <Card padding={4}>
          <VStack gap={3}>
            <HStack gap={2} vAlign="center" wrap="wrap">
              <StackItem size="fill">
                <Heading level={2}>Teacher recruitment</Heading>
              </StackItem>
              {canWrite && data.candidates.length > 0 && <Button label="Record interview" variant="secondary" onClick={() => setCreating('interview')} />}
            </HStack>
            <Text color="secondary" size="sm">
              Record website applications, then interview candidates when subject demand arises. Ready candidates still need teacher onboarding and eligibility before assignment.
            </Text>
            {data.candidates.length === 0 ? (
              <EmptyState title="No candidates yet" isCompact />
            ) : (
              <List hasDividers edge="flush">
                {data.candidates.map(c => (
                  <ListItem
                    key={c.id}
                    label={`${c.name} · ${titleCase(c.status)}`}
                    description={[
                      [c.subjects, c.levels, c.languages].filter(Boolean).join(' · '),
                      c.qualification,
                      [c.availability, c.contact].filter(Boolean).join(' · '),
                      ...c.interviews.map(i => `Interview ${i.score}/100 · ${titleCase(i.decision)} · ${utc(i.createdAt)}${i.strengths ? ` · Strengths: ${i.strengths}` : ''}${i.concerns ? ` · Concerns: ${i.concerns}` : ''}`),
                    ].join('\n')}
                    endContent={c.interviews.find(i => i.recordingUrl) ? <Link href={c.interviews.find(i => i.recordingUrl)?.recordingUrl ?? ''} target="_blank" rel="noopener noreferrer">Recording</Link> : undefined}
                  />
                ))}
              </List>
            )}
            <Text color="secondary" size="sm">
              Showing the latest 200 candidates and interviews.
            </Text>
          </VStack>
        </Card>
      )}

      {acting?.kind === 'resolve' && (
        <ConfirmActionDialog
          isOpen
          onOpenChange={open => !open && setActing(null)}
          title={`Answer ${acting.row.studentName}'s query`}
          description={acting.row.note}
          fields={[{kind: 'textarea', name: 'reply', label: 'What you told the parent', required: true}]}
          fixed={{request_id: acting.row.id}}
          url={tuitionUrl('parent-request-resolve')}
          confirmTitle="Mark this query answered?"
          confirmDescription="The query closes with your reply in the audit history. Correct or accept the class record separately if needed."
          confirmLabel="Mark answered"
          successMessage="Parent query marked as answered."
          isDestructive={false}
          onDone={tuition.reload}
        />
      )}
      {acting?.kind === 'review' && (
        <ConfirmActionDialog
          isOpen
          onOpenChange={open => !open && setActing(null)}
          title={`Parent review · ${acting.row.studentName}`}
          description={`Lesson #${acting.row.lessonId} · ${acting.row.actualMinutes} minutes`}
          fields={[
            {
              kind: 'select',
              name: 'decision',
              label: 'Review update',
              required: true,
              options: [
                {value: 'share', label: 'Statement shared with parent'},
                {value: 'accepted', label: 'Parent accepted / dispute resolved'},
                {value: 'disputed', label: 'Parent disputed this entry'},
              ],
            },
            {kind: 'datetime', name: 'deadline_at', label: `Objection deadline (${data.timezone} time)`, description: 'Required when sharing.'},
            {kind: 'textarea', name: 'note', label: 'Sharing evidence, parent reply or resolution', required: true},
          ]}
          fixed={{attendance_id: acting.row.id}}
          initial={{decision: 'share'}}
          url={tuitionUrl('parent-review')}
          confirmTitle="Save this parent review?"
          confirmDescription="It is recorded with your name and time in the audit history."
          confirmLabel="Save review"
          successMessage="Parent review saved."
          isDestructive={false}
          onDone={tuition.reload}
        />
      )}
      {acting?.kind === 'approve' && (
        <ConfirmActionDialog
          isOpen
          onOpenChange={open => !open && setActing(null)}
          title={`Approve lesson #${acting.row.lessonId}`}
          description={`${acting.row.studentName} · ${acting.row.actualMinutes} minutes`}
          fields={[]}
          fixed={{attendance_id: acting.row.id}}
          url={actionUrl(unitId, 'approve')}
          confirmTitle="Approve reviewed teaching for earnings?"
          confirmDescription="The teacher's earnings for this class are accrued. Disputed entries cannot be approved. No money is transferred."
          confirmLabel="Approve"
          successMessage="Attendance approved."
          isDestructive={false}
          onDone={tuition.reload}
        />
      )}

      <FormDrawer
        isOpen={creating === 'note'}
        onOpenChange={open => !open && setCreating(null)}
        title="Add a private note"
        description="Internal notes and demo outcomes stay private to managers."
        fields={[
          {kind: 'select', name: 'lesson_id', label: 'Class', required: true, options: data.lessons.map(l => ({value: String(l.id), label: `#${l.id} · ${l.studentName} / ${l.teacherName} · ${utc(l.startsAt)} · ${l.kind}`}))},
          {kind: 'select', name: 'kind', label: 'Record type', required: true, options: [{value: 'internal', label: 'Internal note'}, {value: 'demo', label: 'Demo outcome'}]},
          {
            kind: 'select',
            name: 'outcome',
            label: 'Demo outcome',
            options: [
              {value: 'continue', label: 'Continue with this teacher'},
              {value: 'try_another', label: 'Try another teacher'},
              {value: 'awaiting_feedback', label: 'Awaiting feedback'},
            ],
          },
          {kind: 'textarea', name: 'note', label: 'Note', description: 'Optional for a demo outcome.'},
        ]}
        initial={{kind: 'internal'}}
        action={tuitionUrl('note')}
        submitLabel="Save private update"
        successMessage="Saved with your name and time in the audit history."
        onSaved={tuition.reload}
      />
      <FormDrawer
        isOpen={creating === 'candidate'}
        onOpenChange={open => !open && setCreating(null)}
        title="Add candidate"
        fields={[
          {kind: 'text', name: 'name', label: 'Name', required: true},
          {kind: 'text', name: 'contact', label: 'Phone or email', required: true},
          {kind: 'text', name: 'qualification', label: 'Qualifications', required: true},
          {kind: 'text', name: 'subjects', label: 'Subjects', required: true},
          {kind: 'text', name: 'levels', label: 'Teaching levels', required: true, description: 'LP / UP / high school / higher secondary'},
          {kind: 'text', name: 'languages', label: 'Languages'},
          {kind: 'text', name: 'availability', label: 'Available days and times'},
        ]}
        action={tuitionUrl('candidate')}
        submitLabel="Save candidate"
        successMessage="Candidate saved."
        onSaved={() => {
          setParams({tab: 'hr'});
          tuition.reload();
        }}
      />
      <FormDrawer
        isOpen={creating === 'interview'}
        onOpenChange={open => !open && setCreating(null)}
        title="Record interview"
        fields={[
          {kind: 'select', name: 'candidate_id', label: 'Candidate', required: true, options: data.candidates.map(c => ({value: String(c.id), label: `${c.name} · ${c.subjects} · ${c.status}`}))},
          {kind: 'text', name: 'score', label: 'Score (0–100)', required: true},
          {kind: 'url', name: 'recording_url', label: 'Recording link (HTTPS)'},
          {kind: 'textarea', name: 'strengths', label: 'Strengths'},
          {kind: 'textarea', name: 'concerns', label: 'Concerns'},
          {
            kind: 'select',
            name: 'decision',
            label: 'Decision',
            required: true,
            options: [
              {value: 'ready', label: 'Ready for onboarding'},
              {value: 'hold', label: 'Keep in pool / follow up'},
              {value: 'rejected', label: 'Do not proceed'},
            ],
          },
        ]}
        initial={{decision: 'hold'}}
        action={tuitionUrl('interview')}
        submitLabel="Save interview"
        successMessage="Interview saved."
        onSaved={tuition.reload}
      />
      <RecordSharingDialog
        isOpen={sharing && Boolean(statement.data?.review.entries.length)}
        onOpenChange={open => !open && setSharing(false)}
        unitId={unitId}
        statement={statement.data}
        onDone={() => {
          setSharing(false);
          statement.reload();
          tuition.reload();
        }}
      />
    </Page>
  );
}
