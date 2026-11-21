import {useEffect, useState, type ComponentProps} from 'react';
import {useParams} from 'react-router';
import {VStack, HStack, StackItem} from '@astryxdesign/core/Layout';
import {Grid} from '@astryxdesign/core/Grid';
import {Card} from '@astryxdesign/core/Card';
import {Heading, Text} from '@astryxdesign/core/Text';
import {Button} from '@astryxdesign/core/Button';
import {TextInput} from '@astryxdesign/core/TextInput';
import {Selector} from '@astryxdesign/core/Selector';
import {DateInput} from '@astryxdesign/core/DateInput';
import {TimeInput} from '@astryxdesign/core/TimeInput';
import {NumberInput} from '@astryxdesign/core/NumberInput';
import {CheckboxInput} from '@astryxdesign/core/CheckboxInput';
import {List, ListItem} from '@astryxdesign/core/List';
import {Badge} from '@astryxdesign/core/Badge';
import {Banner} from '@astryxdesign/core/Banner';
import {EmptyState} from '@astryxdesign/core/EmptyState';
import {Skeleton} from '@astryxdesign/core/Skeleton';
import {ProgressBar} from '@astryxdesign/core/ProgressBar';
import {Pagination} from '@astryxdesign/core/Pagination';
import {MetadataList, MetadataListItem} from '@astryxdesign/core/MetadataList';
import {Page, PageError} from '../../components/PageState';
import {Status} from '../../components/Status';
import {ApiError} from '../../lib/api';
import {useDebounced, useListQuery, useResource} from '../../lib/hooks';
import {number, titleCase} from '../../lib/format';
import {unitById} from '../../lib/session';
import {ActionDrawer, type ActionField} from './ActionDrawer';
import type {ActionConfig} from './lessonActions';
import {LookupField} from './LookupField';
import {businessAction, canAct, CONFIRMATION_CHANNELS, dayLabel, newReference} from './shared';

type DateValue = ComponentProps<typeof DateInput>['value'];
type TimeValue = ComponentProps<typeof TimeInput>['value'];

interface Context {
  studentId: number | null;
  leadId: number | null;
  name: string;
  grade: string | null;
  syllabus: string | null;
  timezone: string;
  status: string;
}

interface Demo {
  id: number;
  teacherName: string | null;
  scheduledOn: string;
  scheduledTime: string;
  status: string;
  feedbackOutcome: string | null;
  next: 'timetable' | 'allocate' | 'feedback' | null;
  allocate: Record<string, string> | null;
}

interface Overview {
  leads: {id: number; name: string}[];
  handoffs: {demoId: number; leadId: number; leadName: string; when: string | null; teacherName: string | null; subjectName: string | null}[];
  context: Context | null;
  handoff: {learningRequirement: string | null; concern: string | null; aboutStudent: string | null; aboutParent: string | null} | null;
  demos: Demo[];
  seed: Record<string, string> | null;
  subjects: {id: number; name: string}[];
  options: {media: string[]; levels: {value: string; label: string}[]; categories: string[]; sorts: {value: string; label: string}[]};
  timezone: string;
}

interface TeacherMatch {
  id: number;
  code: string;
  name: string;
  timezone: string;
  levels: string | null;
  category: string | null;
  verifiedAt: string | null;
  blockers: string[];
  localTime: string;
  hours: number;
  ttHours: string | number;
  demoStats: {booked: number; attended: number; allocated: number};
  can: {prepare: boolean; demo: boolean; regular: boolean; needsFeedback: boolean};
  successfulDemos: {id: number; label: string}[];
}

interface Matches {
  slot: Record<string, string>;
  weekday: string;
  data: TeacherMatch[];
  meta: {page: number; perPage: number; total: number};
}

interface Prepared {
  teacher: {id: number; code: string; name: string; status: string; timezone: string};
  mediums: string[];
  verifiedAt: string | null;
  windows: {id: number; weekday: string; startTime: string; endTime: string; timezone: string; startsOn: string; endsOn: string | null}[];
  eligibilities: {subject: string; grade: string; syllabus: string}[];
}

