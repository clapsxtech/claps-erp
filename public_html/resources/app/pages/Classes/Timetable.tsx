import {useEffect, useState} from 'react';
import {useParams} from 'react-router';
import {VStack, HStack, StackItem} from '@astryxdesign/core/Layout';
import {Grid} from '@astryxdesign/core/Grid';
import {Card} from '@astryxdesign/core/Card';
import {ClickableCard} from '@astryxdesign/core/ClickableCard';
import {Heading, Text} from '@astryxdesign/core/Text';
import {Button} from '@astryxdesign/core/Button';
import {Icon} from '@astryxdesign/core/Icon';
import {TextInput} from '@astryxdesign/core/TextInput';
import {Selector} from '@astryxdesign/core/Selector';
import {DateInput} from '@astryxdesign/core/DateInput';
import {DropdownMenu} from '@astryxdesign/core/DropdownMenu';
import {List, ListItem} from '@astryxdesign/core/List';
import {Banner} from '@astryxdesign/core/Banner';
import {EmptyState} from '@astryxdesign/core/EmptyState';
import {Skeleton} from '@astryxdesign/core/Skeleton';
import {ProgressBar} from '@astryxdesign/core/ProgressBar';
import {Drawer, DrawerHeader} from '@astryxdesign/core/Drawer';
import {MetadataList, MetadataListItem} from '@astryxdesign/core/MetadataList';
import {Table} from '@astryxdesign/core/Table';
import {TabList, Tab} from '@astryxdesign/core/TabList';
import {ChevronLeftIcon, ChevronRightIcon, PlusIcon} from '@heroicons/react/24/outline';
import type {ComponentProps} from 'react';
import {Page, PageError} from '../../components/PageState';
import {Status} from '../../components/Status';
import {useDebounced, useIsPhone, useListQuery, useResource} from '../../lib/hooks';
import {number, titleCase} from '../../lib/format';
import {unitById} from '../../lib/session';
import {ActionDrawer} from './ActionDrawer';
import {lessonAction, ACTION_PERMISSION, type ActionConfig, type LessonActionKey, type LessonContext} from './lessonActions';
import {canAct, dayLabel} from './shared';
import {planningAction, PLANNING_LABELS, type AgreementContext, type PlanningActionKey} from './planningActions';

type DateValue = ComponentProps<typeof DateInput>['value'];

interface Lesson extends LessonContext {
  studentCode: string;
  teacherCode: string;
  status: string;
  kind: string;
  endTime: string;
  endDate: string;
  utcOffset: string;
  hasEnded: boolean;
  cancellationReason: string | null;
}

interface Week {
  week: {start: string; end: string; timezone: string; today: string; prevDate: string | null; nextDate: string | null; count: number; truncated: boolean};
  days: {date: string; lessons: Lesson[]}[];
}

interface Plans {
  agreements: {data: Record<string, string | number | null>[]; meta: {page: number; total: number}};
  exceptions: {data: Record<string, string | number | null>[]; meta: {page: number; total: number}};
  can: {openPlanning: boolean};
}

interface Availability {
  data: {id: number; party: string; who: string | null; weekday: string; startTime: string; endTime: string; timezone: string; startsOn: string; endsOn: string | null}[];
  meta: {page: number; perPage: number; total: number};
}

const DEFAULTS = {date: '', student: '', teacher: '', status: ''};
const STATUSES = ['scheduled', 'completed', 'cancelled', 'rescheduled', 'no_show'].map(value => ({value, label: titleCase(value)}));

function lessonLine(lesson: Lesson): string {
  return `${lesson.startTime}–${lesson.endTime} · ${lesson.subjectName} · ${lesson.teacherName}`;
}

