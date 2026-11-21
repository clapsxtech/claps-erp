import {useEffect, useMemo, useState, type ComponentProps} from 'react';
import {useParams} from 'react-router';
import {VStack, HStack} from '@astryxdesign/core/Layout';
import {Card} from '@astryxdesign/core/Card';
import {Text} from '@astryxdesign/core/Text';
import {Link} from '@astryxdesign/core/Link';
import {Button} from '@astryxdesign/core/Button';
import {Icon} from '@astryxdesign/core/Icon';
import {DateInput} from '@astryxdesign/core/DateInput';
import {TabList, Tab} from '@astryxdesign/core/TabList';
import {PlusIcon} from '@heroicons/react/24/outline';
import {Page, PageError} from '../../components/PageState';
import {DataTable, type DataColumn, type ListMeta} from '../../components/DataTable';
import {Toned} from '../../components/Amount';
import {useDebounced, useListQuery, useResource} from '../../lib/hooks';
import {date, number} from '../../lib/format';
import {unitById} from '../../lib/session';
import {withQuery} from '../../lib/api';
import {ActionDrawer} from './ActionDrawer';
import type {ActionConfig} from './lessonActions';
import {canAct, todayIn, workspaceAction} from './shared';

type DateValue = ComponentProps<typeof DateInput>['value'];

interface MarkRow extends Record<string, unknown> {
  id: number;
  assessedOn: string;
  studentId: number;
  studentName: string;
  studentCode: string;
  teacherName: string;
  subjectName: string;
  assessment: string;
  score: number;
  maximum: number;
  percentage: number;
  notes: string | null;
}

interface EligibilityRow extends Record<string, unknown> {
  id: number;
  subjectName: string;
  teacherName: string;
  grade: string;
  syllabus: string;
}

const DEFAULTS = {tab: 'results', q: '', from: '', through: '', sort: 'assessed_on', dir: 'desc', page: '1', per_page: '25'};

