import {useState} from 'react';
import {useParams} from 'react-router';
import {VStack} from '@astryxdesign/core/Layout';
import {Card} from '@astryxdesign/core/Card';
import {Heading, Text} from '@astryxdesign/core/Text';
import {Link} from '@astryxdesign/core/Link';
import {Button} from '@astryxdesign/core/Button';
import {MetadataList, MetadataListItem} from '@astryxdesign/core/MetadataList';
import {Table} from '@astryxdesign/core/Table';
import {EmptyState} from '@astryxdesign/core/EmptyState';
import {Page, PageError, PageLoading} from '../../components/PageState';
import {Status} from '../../components/Status';
import {Metric, MetricRow} from '../../components/Metric';
import {useResource} from '../../lib/hooks';
import {date, money, number} from '../../lib/format';
import {unitById} from '../../lib/session';
import {canAct} from './access';
import {TeacherForms, type TeacherFormKind} from './TeacherForms';

interface Profile {
  teacher: {id: number; code: string; name: string; status: string; timezone: string; levels: string[]; category: string | null; ttHours: string};
  eligibilities: {subject: string; grade: string; syllabus: string}[];
  classes: {id: number; studentId: number; studentName: string; subjectName: string | null; startsOn: string; endsOn: string | null; status: string}[];
  statement: {earnedPaise: number; paidPaise: number; otherAdjustmentsPaise: number; netPayablePaise: number} | null;
  can: {seeMoney: boolean; openStudent: boolean};
  options: {levels: {value: string; label: string}[]; categories: string[]};
}

export default function TeacherDetail() {
  const params = useParams();
  const unitId = Number(params.unit);
  const unit = unitById(unitId);
  const {data, error, isLoading, reload} = useResource<Profile>(`/app-api/units/${unitId}/teachers/${params.teacher}`);
  const [form, setForm] = useState<TeacherFormKind | null>(null);

  if (isLoading) return <PageLoading />;
  if (error || !data) return <Page title="Teacher"><PageError error={error ?? new Error('Not found')} onRetry={reload} /></Page>;

  const {teacher, statement} = data;
  const canClassify = canAct(unit, 'teacher-classify');
  const canEligibility = canAct(unit, 'eligibility');
  const active = data.classes.filter(c => c.status === 'active').length;
  const eligibilityRows = data.eligibilities.map((e, i) => ({...e, id: i}));

  return (
    <Page
      title={teacher.name}
      description={`${teacher.code}${teacher.category ? ` · ${teacher.category}` : ''}`}
      crumbs={[{label: 'Teachers', href: `/app/u/${unitId}/teachers`}, {label: teacher.name}]}
      actions={
        <>
          {canEligibility && <Button label="Add subject" variant="primary" onClick={() => setForm('eligibility')} />}
          {canClassify && <Button label="Set level & category" variant="secondary" onClick={() => setForm('classify')} />}
        </>
      }>
      <MetricRow>
        <Metric label="Timetable hours / week" value={teacher.ttHours} />
        <Metric label="Active classes" value={number(active)} />
        <Metric label="Subjects" value={number(new Set(data.eligibilities.map(e => e.subject)).size)} />
        {statement && <Metric label="Net payable" value={money(statement.netPayablePaise)} hint={`Earned ${money(statement.earnedPaise, {whole: true})} · paid ${money(statement.paidPaise, {whole: true})}`} />}
      </MetricRow>

      <Card padding={4}>
        <VStack gap={3}>
          <Heading level={2}>Details</Heading>
          <MetadataList columns="multi" orientation="vertical" label={{position: 'top'}}>
            <MetadataListItem label="Status"><Status value={teacher.status} /></MetadataListItem>
            <MetadataListItem label="Levels">{teacher.levels.join(', ') || 'Not set'}</MetadataListItem>
            <MetadataListItem label="Category">{teacher.category ?? 'Not set'}</MetadataListItem>
            <MetadataListItem label="Time zone">{teacher.timezone}</MetadataListItem>
            {statement && <MetadataListItem label="Other adjustments">{money(statement.otherAdjustmentsPaise)}</MetadataListItem>}
          </MetadataList>
        </VStack>
      </Card>

      <Card padding={4}>
        <VStack gap={3}>
          <Heading level={2}>Can teach</Heading>
          {eligibilityRows.length === 0 ? (
            <EmptyState title="No subjects yet" description="Add a subject, grade and syllabus before assigning classes." isCompact
              actions={canEligibility ? <Button label="Add subject" variant="secondary" onClick={() => setForm('eligibility')} /> : undefined} />
          ) : (
            <Table
              data={eligibilityRows}
              idKey="id"
              columns={[
                {key: 'subject', header: 'Subject', renderCell: r => <Text>{r.subject}</Text>},
                {key: 'grade', header: 'Grade', renderCell: r => <Text>{r.grade}</Text>},
                {key: 'syllabus', header: 'Syllabus', renderCell: r => <Text>{r.syllabus}</Text>},
              ]}
            />
          )}
        </VStack>
      </Card>

      <Card padding={4}>
        <VStack gap={3}>
          <Heading level={2}>Classes</Heading>
          {data.classes.length === 0 ? (
            <EmptyState title="No classes assigned" isCompact />
          ) : (
            <Table
              data={data.classes}
              idKey="id"
              hasHover
              textOverflow="truncate"
              columns={[
                {key: 'studentName', header: 'Student', renderCell: r => (data.can.openStudent ? <Link href={`/app/u/${unitId}/students/${r.studentId}`}>{r.studentName}</Link> : <Text>{r.studentName}</Text>)},
                {key: 'subjectName', header: 'Subject', renderCell: r => <Text>{r.subjectName ?? '—'}</Text>},
                {key: 'startsOn', header: 'From', renderCell: r => <Text hasTabularNumbers>{date(r.startsOn)}</Text>},
                {key: 'endsOn', header: 'Until', renderCell: r => <Text hasTabularNumbers>{r.endsOn ? date(r.endsOn) : 'Ongoing'}</Text>},
                {key: 'status', header: 'Status', renderCell: r => <Status value={r.status} />},
              ]}
            />
          )}
        </VStack>
      </Card>

      {unit && (
        <TeacherForms
          unitId={unitId}
          timezone={unit.timezone}
          kind={form}
          teacher={teacher}
          levels={data.options.levels}
          categories={data.options.categories}
          onClose={() => setForm(null)}
          onSaved={reload}
        />
      )}
    </Page>
  );
}