/** Which lesson actions the timetable offers for this lesson and role. */
function lessonActions(lesson: Lesson, allowed: (action: string) => boolean): LessonActionKey[] {
  const keys: LessonActionKey[] = [];
  if (lesson.status === 'scheduled') {
    keys.push('reschedule', 'cancel');
    if (lesson.hasEnded) keys.push('attendance', 'no-show');
  }
  keys.push('extra', 'exception-student', 'exception-teacher', 'switch-teacher', 'pause-classes', 'stop-classes');
  return keys.filter(key => allowed(ACTION_PERMISSION[key]));
}

const ACTION_LABELS: Record<LessonActionKey, string> = {
  reschedule: 'Reschedule',
  cancel: 'Cancel without charge',
  attendance: 'Record attended minutes',
  'no-show': 'Record no-show',
  extra: 'Schedule extra or demo class',
  'exception-student': 'Student unavailable',
  'exception-teacher': 'Teacher unavailable',
  'switch-teacher': 'Change teacher',
  'pause-classes': "Pause student's classes",
  'stop-classes': "Stop student's classes",
};

function statusNote(lesson: Lesson): string | null {
  if (lesson.status === 'cancelled') return 'Cancelled lessons stay visible for history. No attendance or charge.';
  if (lesson.status === 'rescheduled') return 'Original time kept for history; the replacement is a separate lesson.';
  if (lesson.status === 'no_show') return `Student didn't attend${lesson.cancellationReason ? `: ${lesson.cancellationReason}` : ''}. No charge and no teacher pay.`;
  if (lesson.kind === 'demo') return 'Demo: free for the student, teacher unpaid.';
  return null;
}

