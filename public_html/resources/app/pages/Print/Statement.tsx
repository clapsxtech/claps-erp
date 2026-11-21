// Monthly parent statement (staff copy).
// Below the statement, managers record that it was shared (tuition monthly-review);
// that part is never printed.
import {useState} from 'react';
import {useParams, useSearchParams} from 'react-router';
import {VStack} from '@astryxdesign/core/Layout';
import {Heading, Text} from '@astryxdesign/core/Text';
import {Button} from '@astryxdesign/core/Button';
import {Card} from '@astryxdesign/core/Card';
import {Table, type TableColumn} from '@astryxdesign/core/Table';
import {useResource} from '../../lib/hooks';
import {DocumentState, PrintControls, SummaryTable, useDocumentTitle} from './PrintFrame';
import {RecordSharingDialog} from './RecordSharing';

interface Row extends Record<string, unknown> {
  id: number;
  when: string;
  teacher: string;
  subject: string;
  minutes: number;
  charge: string;
  status: string;
}

export interface StatementData {
  businessName: string;
  businessTimezone: string;
  month: string;
  student: {id: number; name: string; code: string; timezone: string};
  rows: Row[];
  totalMinutes: number;
  account: {opening: string; lines: {label: string; amount: string}[]; closing: string};
  review: {canRecord: boolean; entries: {attendanceId: number; snapshot: string}[]};
}

export default function Statement() {
  const {unit} = useParams();
  const [params] = useSearchParams();
  // Query parameters student_id and month; "student" is accepted as a short form.
  const studentId = params.get('student_id') ?? params.get('student') ?? '';
  const month = params.get('month') ?? '';
  const {data, error, reload} = useResource<StatementData>(`/app-api/units/${unit}/print/statement`, {student_id: studentId, month});
  const [recording, setRecording] = useState(false);
  useDocumentTitle(data ? `Attendance statement · ${data.student.name}` : undefined);
  if (!data) return <DocumentState error={error} onRetry={reload} />;
  const columns: TableColumn<Row>[] = [
    {key: 'when', header: 'Date and time', renderCell: r => <Text hasTabularNumbers>{r.when}</Text>},
    {key: 'teacher', header: 'Teacher', renderCell: r => <Text>{r.teacher}</Text>},
    {key: 'subject', header: 'Subject', renderCell: r => <Text>{r.subject}</Text>},
    {key: 'minutes', header: 'Minutes', align: 'end', renderCell: r => <Text hasTabularNumbers>{String(r.minutes)}</Text>},
    {key: 'charge', header: 'Class charge', align: 'end', renderCell: r => <Text hasTabularNumbers>{r.charge}</Text>},
  ];
  const account: {label: string; amount: string; isTotal?: boolean}[] = [
    {label: 'Balance at the start of the month', amount: data.account.opening},
    ...data.account.lines,
    {label: 'Balance at the end of the month', amount: data.account.closing, isTotal: true},
  ];
  const canRecord = data.review.canRecord && data.review.entries.length > 0;
  return (
    <VStack gap={4}>
      <PrintControls printLabel="Print" back={{label: 'Back to class follow-up', href: `/app/u/${unit}/tuition`}} />
      <Heading level={1}>{data.businessName}</Heading>
      <Heading level={2}>{`Attendance statement · ${data.month}`}</Heading>
      <Text>{`${data.student.name} · ${data.student.code} · Times shown in ${data.student.timezone}`}</Text>
      <VStack isScrollable>
        <Table<Row> data={data.rows} columns={columns} idKey="id" density="compact" />
      </VStack>
      <Text>{`Total reported teaching: ${data.totalMinutes} minutes. Please check the entries and report any discrepancy to your tuition administrator.`}</Text>
      <Text>Class charges shown are calculated from reported teaching and the agreed rate. Account posting is handled separately.</Text>
      <Heading level={2}>{`Account summary for ${data.month}`}</Heading>
      <SummaryTable rows={account} />
      <Text>A credit is paid in advance; an amount due is still to be paid. Class charges here are those posted to the account, which can differ from the reported teaching above until every class is reviewed.</Text>
      <Card padding={4} className="print-controls">
        <VStack gap={2}>
          <Heading level={2}>After sharing</Heading>
          <Text color="secondary">
            Print or save this page as PDF to share with the parent. Then record the evidence and deadline after sending this statement through your existing parent communication channel. Only the listed submitted entries are included. New or corrected entries require a new review.
          </Text>
          {canRecord ? (
            <Button label="Record statement sharing" variant="secondary" onClick={() => setRecording(true)} />
          ) : (
            <Text color="secondary">{data.review.entries.length === 0 ? 'No submitted entries are waiting for parent review this month.' : 'This team is read-only right now.'}</Text>
          )}
        </VStack>
      </Card>
      <RecordSharingDialog isOpen={recording} onOpenChange={setRecording} unitId={Number(unit)} statement={data} onDone={reload} />
    </VStack>
  );
}