const SLOT_KEYS = ['subject_id', 'grade', 'syllabus', 'medium', 'date', 'time', 'timezone', 'duration_minutes', 'teacher_q', 'level', 'category', 'sort', 'include_unverified'] as const;
type SlotKey = (typeof SLOT_KEYS)[number];
const DEFAULTS = {
  q: '', lead_id: '', student_id: '', demo_id: '', search: '', matches_page: '1', prepare: '',
  subject_id: '', grade: '', syllabus: '', medium: '', date: '', time: '', timezone: '', duration_minutes: '', teacher_q: '', level: '', category: '', sort: 'code', include_unverified: '',
};
const WEEKDAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'].map((label, i) => ({value: String(i + 1), label}));

const confirmFields = (recurring: boolean): ActionField[] => [
  {kind: 'confirm', name: 'teacher_confirmed', label: 'Teacher confirmed', required: true},
  {kind: 'confirm', name: 'student_confirmed', label: 'Parent / student confirmed', required: true},
  {kind: 'select', name: 'confirmation_channel', label: 'Confirmation channel', required: true, options: CONFIRMATION_CHANNELS},
  {kind: 'textarea', name: 'confirmation_note', label: 'Confirmation evidence', required: true, description: recurring ? 'Who confirmed this weekly schedule, when, and the agreed dates and times.' : 'Who confirmed, when, and the agreed date and time.'},
];