export default function Timetable() {
  const unitId = Number(useParams().unit);
  const unit = unitById(unitId);
  const isPhone = useIsPhone();
  const [query, setQuery] = useListQuery(DEFAULTS);
  const [student, setStudent] = useState(query.student);
  const [teacher, setTeacher] = useState(query.teacher);
  const debouncedStudent = useDebounced(student);
  const debouncedTeacher = useDebounced(teacher);
  useEffect(() => {
    if (debouncedStudent !== query.student || debouncedTeacher !== query.teacher) setQuery({student: debouncedStudent, teacher: debouncedTeacher}, {resetPage: false});
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debouncedStudent, debouncedTeacher]);

  const week = useResource<Week>(`/app-api/units/${unitId}/timetable`, query);
  const [tab, setTab] = useState('week');
  const plans = useResource<Plans>(tab === 'plans' ? `/app-api/units/${unitId}/timetable/plans` : null);
  const availability = useResource<Availability>(tab === 'plans' ? `/app-api/units/${unitId}/timetable/availability` : null);
  const [selected, setSelected] = useState<Lesson | null>(null);
  const [drawer, setDrawer] = useState<{config: ActionConfig; isOpen: boolean} | null>(null);
  const timezone = week.data?.week.timezone ?? unit?.timezone ?? 'Asia/Kolkata';
  const allowed = (action: string) => canAct(unit, action);

  const openPlanning = (key: PlanningActionKey, agreement?: AgreementContext) => setDrawer({config: planningAction(key, unitId, timezone, agreement), isOpen: true});

  const open = (key: LessonActionKey, lesson?: Lesson) => {
    setSelected(null);
    setDrawer({config: lessonAction(key, unitId, timezone, lesson), isOpen: true});
  };

  if (week.error && !week.data) {
    return (
      <Page title="Timetable">
        <PageError error={week.error} onRetry={week.reload} />
      </Page>
    );
  }

  const w = week.data?.week;
  const hasFilters = Boolean(query.student || query.teacher || query.status);
  const headerActions = (['pause-classes', 'stop-classes', 'switch-teacher', 'exception-student', 'exception-teacher'] as LessonActionKey[]).filter(key => allowed(ACTION_PERMISSION[key]));

  const lessonCard = (lesson: Lesson, date: string) => (
    <ClickableCard key={`${date}-${lesson.id}`} padding={2} variant={lesson.status === 'scheduled' ? 'default' : 'muted'} onClick={() => setSelected(lesson)} label={`${lesson.studentName}, ${lessonLine(lesson)}`}>
      <VStack gap={1}>
        <Text weight="semibold" hasTabularNumbers>{`${lesson.startTime}–${lesson.endTime}`}</Text>
        <Text maxLines={1}>{lesson.studentName}</Text>
        <Text size="sm" color="secondary" maxLines={2}>{`${lesson.subjectName} · ${lesson.teacherName}`}</Text>
        <Status value={lesson.status} label={lesson.kind === 'regular' ? undefined : `${titleCase(lesson.status)} · ${lesson.kind}`} />
      </VStack>
    </ClickableCard>
  );

  let body;
  if (week.isLoading || !week.data) {
    body = (
      <VStack gap={2}>
        {Array.from({length: 5}, (_, i) => <Skeleton key={i} height={48} width="100%" />)}
      </VStack>
    );
  } else if (isPhone) {
    body = (
      <VStack gap={4}>
        {week.data.days.map(day => (
          <VStack key={day.date} gap={2}>
            <Heading level={3}>{`${dayLabel(day.date)}${day.date === w?.today ? ' · Today' : ''}`}</Heading>
            {day.lessons.length === 0 ? (
              <Text color="secondary" size="sm">No lessons</Text>
            ) : (
              <List hasDividers edge="flush">
                {day.lessons.map(lesson => (
                  <ListItem
                    key={`${day.date}-${lesson.id}`}
                    label={`${lesson.startTime} · ${lesson.studentName}`}
                    description={`${lesson.subjectName} · ${lesson.teacherName}`}
                    endContent={<Status value={lesson.status} />}
                    onClick={() => setSelected(lesson)}
                  />
                ))}
              </List>
            )}
          </VStack>
        ))}
      </VStack>
    );
  } else {
    body = (
      <Grid columns={7} gap={2} align="start">
        {week.data.days.map(day => (
          <Card key={day.date} padding={2} variant={day.date === w?.today ? 'blue' : 'default'}>
            <VStack gap={2}>
              <Text weight="semibold">{dayLabel(day.date)}</Text>
              {day.lessons.length === 0 ? <Text color="secondary" size="sm">—</Text> : day.lessons.map(lesson => lessonCard(lesson, day.date))}
            </VStack>
          </Card>
        ))}
      </Grid>
    );
  }

  const selectedActions = selected ? lessonActions(selected, allowed) : [];

  return (
    <Page
      title="Timetable"
      description={w ? `${dayLabel(w.start, {day: 'numeric', month: 'short'})} – ${dayLabel(w.end, {day: 'numeric', month: 'short', year: 'numeric'})} · ${number(w.count)} lessons · times in ${w.timezone}` : undefined}
      actions={
        <>
          {allowed('extra') && <Button label="Extra class" variant="primary" icon={<Icon icon={PlusIcon} size="sm" />} onClick={() => open('extra')} />}
          {headerActions.length > 0 && (
            <DropdownMenu button={{label: 'More', variant: 'secondary'}} items={headerActions.map(key => ({id: key, label: ACTION_LABELS[key], onClick: () => open(key)}))} />
          )}
        </>
      }>
      <TabList value={tab} onChange={setTab}>
        <Tab value="week" label="Week" />
        <Tab value="plans" label="Weekly slots & unavailability" />
      </TabList>
      {tab === 'week' && (
        <Card padding={4}>
          <VStack gap={3}>
            <HStack gap={2} vAlign="end" wrap="wrap">
              <HStack gap={1} vAlign="center">
                <Button label="Previous week" isIconOnly variant="secondary" icon={<Icon icon={ChevronLeftIcon} size="sm" />} isDisabled={!w?.prevDate} onClick={() => w?.prevDate && setQuery({date: w.prevDate}, {resetPage: false})} />
                <Button label="Today" variant="secondary" onClick={() => setQuery({date: ''}, {resetPage: false})} />
                <Button label="Next week" isIconOnly variant="secondary" icon={<Icon icon={ChevronRightIcon} size="sm" />} isDisabled={!w?.nextDate} onClick={() => w?.nextDate && setQuery({date: w.nextDate}, {resetPage: false})} />
              </HStack>
              <DateInput label="Any date in week" isLabelHidden value={(query.date || undefined) as DateValue} onChange={value => setQuery({date: value ?? ''}, {resetPage: false})} min={'2000-01-03' as DateValue} max={'2099-12-27' as DateValue} />
              <StackItem size="fill">
                <TextInput label="Student" isLabelHidden placeholder="Student name or code" value={student} onChange={setStudent} width="100%" />
              </StackItem>
              <StackItem size="fill">
                <TextInput label="Teacher" isLabelHidden placeholder="Teacher name or code" value={teacher} onChange={setTeacher} width="100%" />
              </StackItem>
              <Selector label="Status" isLabelHidden placeholder="Any status" hasClear value={query.status || null} onChange={value => setQuery({status: value ?? ''}, {resetPage: false})} options={STATUSES} />
              {hasFilters && (
                <Button
                  label="Clear filters"
                  variant="ghost"
                  onClick={() => {
                    setStudent('');
                    setTeacher('');
                    setQuery({student: '', teacher: '', status: ''}, {resetPage: false});
                  }}
                />
              )}
            </HStack>
            {week.isRefreshing && <ProgressBar label="Updating" isIndeterminate />}
            {w?.truncated && (
              <Banner status="warning" title="Showing only the first 250 matching lessons" description="This week is incomplete. Narrow the student, teacher or status filters to see the rest." collapsible={false} />
            )}
            {w && w.count === 0 && !week.isRefreshing ? (
              <EmptyState
                title={hasFilters ? 'No matching lessons this week' : 'No lessons this week'}
                description="Only dated lessons appear here. Confirmed weekly slots need their lessons generated first."
              />
            ) : (
              body
            )}
          </VStack>
        </Card>
      )}
      {tab === 'plans' && (
        <PlansView
          plans={plans.data}
          availability={availability.data}
          error={plans.error ?? availability.error}
          isLoading={plans.isLoading || availability.isLoading}
          allowed={allowed}
          isPhone={isPhone}
          onAction={openPlanning}
        />
      )}

      <Drawer isOpen={selected !== null} onOpenChange={isOpen => !isOpen && setSelected(null)} label="Lesson" width={440} padding={5} experimental={{isFullWidthOnMobile: true}}>
        {selected && (
          <VStack gap={4}>
            <DrawerHeader title={selected.studentName} subtitle={`Lesson #${selected.id}`} onOpenChange={isOpen => !isOpen && setSelected(null)} />
            <MetadataList orientation="horizontal">
              <MetadataListItem label="When">{`${dayLabel(selected.localDate, {weekday: 'long', day: 'numeric', month: 'short'})} · ${selected.startTime}–${selected.endTime} (UTC${selected.utcOffset})`}</MetadataListItem>
              <MetadataListItem label="Subject">{selected.subjectName}</MetadataListItem>
              <MetadataListItem label="Teacher">{`${selected.teacherName} · ${selected.teacherCode}`}</MetadataListItem>
              <MetadataListItem label="Student code">{selected.studentCode}</MetadataListItem>
              <MetadataListItem label="Kind">{titleCase(selected.kind)}</MetadataListItem>
              <MetadataListItem label="Status"><Status value={selected.status} /></MetadataListItem>
            </MetadataList>
            {statusNote(selected) && <Text color="secondary">{statusNote(selected)}</Text>}
            {selected.status === 'completed' && <Button label="Review attendance" variant="secondary" href={`/app/u/${unitId}/attendance`} />}
            {selectedActions.length > 0 ? (
              <VStack gap={2}>
                {selectedActions.map((key, index) => (
                  <Button key={key} label={ACTION_LABELS[key]} variant={index === 0 ? 'primary' : 'secondary'} width="100%" onClick={() => open(key, selected)} />
                ))}
              </VStack>
            ) : (
              <Text color="secondary">View only for your current access.</Text>
            )}
          </VStack>
        )}
      </Drawer>

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
          alwaysSend={drawer.config.alwaysSend}
          action={drawer.config.action}
          submitLabel={drawer.config.submitLabel}
          successMessage={drawer.config.successMessage}
          confirmText={drawer.config.confirmText}
          isDestructive={drawer.config.isDestructive}
          onSaved={() => {
            week.reload();
            if (tab === 'plans') {
              plans.reload();
              availability.reload();
            }
          }}
        />
      )}
    </Page>
  );
}