export default function Academics() {
  const unitId = Number(useParams().unit);
  const unit = unitById(unitId);
  const [query, setQuery] = useListQuery(DEFAULTS);
  const [searchText, setSearchText] = useState(query.q);
  const debounced = useDebounced(searchText);
  useEffect(() => {
    if (debounced !== query.q) setQuery({q: debounced});
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debounced]);
  const isResults = query.tab !== 'eligibility';

  const marks = useResource<{data: MarkRow[]; meta: ListMeta; can: {openProfile: boolean}}>(
    isResults ? `/app-api/units/${unitId}/academics` : null,
    {q: query.q, from: query.from, through: query.through, sort: query.sort, dir: query.dir, page: query.page, per_page: query.per_page},
  );
  const eligibility = useResource<{data: EligibilityRow[]; meta: {page: number; perPage: number; total: number}}>(
    isResults ? null : `/app-api/units/${unitId}/academics/eligibility`,
    {q: query.q, page: query.page},
  );
  const [drawer, setDrawer] = useState<{config: ActionConfig; isOpen: boolean} | null>(null);
  const canMark = canAct(unit, 'mark');
  const canEligibility = canAct(unit, 'eligibility');
  const today = todayIn(unit?.timezone ?? 'Asia/Kolkata');

  const markConfig: ActionConfig = {
    action: workspaceAction(unitId, 'mark'),
    permission: 'mark',
    title: 'Record an assessment',
    description: "Choose the student's teaching assignment. Scores may be zero but cannot exceed the maximum.",
    submitLabel: 'Save assessment',
    successMessage: 'Assessment saved.',
    fields: [
      {kind: 'lookup', name: 'enrollment_id', label: 'Teaching assignment', lookup: 'enrollments', required: true},
      {kind: 'text', name: 'assessment', label: 'Assessment name', required: true, placeholder: 'e.g. Algebra review'},
      {kind: 'date', name: 'assessed_on', label: 'Assessment date', required: true, max: today},
      {kind: 'number', name: 'score', label: 'Score', required: true, min: 0},
      {kind: 'number', name: 'maximum', label: 'Maximum score', required: true, min: 1},
      {kind: 'textarea', name: 'notes', label: 'Notes'},
    ],
    initial: {assessed_on: today},
  };
  const eligibilityConfig: ActionConfig = {
    action: workspaceAction(unitId, 'eligibility'),
    permission: 'eligibility',
    title: "Add a teacher's subject eligibility",
    description: 'The subject, grade and syllabus the teacher can teach. This does not assign a student or schedule a class.',
    submitLabel: 'Save eligibility',
    successMessage: 'Eligibility saved.',
    fields: [
      {kind: 'lookup', name: 'teacher_id', label: 'Teacher', lookup: 'teachers', required: true},
      {kind: 'text', name: 'subject', label: 'Subject', required: true},
      {kind: 'text', name: 'grade', label: 'Grade', required: true},
      {kind: 'text', name: 'syllabus', label: 'Syllabus', required: true},
    ],
  };

  const can = marks.data?.can;
  const columns = useMemo<DataColumn<MarkRow>[]>(
    () => [
      {key: 'assessedOn', header: 'Date', sortKey: 'assessed_on', renderCell: r => <Text hasTabularNumbers>{date(r.assessedOn)}</Text>},
      {
        key: 'studentName',
        header: 'Student',
        sortKey: 'student',
        renderCell: r => (can?.openProfile ? <Link href={`/app/u/${unitId}/students/${r.studentId}`}>{r.studentName}</Link> : <Text>{r.studentName}</Text>),
      },
      {key: 'subjectName', header: 'Subject · teacher', sortKey: 'subject', renderCell: r => <Text>{`${r.subjectName} · ${r.teacherName}`}</Text>},
      {key: 'assessment', header: 'Assessment', sortKey: 'assessment', renderCell: r => <Text>{r.assessment}</Text>},
      {key: 'score', header: 'Score', align: 'end', renderCell: r => <Text hasTabularNumbers>{`${r.score} / ${r.maximum}`}</Text>},
      {key: 'percentage', header: '%', align: 'end', renderCell: r => <Toned tone={r.percentage < 40 ? 'error' : undefined}>{`${r.percentage.toFixed(1)}%`}</Toned>},
      {key: 'notes', header: 'Notes', hideOnPhone: true, renderCell: r => <Text color="secondary">{r.notes ?? ''}</Text>},
    ],
    [can, unitId],
  );
  const eligibilityColumns: DataColumn<EligibilityRow>[] = [
    {key: 'subjectName', header: 'Subject', renderCell: r => <Text>{r.subjectName}</Text>},
    {key: 'teacherName', header: 'Teacher', renderCell: r => <Text>{r.teacherName}</Text>},
    {key: 'grade', header: 'Grade', renderCell: r => <Text>{r.grade}</Text>},
    {key: 'syllabus', header: 'Syllabus', renderCell: r => <Text>{r.syllabus}</Text>},
  ];

  const error = isResults ? marks.error && !marks.data && marks.error : eligibility.error && !eligibility.data && eligibility.error;
  if (error) {
    return (
      <Page title="Results">
        <PageError error={error} onRetry={isResults ? marks.reload : eligibility.reload} />
      </Page>
    );
  }

  const hasFilters = Boolean(query.q || query.from || query.through);
  const clear = () => {
    setSearchText('');
    setQuery({q: '', from: '', through: ''});
  };
  const exportHref = unit?.exports.includes('marks') ? withQuery(`/erp/units/${unitId}/academics/export`, {q: query.q, from: query.from, through: query.through}) : undefined;

  return (
    <Page
      title="Results"
      description={isResults && marks.data ? `${number(marks.data.meta.total)} assessment results${hasFilters ? ' matching' : ''}` : 'Assessments and the subjects each teacher can teach.'}
      actions={
        <>
          {canMark && <Button label="Record assessment" variant="primary" icon={<Icon icon={PlusIcon} size="sm" />} onClick={() => setDrawer({config: markConfig, isOpen: true})} />}
          {canEligibility && <Button label="Add eligibility" variant="secondary" onClick={() => setDrawer({config: eligibilityConfig, isOpen: true})} />}
        </>
      }>
      <TabList value={isResults ? 'results' : 'eligibility'} onChange={tab => setQuery({tab})}>
        <Tab value="results" label="Assessment results" />
        <Tab value="eligibility" label="Subject eligibility" />
      </TabList>
      {isResults ? (
        <DataTable<MarkRow>
          label="Results"
          rows={marks.data?.data}
          meta={marks.data?.meta}
          columns={columns}
          idKey="id"
          isLoading={marks.isLoading}
          isRefreshing={marks.isRefreshing}
          search={searchText}
          onSearch={setSearchText}
          searchPlaceholder="Student, teacher, subject or assessment"
          filters={
            <HStack gap={2} wrap="wrap">
              <DateInput label="From" isLabelHidden placeholder="From" value={(query.from || undefined) as DateValue} onChange={value => setQuery({from: value ?? ''})} />
              <DateInput label="Through" isLabelHidden placeholder="Through" value={(query.through || undefined) as DateValue} onChange={value => setQuery({through: value ?? ''})} min={(query.from || undefined) as DateValue} />
            </HStack>
          }
          onSort={(sort, dir) => setQuery({sort, dir})}
          onPage={page => setQuery({page: String(page)}, {resetPage: false})}
          onPageSize={size => setQuery({per_page: String(size)})}
          exportHref={exportHref}
          hasActiveFilters={hasFilters}
          onClearFilters={clear}
          emptyTitle="No assessment results yet"
          emptyDescription="Record an assessment to see results here."
          emptyAction={canMark ? <Button label="Record assessment" variant="secondary" onClick={() => setDrawer({config: markConfig, isOpen: true})} /> : undefined}
          phoneCard={{
            title: r => r.studentName,
            subtitle: r => `${date(r.assessedOn)} · ${r.subjectName} · ${r.assessment}`,
            trailing: r => <Text hasTabularNumbers>{`${r.score}/${r.maximum} · ${r.percentage.toFixed(0)}%`}</Text>,
            href: r => (can?.openProfile ? `/app/u/${unitId}/students/${r.studentId}` : undefined),
          }}
        />
      ) : (
        <VStack gap={3}>
          <DataTable<EligibilityRow>
            label="Subject eligibility"
            rows={eligibility.data?.data}
            meta={eligibility.data ? {...eligibility.data.meta, sort: '', dir: 'asc'} : undefined}
            columns={eligibilityColumns}
            idKey="id"
            isLoading={eligibility.isLoading}
            isRefreshing={eligibility.isRefreshing}
            search={searchText}
            onSearch={setSearchText}
            searchPlaceholder="Subject or teacher"
            onSort={() => undefined}
            onPage={page => setQuery({page: String(page)}, {resetPage: false})}
            onPageSize={() => undefined}
            hasActiveFilters={Boolean(query.q)}
            onClearFilters={clear}
            emptyTitle="No subject eligibility recorded"
            emptyDescription="Eligibility is specific to a teacher, grade and syllabus."
            emptyAction={canEligibility ? <Button label="Add eligibility" variant="secondary" onClick={() => setDrawer({config: eligibilityConfig, isOpen: true})} /> : undefined}
            phoneCard={{title: r => r.subjectName, subtitle: r => `${r.teacherName} · ${r.grade} · ${r.syllabus}`}}
          />
          <Card padding={3} variant="muted">
            <Text color="secondary">Eligibility is per teacher, grade and syllabus. Weekly assignments are made from Teacher matching.</Text>
          </Card>
        </VStack>
      )}
      {drawer && (
        <ActionDrawer
          isOpen={drawer.isOpen}
          onOpenChange={isOpen => setDrawer(current => (current ? {...current, isOpen} : null))}
          unitId={unitId}
          title={drawer.config.title}
          description={drawer.config.description}
          fields={drawer.config.fields}
          initial={drawer.config.initial}
          action={drawer.config.action}
          submitLabel={drawer.config.submitLabel}
          successMessage={drawer.config.successMessage}
          onSaved={() => (drawer.config.permission === 'mark' ? marks.reload() : eligibility.reload())}
        />
      )}
    </Page>
  );
}