export default function Matching() {
  const unitId = Number(useParams().unit);
  const unit = unitById(unitId);
  const [query, setQuery] = useListQuery(DEFAULTS);
  const [leadSearch, setLeadSearch] = useState(query.q);
  const debouncedLead = useDebounced(leadSearch);
  useEffect(() => {
    if (debouncedLead !== query.q) setQuery({q: debouncedLead}, {resetPage: false});
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debouncedLead]);

  const learner = {q: query.q, lead_id: query.lead_id, student_id: query.student_id, demo_id: query.demo_id};
  const overview = useResource<Overview>(`/app-api/units/${unitId}/matching`, learner);
  const context = overview.data?.context ?? null;
  const learnerKey = context ? (context.leadId ? {lead_id: String(context.leadId)} : {student_id: String(context.studentId)}) : null;
  const slotQuery = Object.fromEntries(SLOT_KEYS.map(key => [key, query[key]]));
  const matches = useResource<Matches>(
    query.search && learnerKey ? `/app-api/units/${unitId}/matching/teachers` : null,
    learnerKey ? {...learnerKey, ...slotQuery, matches_page: query.matches_page} : undefined,
  );
  const prepared = useResource<Prepared>(query.prepare && canAct(unit, 'allocation-qualify') ? `/app-api/units/${unitId}/matching/prepare/${query.prepare}` : null);

  // The slot form is local until "Find teachers"; it starts from the URL, then the server's seed.
  const [form, setForm] = useState<Record<SlotKey, string>>(() => Object.fromEntries(SLOT_KEYS.map(key => [key, query[key]])) as Record<SlotKey, string>);
  useEffect(() => {
    const seed = overview.data?.seed;
    if (!seed) return;
    setForm(current => {
      const next = {...current};
      for (const [key, value] of Object.entries(seed)) if (!next[key as SlotKey] && value) next[key as SlotKey] = value;
      // Grade and syllabus come from the learner's record and must match it.
      if (context?.grade) next.grade = context.grade;
      if (context?.syllabus) next.syllabus = context.syllabus;
      return next;
    });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [overview.data?.seed, context?.leadId, context?.studentId]);
  useEffect(() => {
    setForm(current => ({...current, ...Object.fromEntries(SLOT_KEYS.filter(key => query[key]).map(key => [key, query[key]]))}));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [query.search, query.date, query.subject_id, query.teacher_q]);

  const [drawer, setDrawer] = useState<{config: ActionConfig; isOpen: boolean} | null>(null);
  const openDrawer = (config: ActionConfig) => setDrawer({config, isOpen: true});

  if (overview.error && !overview.data) {
    return (
      <Page title="Teacher matching">
        <PageError error={overview.error} onRetry={overview.reload} />
      </Page>
    );
  }
  const data = overview.data;
  const setField = (key: SlotKey) => (value: string | undefined | null) => setForm(current => ({...current, [key]: value ?? ''}));
  const chooseLearner = (patch: {lead_id?: string; student_id?: string}) => {
    // A new learner starts a new slot; the seed effect refills it from their record.
    setForm(Object.fromEntries(SLOT_KEYS.map(key => [key, key === 'sort' ? 'code' : ''])) as Record<SlotKey, string>);
    setQuery({lead_id: patch.lead_id ?? '', student_id: patch.student_id ?? '', demo_id: '', search: '', prepare: '', ...Object.fromEntries(SLOT_KEYS.map(key => [key, '']))}, {resetPage: false});
  };
  const findTeachers = () => setQuery({...form, search: '1', matches_page: '1'}, {resetPage: false});

  const matchError = matches.error instanceof ApiError ? Object.values(matches.error.fieldErrors).flat()[0] ?? matches.error.message : matches.error?.message;

  function bookingConfig(kind: 'demo' | 'regular', teacher: TeacherMatch, slot: Record<string, string>, weekday: string): ActionConfig {
    const fixed: Record<string, unknown> = {...learnerKey, teacher_id: teacher.id, reference: newReference(`allocation-${kind}`), ...slot};
    if (kind === 'demo') {
      return {
        action: businessAction(unitId, 'allocation-demo'),
        permission: 'allocation-demo',
        title: `Book demo with ${teacher.name}`,
        description: `${dayLabel(slot.date, {weekday: 'long', day: 'numeric', month: 'short'})} at ${slot.time} (${slot.timezone}), ${slot.duration_minutes} min, ${slot.medium}. The slot is rechecked when saved.`,
        submitLabel: 'Reserve demo slot',
        successMessage: 'Demo booked.',
        fields: [{kind: 'url', name: 'meet_link', label: 'Meeting link'}, ...confirmFields(false)],
        fixed,
        initial: {confirmation_channel: 'whatsapp'},
      };
    }
    const fields: ActionField[] = [];
    if (context?.leadId) fields.push({kind: 'select', name: 'demo_id', label: 'Successful demo', required: true, options: teacher.successfulDemos.map(d => ({value: String(d.id), label: d.label}))});
    fields.push(
      {kind: 'date', name: 'ends_on', label: 'Last date', required: true, min: slot.date, description: 'Within 366 days of the first class.'},
      {kind: 'money', name: 'student_rate_inr', label: 'Student hourly rate (INR)', required: true},
      {kind: 'money', name: 'teacher_rate_inr', label: 'Teacher hourly rate (INR)', required: true},
      ...confirmFields(true),
    );
    return {
      action: businessAction(unitId, 'allocation-regular'),
      permission: 'allocation-regular',
      title: `Allocate weekly class with ${teacher.name}`,
      description: `Repeats every ${weekday} at ${slot.time} (${slot.timezone}), starting ${slot.date}. Creates the assignment, the confirmed weekly slot and its dated lessons.`,
      submitLabel: 'Confirm and generate lessons',
      successMessage: 'Regular assignment confirmed and lessons created.',
      fields,
      fixed,
      initial: {confirmation_channel: 'whatsapp', demo_id: teacher.successfulDemos.length === 1 ? String(teacher.successfulDemos[0].id) : ''},
      confirmText: 'This creates a teaching assignment at these rates and books every weekly lesson until the last date.',
    };
  }

  const windowConfig = (party: 'teacher' | 'student', id: number, name: string, timezone: string): ActionConfig => ({
    action: businessAction(unitId, 'allocation-window'),
    permission: 'allocation-window',
    title: `Confirm ${name}'s availability`,
    description: 'A weekly window the person has confirmed. Imported free-text preferences do not count as availability.',
    submitLabel: 'Record availability',
    successMessage: 'Availability recorded.',
    fields: [
      {kind: 'select', name: 'weekday', label: 'Weekday', required: true, options: WEEKDAYS},
      {kind: 'time', name: 'start_time', label: 'From', required: true},
      {kind: 'time', name: 'end_time', label: 'Until', required: true},
      {kind: 'text', name: 'timezone', label: 'Timezone', required: true},
      {kind: 'date', name: 'starts_on', label: 'Valid from', required: true},
      {kind: 'date', name: 'ends_on', label: 'Valid through', required: true},
      {kind: 'textarea', name: 'note', label: 'Who confirmed and when', required: true},
    ],
    fixed: {[`${party}_id`]: id},
    initial: {timezone, weekday: '1'},
  });

  const qualifyConfig = (p: Prepared): ActionConfig => ({
    action: businessAction(unitId, 'allocation-qualify'),
    permission: 'allocation-qualify',
    title: `Confirm ${p.teacher.name}'s teaching details`,
    description: 'Adds a subject, class and syllabus eligibility and the teaching media you have verified.',
    submitLabel: 'Save confirmed details',
    successMessage: 'Teaching details saved.',
    fields: [
      {kind: 'select', name: 'subject_id', label: 'Subject', required: true, options: (data?.subjects ?? []).map(s => ({value: String(s.id), label: s.name}))},
      {kind: 'text', name: 'grade', label: 'Class / grade', required: true},
      {kind: 'text', name: 'syllabus', label: 'Syllabus', required: true},
      {kind: 'multi', name: 'mediums', label: 'Confirmed teaching media', required: true, options: data?.options.media ?? []},
      {kind: 'textarea', name: 'note', label: 'Verification evidence', required: true},
    ],
    fixed: {teacher_id: p.teacher.id},
    initial: {mediums: p.mediums, subject_id: form.subject_id, grade: form.grade, syllabus: form.syllabus},
  });

  const feedbackConfig = (demo: Demo): ActionConfig => ({
    action: businessAction(unitId, 'allocation-feedback'),
    permission: 'allocation-feedback',
    title: 'Record demo feedback',
    description: `${demo.teacherName ?? 'Teacher'} · ${demo.scheduledOn} ${demo.scheduledTime}. Continue enables a weekly allocation with the same teacher and subject.`,
    submitLabel: 'Record feedback',
    successMessage: 'Feedback recorded.',
    fields: [
      {kind: 'select', name: 'outcome', label: 'Parent preference', required: true, options: [
        {value: 'CONTINUE', label: 'Continue with this teacher'},
        {value: 'TRY_ANOTHER', label: 'Try another teacher'},
        {value: 'AWAITING_FEEDBACK', label: 'Awaiting feedback'},
        {value: 'NO_SHOW', label: 'Learner did not attend (no-show)'},
      ]},
      {kind: 'textarea', name: 'note', label: 'Feedback / attendance evidence', required: true},
    ],
    fixed: {demo_id: demo.id},
    initial: {outcome: 'CONTINUE'},
  });

  const selectOptions = (values: string[]) => values.map(value => ({value, label: value}));

  return (
    <Page
      title="Teacher matching"
      description="Choose a learner and a slot, compare teachers whose confirmed details fit, then book a demo or a weekly class."
      actions={unit?.pages.includes('teacher-recruitment') ? <Button label="Teacher hiring" variant="secondary" href={`/app/u/${unitId}/recruitment`} /> : undefined}>
      <Card padding={4}>
        <VStack gap={3}>
          <Heading level={2}>Learner</Heading>
          <Grid columns={{minWidth: 260, repeat: 'fit'}} gap={3}>
            <VStack gap={2}>
              <TextInput label="Find an enquiry" value={leadSearch} onChange={setLeadSearch} placeholder="Name, phone or admission code" />
              <Selector
                label="Enquiry"
                placeholder={data ? `Choose from ${data.leads.length} enquiries` : 'Loading…'}
                value={query.lead_id || undefined}
                onChange={value => value && chooseLearner({lead_id: value})}
                options={(data?.leads ?? []).map(lead => ({value: String(lead.id), label: `${lead.name} (#${lead.id})`}))}
                hasSearch
              />
            </VStack>
            <LookupField unitId={unitId} type="students" label="Or an existing student" value={query.student_id} onChange={id => id && chooseLearner({student_id: id})} />
          </Grid>
        </VStack>
      </Card>

      {!context && (data?.handoffs.length ?? 0) > 0 && (
        <Card padding={4}>
          <VStack gap={3}>
            <HStack gap={2} vAlign="center">
              <Heading level={2}>Ready to allocate</Heading>
              <Badge label={number(data?.handoffs.length)} />
            </HStack>
            <Text color="secondary">These enquiries had a successful demo and are waiting for a weekly class.</Text>
            <List hasDividers edge="flush">
              {data?.handoffs.map(row => (
                <ListItem key={row.demoId} label={row.leadName} description={`${row.when ?? ''} · ${row.teacherName ?? '—'} · ${row.subjectName ?? '—'}`} onClick={() => chooseLearner({lead_id: String(row.leadId)})} />
              ))}
            </List>
          </VStack>
        </Card>
      )}

      {overview.isLoading && <Skeleton height={120} width="100%" />}

      {context && data && (
        <Card padding={4}>
          <VStack gap={4}>
            <HStack gap={2} vAlign="center" wrap="wrap">
              <StackItem size="fill">
                <VStack gap={1}>
                  <Heading level={2}>{context.name}</Heading>
                  <Text color="secondary">{`${context.leadId ? 'Enquiry' : 'Student'} · ${context.grade || 'Confirm class'} · ${context.syllabus || 'Confirm syllabus'} · ${context.timezone}`}</Text>
                </VStack>
              </StackItem>
              {context.studentId && canAct(unit, 'allocation-window') && (
                <Button label="Confirm student availability" variant="secondary" onClick={() => openDrawer(windowConfig('student', context.studentId as number, context.name, context.timezone))} />
              )}
            </HStack>
            {data.handoff && (
              <MetadataList orientation="vertical" columns="multi" label={{position: 'top'}}>
                <MetadataListItem label="Learning needs">{data.handoff.learningRequirement || 'Not recorded'}</MetadataListItem>
                <MetadataListItem label="Parent concerns">{data.handoff.concern || 'Not recorded'}</MetadataListItem>
                <MetadataListItem label="About the student">{data.handoff.aboutStudent || 'Not recorded'}</MetadataListItem>
                <MetadataListItem label="Parent communication">{data.handoff.aboutParent || 'Not recorded'}</MetadataListItem>
              </MetadataList>
            )}
            <Heading level={3}>Slot</Heading>
            <Grid columns={{minWidth: 200, repeat: 'fill'}} gap={3}>
              <Selector label="Subject" isRequired value={form.subject_id || undefined} onChange={setField('subject_id')} options={data.subjects.map(s => ({value: String(s.id), label: s.name}))} hasSearch />
              <TextInput label="Class / grade" isRequired value={form.grade} onChange={setField('grade')} isReadOnly={Boolean(context.grade)} />
              <TextInput label="Syllabus" isRequired value={form.syllabus} onChange={setField('syllabus')} isReadOnly={Boolean(context.syllabus)} />
              <Selector label="Teaching medium" isRequired value={form.medium || undefined} onChange={setField('medium')} options={selectOptions(data.options.media)} />
              <DateInput label={context.leadId ? 'Demo / first class date' : 'First class date'} isRequired value={(form.date || undefined) as DateValue} onChange={setField('date')} />
              <TimeInput label="Start time" isRequired hourFormat="24h" value={(form.time || undefined) as TimeValue} onChange={value => setField('time')(value ?? '')} />
              <TextInput label="Timezone" isRequired value={form.timezone} onChange={setField('timezone')} placeholder="Asia/Kolkata" />
              <NumberInput label="Duration (minutes)" isRequired value={form.duration_minutes ? Number(form.duration_minutes) : null} onChange={value => setField('duration_minutes')(Number.isFinite(value) ? String(value) : '')} min={5} max={180} isIntegerOnly />
              <TextInput label="Teacher name / code" isOptional value={form.teacher_q} onChange={setField('teacher_q')} />
              <Selector label="Teacher level" hasClear placeholder="All levels" value={form.level || null} onChange={setField('level')} options={data.options.levels} />
              <Selector label="Teacher category" hasClear placeholder="All categories" value={form.category || null} onChange={setField('category')} options={selectOptions(data.options.categories)} />
              <Selector label="Order teachers by" value={form.sort || 'code'} onChange={setField('sort')} options={data.options.sorts} />
            </Grid>
            <HStack gap={3} vAlign="center" wrap="wrap">
              <CheckboxInput label="Include teachers needing eligibility verification" value={form.include_unverified === '1'} onChange={checked => setField('include_unverified')(checked ? '1' : '')} />
              <StackItem size="fill">{null}</StackItem>
              <Button label="Find teachers for this slot" variant="primary" onClick={findTeachers} />
            </HStack>
          </VStack>
        </Card>
      )}

      {context && query.search && (
        <Card padding={4}>
          <VStack gap={3}>
            <HStack gap={2} vAlign="center">
              <Heading level={2}>Teacher matches</Heading>
              {matches.data && <Badge label={number(matches.data.meta.total)} />}
            </HStack>
            <Text color="secondary">Availability is checked against confirmed windows, exceptions, weekly slots, lessons and demos. The slot is rechecked when you save.</Text>
            {matches.isRefreshing && <ProgressBar label="Updating" isIndeterminate />}
            {matchError && <Banner status="error" title="This slot cannot be matched" description={matchError} collapsible={false} />}
            {matches.isLoading && <Skeleton height={120} width="100%" />}
            {matches.data && !matches.error && (matches.data.data.length === 0 ? (
              <EmptyState title="No verified matches" description="Include teachers needing verification, or confirm a teacher's teaching details below." />
            ) : (
              <List hasDividers edge="flush">
                {matches.data.data.map(teacher => (
                  <ListItem
                    key={teacher.id}
                    align="first-line"
                    label={`${teacher.name} · ${teacher.code}`}
                    description={
                      <VStack gap={1}>
                        <Text size="sm" color="secondary">{[teacher.category, teacher.levels?.replace(/,/g, ', '), `${teacher.ttHours} timetable hrs/week`, `Verified: ${teacher.verifiedAt ?? 'not yet'}`].filter(Boolean).join(' · ')}</Text>
                        <Text size="sm">{`Their time: ${teacher.localTime} (${teacher.timezone}) · ${teacher.hours} h scheduled in the 7 days from this slot`}</Text>
                        <Text size="sm" color="secondary">{`Demos here: ${teacher.demoStats.booked} booked · ${teacher.demoStats.attended} attended · ${teacher.demoStats.allocated} allocated`}</Text>
                        {teacher.blockers.length > 0 ? teacher.blockers.map(blocker => <Text key={blocker} size="sm">{`• ${blocker}`}</Text>) : <Text size="sm">Confirmed details match this slot.</Text>}
                        {teacher.can.needsFeedback && <Text size="sm" color="secondary">Record Continue feedback after the demo before a weekly allocation.</Text>}
                      </VStack>
                    }
                    endContent={
                      <VStack gap={1}>
                        {teacher.can.demo && matches.data && <Button label="Book demo" size="sm" variant="primary" onClick={() => openDrawer(bookingConfig('demo', teacher, matches.data!.slot, matches.data!.weekday))} />}
                        {teacher.can.regular && matches.data && <Button label="Allocate weekly" size="sm" variant={teacher.can.demo ? 'secondary' : 'primary'} onClick={() => openDrawer(bookingConfig('regular', teacher, matches.data!.slot, matches.data!.weekday))} />}
                        {teacher.can.prepare && <Button label="Confirm details" size="sm" variant="secondary" onClick={() => setQuery({prepare: String(teacher.id)}, {resetPage: false})} />}
                      </VStack>
                    }
                  />
                ))}
              </List>
            ))}
            {matches.data && matches.data.meta.total > matches.data.meta.perPage && (
              <HStack hAlign="end">
                <Pagination page={matches.data.meta.page} onChange={page => setQuery({matches_page: String(page)}, {resetPage: false})} totalItems={matches.data.meta.total} pageSize={matches.data.meta.perPage} size="sm" label="Match pages" />
              </HStack>
            )}
          </VStack>
        </Card>
      )}

      {context && data && data.demos.length > 0 && (
        <Card padding={4}>
          <VStack gap={3}>
            <Heading level={2}>Demo feedback and handoff</Heading>
            <List hasDividers edge="flush">
              {data.demos.map(demo => (
                <ListItem
                  key={demo.id}
                  label={`${demo.teacherName ?? 'Teacher'} · ${demo.scheduledOn} ${demo.scheduledTime}`}
                  description={`${titleCase(demo.status.toLowerCase())} · ${demo.feedbackOutcome ? titleCase(demo.feedbackOutcome.toLowerCase()) : 'Feedback not recorded'}`}
                  endContent={
                    demo.next === 'timetable' ? (
                      <Button label="Open timetable" size="sm" variant="ghost" href={`/app/u/${unitId}/timetable`} />
                    ) : demo.next === 'allocate' && demo.allocate ? (
                      <Button label="Allocate weekly class" size="sm" variant="primary" onClick={() => setQuery({...demo.allocate, search: '1', matches_page: '1'}, {resetPage: false})} />
                    ) : demo.next === 'feedback' ? (
                      <Button label="Record feedback" size="sm" variant="secondary" onClick={() => openDrawer(feedbackConfig(demo))} />
                    ) : (
                      <Status value={demo.status.toLowerCase()} />
                    )
                  }
                />
              ))}
            </List>
          </VStack>
        </Card>
      )}

      {canAct(unit, 'allocation-qualify') && (
        <Card padding={4}>
          <VStack gap={3}>
            <Heading level={2}>Prepare a teacher for matching</Heading>
            <LookupField unitId={unitId} type="teachers" label="Teacher" value={query.prepare} onChange={id => setQuery({prepare: id}, {resetPage: false})} />
            {prepared.isLoading && <Skeleton height={80} width="100%" />}
            {prepared.error && <Banner status="error" title={prepared.error.message} collapsible={false} />}
            {prepared.data && (
              <VStack gap={3}>
                <HStack gap={2} vAlign="center" wrap="wrap">
                  <StackItem size="fill">
                    <VStack gap={1}>
                      <Heading level={3}>{`${prepared.data.teacher.name} (${prepared.data.teacher.code})`}</Heading>
                      <Text color="secondary">{`Status ${prepared.data.teacher.status} · ${prepared.data.teacher.timezone} · Media: ${prepared.data.mediums.join(', ') || 'none confirmed'}`}</Text>
                    </VStack>
                  </StackItem>
                  <Button label="Confirm teaching details" variant="primary" onClick={() => prepared.data && openDrawer(qualifyConfig(prepared.data))} />
                  {canAct(unit, 'allocation-window') && (
                    <Button label="Add availability" variant="secondary" onClick={() => prepared.data && openDrawer(windowConfig('teacher', prepared.data.teacher.id, prepared.data.teacher.name, prepared.data.teacher.timezone))} />
                  )}
                </HStack>
                <Text weight="semibold">Eligibility</Text>
                {prepared.data.eligibilities.length === 0 ? <Text color="secondary">None confirmed.</Text> : prepared.data.eligibilities.map(e => <Text key={`${e.subject}-${e.grade}-${e.syllabus}`}>{`${e.subject} · ${e.grade} · ${e.syllabus}`}</Text>)}
                <Text weight="semibold">Confirmed availability windows</Text>
                {prepared.data.windows.length === 0 ? (
                  <Text color="secondary">No structured availability recorded.</Text>
                ) : (
                  prepared.data.windows.map(w => <Text key={w.id}>{`${w.weekday} ${w.startTime}–${w.endTime} · ${w.timezone} · ${w.startsOn} to ${w.endsOn ?? 'ongoing'}`}</Text>)
                )}
              </VStack>
            )}
          </VStack>
        </Card>
      )}

      {drawer && (
        <ActionDrawer
          isOpen={drawer.isOpen}
          onOpenChange={isOpen => setDrawer(current => (current ? {...current, isOpen} : null))}
          unitId={unitId}
          title={drawer.config.title}
          description={drawer.config.description}
          fields={drawer.config.fields}
          fixed={drawer.config.fixed}
          initial={drawer.config.initial}
          action={drawer.config.action}
          submitLabel={drawer.config.submitLabel}
          successMessage={drawer.config.successMessage}
          confirmText={drawer.config.confirmText}
          onSaved={() => {
            overview.reload();
            matches.reload();
            prepared.reload();
          }}
        />
      )}
    </Page>
  );
}