function PlansView({
  plans,
  availability,
  error,
  isLoading,
  allowed,
  isPhone,
  onAction,
}: {
  plans: Plans | undefined;
  availability: Availability | undefined;
  error: Error | undefined;
  isLoading: boolean;
  allowed: (action: string) => boolean;
  isPhone: boolean;
  onAction: (key: PlanningActionKey, agreement?: AgreementContext) => void;
}) {
  if (error && !plans) return <PageError error={error} />;
  if (isLoading || !plans) return <Skeleton height={120} width="100%" />;
  type Row = Record<string, string | number | null>;
  const cell = (value: unknown) => <Text>{value === null || value === undefined || value === '' ? '—' : String(value)}</Text>;
  const context = (r: Row): AgreementContext => ({
    id: Number(r.id),
    label: `${r.studentName} · ${r.subjectName} with ${r.teacherName} · ${r.weekday} ${r.startTime}`,
    startsOn: String(r.startsOn),
    endsOn: r.endsOn ? String(r.endsOn) : null,
  });
  /** Which planning steps apply to a slot: confirm a proposal; generate or end a confirmed slot. */
  const rowActions = (r: Row): PlanningActionKey[] => {
    const keys: PlanningActionKey[] = r.status === 'proposed' ? ['confirm'] : r.status === 'confirmed' ? ['materialize', 'end-agreement'] : [];
    return keys.filter(key => allowed(key));
  };
  const headerKeys = (['availability', 'propose'] as PlanningActionKey[]).filter(key => allowed(key));
  // The same steps for any slot (the table shows the newest 25): pick the slot in the form.
  const slotKeys = (['confirm', 'materialize', 'end-agreement'] as PlanningActionKey[]).filter(key => allowed(key));
  const rowMenu = (r: Row) => {
    const keys = rowActions(r);
    if (keys.length === 0) return <Text> </Text>;
    if (keys.length === 1) return <Button label={PLANNING_LABELS[keys[0]]} size="sm" variant="secondary" onClick={() => onAction(keys[0], context(r))} />;
    return <DropdownMenu button={{label: 'Actions', variant: 'secondary', size: 'sm'}} items={keys.map(key => ({id: key, label: PLANNING_LABELS[key], onClick: () => onAction(key, context(r))}))} />;
  };
  return (
    <VStack gap={4}>
      {headerKeys.length + slotKeys.length > 0 && (
        <HStack gap={2} wrap="wrap">
          {headerKeys.map((key, index) => (
            <Button key={key} label={PLANNING_LABELS[key]} variant={index === headerKeys.length - 1 ? 'primary' : 'secondary'} icon={<Icon icon={PlusIcon} size="sm" />} onClick={() => onAction(key)} />
          ))}
          {slotKeys.length > 0 && (
            <DropdownMenu button={{label: 'Any slot', variant: 'secondary'}} items={slotKeys.map(key => ({id: key, label: `${PLANNING_LABELS[key]}…`, onClick: () => onAction(key)}))} />
          )}
        </HStack>
      )}
      <Card padding={4}>
        <VStack gap={3}>
          <Heading level={2}>{`Weekly slots (${number(plans.agreements.meta.total)})`}</Heading>
          <Text color="secondary" size="sm">Propose a slot from both sides’ availability, confirm it once the teacher and family agree, then generate its dated lessons.</Text>
          {plans.agreements.data.length === 0 ? (
            <EmptyState title="No weekly slots yet" isCompact />
          ) : isPhone ? (
            <VStack gap={3}>
              {plans.agreements.data.map(r => (
                <VStack key={String(r.id)} gap={1}>
                  <Text weight="semibold">{`${r.studentName} · ${r.weekday} ${r.startTime} · ${r.durationMinutes} min`}</Text>
                  <Text size="sm" color="secondary">{`${r.subjectName} with ${r.teacherName} · ${r.startsOn} – ${r.endsOn ?? 'ongoing'} · ${r.timezone}`}</Text>
                  <HStack gap={2} vAlign="center" hAlign="between">
                    <Status value={String(r.status)} />
                    {rowMenu(r)}
                  </HStack>
                </VStack>
              ))}
            </VStack>
          ) : (
            <Table<Row>
              data={plans.agreements.data}
              idKey={row => Number(row.id)}
              textOverflow="wrap"
              columns={[
                {key: 'studentName', header: 'Student', renderCell: r => cell(r.studentName)},
                {key: 'subjectName', header: 'Subject · teacher', renderCell: r => cell(`${r.subjectName} · ${r.teacherName}`)},
                {key: 'weekday', header: 'Slot', renderCell: r => cell(`${r.weekday} ${r.startTime} · ${r.durationMinutes} min · ${r.timezone}`)},
                {key: 'startsOn', header: 'From – until', renderCell: r => cell(`${r.startsOn} – ${r.endsOn ?? 'ongoing'}`)},
                {key: 'status', header: 'Status', renderCell: r => <Status value={String(r.status)} />},
                {key: 'id', header: '', align: 'end', renderCell: rowMenu},
              ]}
            />
          )}
        </VStack>
      </Card>
      <Card padding={4}>
        <VStack gap={3}>
          <Heading level={2}>{`Weekly availability (${number(availability?.meta.total ?? 0)})`}</Heading>
          {!availability || availability.data.length === 0 ? (
            <EmptyState title="No availability recorded" isCompact />
          ) : isPhone ? (
            <List hasDividers edge="flush">
              {availability.data.map(r => (
                <ListItem key={r.id} label={`${r.who ?? '—'} (${r.party})`} description={`${r.weekday} ${r.startTime}–${r.endTime} ${r.timezone} · ${r.startsOn} – ${r.endsOn ?? 'ongoing'}`} />
              ))}
            </List>
          ) : (
            <Table<Row>
              data={availability.data as unknown as Row[]}
              idKey={row => Number(row.id)}
              textOverflow="truncate"
              columns={[
                {key: 'who', header: 'Who', renderCell: r => cell(`${r.who ?? '—'} (${r.party})`)},
                {key: 'weekday', header: 'Weekday', renderCell: r => cell(r.weekday)},
                {key: 'startTime', header: 'Time', renderCell: r => cell(`${r.startTime}–${r.endTime} ${r.timezone}`)},
                {key: 'startsOn', header: 'From – until', renderCell: r => cell(`${r.startsOn} – ${r.endsOn ?? 'ongoing'}`)},
              ]}
            />
          )}
        </VStack>
      </Card>
      <Card padding={4}>
        <VStack gap={3}>
          <Heading level={2}>{`Unavailability (${number(plans.exceptions.meta.total)})`}</Heading>
          {plans.exceptions.data.length === 0 ? (
            <EmptyState title="No unavailability recorded" isCompact />
          ) : (
            <Table<Row>
              data={plans.exceptions.data}
              idKey={row => Number(row.id)}
              textOverflow="truncate"
              columns={[
                {key: 'date', header: 'Date', renderCell: r => cell(r.date)},
                {key: 'interval', header: 'Time', renderCell: r => cell(r.interval)},
                {key: 'who', header: 'Who', renderCell: r => cell(`${r.who ?? '—'} (${r.party})`)},
                {key: 'reason', header: 'Reason', renderCell: r => cell(r.reason)},
              ]}
            />
          )}
        </VStack>
      </Card>
    </VStack>
  );
}
