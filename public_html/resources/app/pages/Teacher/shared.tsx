// Shared pieces of the teacher portal: the session shape, the one workspace
// read (filters live in the URL), lesson cards with one-tap attendance, and the
// date filter bar. Writes post to the /erp/teacher/* routes, which keep
// their validation, ownership checks and duplicate safety.
import {useState, type ComponentProps, type ReactNode} from 'react';
import {useSearchParams} from 'react-router';
import {VStack, HStack, StackItem} from '@astryxdesign/core/Layout';
import {Card} from '@astryxdesign/core/Card';
import {Grid} from '@astryxdesign/core/Grid';
import {Heading, Text} from '@astryxdesign/core/Text';
import {Button} from '@astryxdesign/core/Button';
import {Banner} from '@astryxdesign/core/Banner';
import {DateInput} from '@astryxdesign/core/DateInput';
import {DateTimeInput, type ISODateTimeString} from '@astryxdesign/core/DateTimeInput';
import {Selector} from '@astryxdesign/core/Selector';
import {NumberInput} from '@astryxdesign/core/NumberInput';
import {TextInput} from '@astryxdesign/core/TextInput';
import {Drawer, DrawerHeader} from '@astryxdesign/core/Drawer';
import {useToast} from '@astryxdesign/core/Toast';
import {ApiError, postJson} from '../../lib/api';
import {useResource, type Resource} from '../../lib/hooks';
import {readSession} from '../../lib/session';
import {Status} from '../../components/Status';
import {titleCase} from '../../lib/format';

export interface TeacherSession {
  kind: 'teacher';
  mode: 'portal' | 'setup' | 'refused';
  user: {name: string; initials: string};
  teacher?: {name: string; code: string; timezone: string};
  unitName?: string;
  message?: string;
  isAdmin?: boolean;
  units?: {id: number; name: string; canOpenTeamAccess: boolean; canOpenPeople: boolean}[];
}

export function teacherSession(): TeacherSession {
  return readSession<TeacherSession>();
}

export interface Lesson {
  id: number;
  date: string;
  startTime: string;
  endTime: string;
  startsAtLocal: string;
  scheduledMinutes: number;
  status: string;
  kind: string;
  studentName: string;
  studentCode: string;
  subjectName: string;
  hasEnded: boolean;
  isUpcoming: boolean;
  needsAttendance: boolean;
  canMarkNoShow: boolean;
  attendance: {status: string; minutes: number} | null;
}

export interface Assignment {
  id: number;
  studentId: number;
  studentName: string;
  studentCode: string;
  grade: string | null;
  syllabus: string | null;
  subjectName: string;
  learningRequirement: string | null;
  concern: string | null;
  aboutStudent: string | null;
}

export interface Result {
  id: number;
  assessment: string;
  assessedOn: string;
  score: number;
  maximum: number;
  notes: string | null;
  studentName: string;
  subjectName: string;
}

export interface Workspace {
  range: {from: string; through: string; today: string; timezone: string; truncated: boolean; student: number | null};
  lessons: Lesson[];
  assignments: Assignment[];
  students: {id: number; name: string; code: string}[];
  results: {data: Result[]; meta: {page: number; perPage: number; total: number; lastPage: number}};
}

const FILTER_KEYS = ['from', 'through', 'student', 'results_page'] as const;

/** The one JSON read behind every section. Filters come from the URL so links and refresh keep them. */
export function useWorkspace(): Resource<Workspace> {
  const [params] = useSearchParams();
  const query: Record<string, string> = {};
  for (const key of FILTER_KEYS) {
    const value = params.get(key);
    if (value) query[key] = value;
  }
  return useResource<Workspace>('/teacher-api/workspace', query);
}

/** Leaves the portal with a full page load, so the other area gets its own session. */
export function leave(href: string) {
  window.location.assign(href);
}

export function signOut() {
  const form = document.createElement('form');
  form.method = 'post';
  form.action = '/logout';
  const token = document.createElement('input');
  token.type = 'hidden';
  token.name = '_token';
  token.value = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
  form.appendChild(token);
  document.body.appendChild(form);
  form.submit();
}

export function dayLabel(iso: string, today: string): string {
  if (iso === today) return 'Today';
  const d = new Date(`${iso}T00:00:00`);
  const t = new Date(`${today}T00:00:00`);
  const diff = Math.round((d.getTime() - t.getTime()) / 86400000);
  if (diff === 1) return 'Tomorrow';
  if (diff === -1) return 'Yesterday';
  return d.toLocaleDateString('en-IN', {weekday: 'short', day: '2-digit', month: 'short'});
}

export function longDate(iso: string): string {
  const d = new Date(`${iso}T00:00:00`);
  return d.toLocaleDateString('en-IN', {weekday: 'long', day: 'numeric', month: 'long'});
}

export function shortDate(iso: string): string {
  const d = new Date(`${iso}T00:00:00`);
  return d.toLocaleDateString('en-IN', {day: '2-digit', month: 'short', year: 'numeric'});
}

/** Section frame: title, a line of context and the content. Narrow on desktop, edge to edge on phones. */
export function Section({title, description, actions, children, header}: {title: string; description?: ReactNode; actions?: ReactNode; children: ReactNode; /** Replaces the title row; must contain the h1. */ header?: ReactNode}) {
  return (
    <VStack hAlign="center">
    <VStack padding={4} gap={5} width="100%" maxWidth={960}>
      {header ?? (
      <HStack gap={3} vAlign="center" wrap="wrap">
        <StackItem size="fill">
          <VStack gap={1}>
            <Heading level={1}>{title}</Heading>
            {description && <Text color="secondary">{description}</Text>}
          </VStack>
        </StackItem>
        {actions}
      </HStack>
      )}
      {children}
    </VStack>
    </VStack>
  );
}

export function SubHeading({title, note}: {title: string; note?: string}) {
  return (
    <VStack gap={0.5}>
      <Heading level={2}>{title}</Heading>
      {note && <Text color="secondary" size="sm">{note}</Text>}
    </VStack>
  );
}

export function Tile({label, value, hint}: {label: string; value: string; hint?: string}) {
  return (
    <Card padding={3}>
      <VStack gap={1}>
        <Text color="secondary" size="sm">{label}</Text>
        <Text size="2xl" weight="semibold" hasTabularNumbers>{value}</Text>
        {hint && <Text color="secondary" size="sm">{hint}</Text>}
      </VStack>
    </Card>
  );
}

/** The four dashboard figures, computed over the loaded date range. */
export function Metrics({data}: {data: Workspace}) {
  const pending = data.lessons.filter(lesson => lesson.needsAttendance).length;
  const minutes = data.lessons.reduce((sum, lesson) => sum + (lesson.attendance && lesson.attendance.status !== 'rejected' ? lesson.attendance.minutes : 0), 0);
  return (
    <Grid columns={{minWidth: 150, repeat: 'fill'}} gap={3}>
      <Tile label="Active assignments" value={String(data.assignments.length)} />
      <Tile label="Attendance to submit" value={`${pending}${data.range.truncated ? '+' : ''}`} />
      <Tile label="Recorded teaching" value={`${(minutes / 60).toFixed(2)} h`} hint="Rejected attendance excluded" />
      <Tile label="Assessment results" value={String(data.results.meta.total)} />
    </Grid>
  );
}

export function RangeNote({data}: {data: Workspace}) {
  return (
    <Text color="secondary" size="sm">
      {`Covers ${shortDate(data.range.from)} to ${shortDate(data.range.through)} (${data.range.timezone})${data.range.truncated ? ', limited to the first 500 lessons' : ''}.`}
    </Text>
  );
}

type DateValue = ComponentProps<typeof DateInput>['value'];

/** Date range and student filter. Applies to the URL; Reset returns to the default range. */
export function Filters({data}: {data: Workspace | undefined}) {
  const [params, setParams] = useSearchParams();
  const [isOpen, setOpen] = useState(Boolean(params.get('from') || params.get('through') || params.get('student')));
  const [from, setFrom] = useState(params.get('from') ?? data?.range.from ?? '');
  const [through, setThrough] = useState(params.get('through') ?? data?.range.through ?? '');
  const [student, setStudent] = useState(params.get('student') ?? '');
  const summary = data ? `${shortDate(data.range.from)} – ${shortDate(data.range.through)}` : '';
  const studentName = data?.students.find(s => String(s.id) === params.get('student'))?.name;

  const apply = () => {
    setParams(current => {
      const next = new URLSearchParams(current);
      for (const [key, value] of [['from', from], ['through', through], ['student', student]] as const) {
        if (value) next.set(key, value);
        else next.delete(key);
      }
      next.delete('results_page');
      return next;
    });
  };
  const reset = () => {
    setFrom('');
    setThrough('');
    setStudent('');
    setOpen(false);
    setParams(current => {
      const next = new URLSearchParams(current);
      for (const key of FILTER_KEYS) next.delete(key);
      return next;
    });
  };

  if (!isOpen) {
    return (
      <HStack gap={2} vAlign="center" wrap="wrap">
        <Text color="secondary" size="sm">{`${summary}${studentName ? ` · ${studentName}` : ''}`}</Text>
        <Button label="Change dates" size="sm" variant="secondary" onClick={() => {
          setFrom(from || data?.range.from || '');
          setThrough(through || data?.range.through || '');
          setOpen(true);
        }} />
      </HStack>
    );
  }
  return (
    <Card padding={3} variant="muted">
      <VStack gap={3}>
        <Grid columns={{minWidth: 180, repeat: 'fill'}} gap={3}>
          <DateInput label="From" value={(from || undefined) as DateValue} onChange={value => setFrom(value ?? '')} />
          <DateInput label="Through" value={(through || undefined) as DateValue} onChange={value => setThrough(value ?? '')} />
          <Selector
            label="Student"
            options={[{value: '', label: 'All my students'}, ...(data?.students ?? []).map(s => ({value: String(s.id), label: `${s.name} · ${s.code}`}))]}
            value={student}
            onChange={value => setStudent(value ?? '')}
            hasSearch={(data?.students.length ?? 0) > 8}
          />
        </Grid>
        <HStack gap={2} wrap="wrap">
          <Button label="Apply filters" variant="primary" onClick={apply} />
          <Button label="Reset" variant="ghost" onClick={reset} />
          <Button label="Close" variant="ghost" onClick={() => setOpen(false)} />
        </HStack>
      </VStack>
    </Card>
  );
}

function messageFor(reason: unknown): string {
  if (reason instanceof ApiError) {
    const fields = Object.values(reason.fieldErrors).flat();
    return fields.length ? fields.join(' ') : reason.message;
  }
  return 'Could not reach the server. Nothing was saved.';
}

/**
 * One lesson. When attendance is due the primary button submits the scheduled
 * minutes in one tap; a different time or a no-show opens a short form.
 */
export function LessonCard({lesson, today, onSaved}: {lesson: Lesson; today: string; onSaved: () => void}) {
  const showToast = useToast();
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string>();
  const [drawer, setDrawer] = useState<'change' | 'no-show' | null>(null);
  const oneTap = lesson.needsAttendance && lesson.scheduledMinutes >= 1 && lesson.scheduledMinutes <= 480;

  const submit = async () => {
    setSaving(true);
    setError(undefined);
    try {
      const result = await postJson<{message: string}>('/erp/teacher/attendance', {lesson_id: lesson.id, actual_minutes: lesson.scheduledMinutes});
      showToast({body: result.message, uniqueID: `attendance-${lesson.id}`});
      onSaved();
    } catch (reason) {
      setError(messageFor(reason));
    } finally {
      setSaving(false);
    }
  };

  return (
    <Card padding={3} variant={lesson.needsAttendance ? 'default' : lesson.status === 'scheduled' ? 'default' : 'muted'}>
      <VStack gap={2}>
        <HStack gap={2} vAlign="center" wrap="wrap">
          <StackItem size="fill">
            <Text weight="semibold" hasTabularNumbers>{`${dayLabel(lesson.date, today)} · ${lesson.startTime}–${lesson.endTime}`}</Text>
          </StackItem>
          <Status value={lesson.status} />
        </HStack>
        <VStack gap={0.5}>
          <Text size="lg" weight="semibold">{lesson.studentName}</Text>
          <Text color="secondary">{`${lesson.subjectName} · ${titleCase(lesson.kind)} class · ${lesson.studentCode}`}</Text>
        </VStack>
        {lesson.attendance ? (
          <HStack gap={2} vAlign="center">
            <Text hasTabularNumbers>{`${lesson.attendance.minutes} minutes recorded`}</Text>
            <Status value={lesson.attendance.status} />
          </HStack>
        ) : lesson.needsAttendance ? (
          <VStack gap={2}>
            {error && <Banner status="error" title={error} />}
            <HStack gap={2} wrap="wrap">
              {oneTap ? (
                <Button label={`Submit attendance · ${lesson.scheduledMinutes} min`} variant="primary" isLoading={saving} onClick={submit} />
              ) : (
                <Button label="Submit attendance" variant="primary" onClick={() => setDrawer('change')} />
              )}
              {oneTap && <Button label="Different time" variant="secondary" onClick={() => setDrawer('change')} />}
              {lesson.canMarkNoShow && <Button label="Didn't attend" variant="ghost" onClick={() => setDrawer('no-show')} />}
            </HStack>
          </VStack>
        ) : (
          <Text color="secondary" size="sm">
            {['scheduled', 'completed'].includes(lesson.status) ? 'Attendance opens when this lesson ends.' : 'Attendance unavailable for this lesson.'}
          </Text>
        )}
      </VStack>
      {drawer === 'change' && <AttendanceDrawer lesson={lesson} onClose={() => setDrawer(null)} onSaved={onSaved} />}
      {drawer === 'no-show' && <NoShowDrawer lesson={lesson} onClose={() => setDrawer(null)} onSaved={onSaved} />}
    </Card>
  );
}

function useSave(onSaved: () => void, onClose: () => void) {
  const showToast = useToast();
  const [saving, setSaving] = useState(false);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [formError, setFormError] = useState<string>();
  const save = async (url: string, payload: Record<string, unknown>, fields: string[]) => {
    setSaving(true);
    setErrors({});
    setFormError(undefined);
    try {
      const result = await postJson<{message: string}>(url, payload);
      showToast({body: result.message, uniqueID: url});
      onClose();
      onSaved();
      return true;
    } catch (reason) {
      if (reason instanceof ApiError) {
        const placed: Record<string, string[]> = {};
        const other: string[] = [];
        for (const [key, messages] of Object.entries(reason.fieldErrors)) {
          if (fields.includes(key)) placed[key] = messages;
          else other.push(...messages);
        }
        setErrors(placed);
        setFormError(other.length ? other.join(' ') : Object.keys(placed).length ? 'Please check the highlighted fields. Nothing has been saved yet.' : reason.message);
      } else {
        setFormError('Could not reach the server. Nothing was saved.');
      }
      return false;
    } finally {
      setSaving(false);
    }
  };
  const statusFor = (name: string) => (errors[name]?.length ? {type: 'error' as const, message: errors[name][0]} : undefined);
  return {saving, formError, save, statusFor};
}

function AttendanceDrawer({lesson, onClose, onSaved}: {lesson: Lesson; onClose: () => void; onSaved: () => void}) {
  const [minutes, setMinutes] = useState<number | null>(lesson.scheduledMinutes >= 1 && lesson.scheduledMinutes <= 480 ? lesson.scheduledMinutes : null);
  const [start, setStart] = useState<string>(lesson.startsAtLocal);
  const [reason, setReason] = useState('');
  const {saving, formError, save, statusFor} = useSave(onSaved, onClose);
  const submit = () =>
    save('/erp/teacher/attendance', {lesson_id: lesson.id, actual_minutes: minutes ?? '', actual_starts_at: start, change_reason: reason}, ['actual_minutes', 'actual_starts_at', 'change_reason', 'lesson_id']);
  return (
    <Drawer isOpen onOpenChange={open => !open && onClose()} label="Submit attendance" width={440} purpose="form" padding={5} experimental={{isFullWidthOnMobile: true}}>
      <VStack gap={5}>
        <DrawerHeader title="Submit attendance" subtitle={`${lesson.studentName} · ${lesson.subjectName} · ${lesson.startTime}–${lesson.endTime}`} onOpenChange={open => !open && onClose()} />
        {formError && <Banner status="error" title={formError} />}
        <VStack gap={4}>
          <NumberInput label="Actual delivered minutes" isRequired value={minutes} onChange={value => setMinutes(Number.isFinite(value) ? value : null)} min={1} max={480} isIntegerOnly status={statusFor('actual_minutes')} />
          <DateTimeInput label="Actual start" description="Your time zone. Change it only if the class started at a different time." value={start as ISODateTimeString} hourFormat="24h" onChange={value => setStart((value ?? '').slice(0, 16))} status={statusFor('actual_starts_at')} />
          <TextInput label="Reason for change" isOptional description="Needed when the time or duration differs from the timetable." placeholder="For example: parent requested 15 extra minutes" value={reason} onChange={setReason} status={statusFor('change_reason')} />
          <Text color="secondary" size="sm">Your submission is sent for administrator review. Ask an administrator to correct an existing record.</Text>
        </VStack>
        <HStack gap={2} hAlign="end">
          <Button label="Cancel" variant="ghost" onClick={onClose} />
          <Button label="Submit attendance" variant="primary" isLoading={saving} onClick={submit} />
        </HStack>
      </VStack>
    </Drawer>
  );
}

function NoShowDrawer({lesson, onClose, onSaved}: {lesson: Lesson; onClose: () => void; onSaved: () => void}) {
  const [reason, setReason] = useState('');
  const {saving, formError, save, statusFor} = useSave(onSaved, onClose);
  return (
    <Drawer isOpen onOpenChange={open => !open && onClose()} label="Student didn't attend" width={440} purpose="form" padding={5} experimental={{isFullWidthOnMobile: true}}>
      <VStack gap={5}>
        <DrawerHeader title="Student didn't attend" subtitle={`${lesson.studentName} · ${lesson.startTime}–${lesson.endTime}`} onOpenChange={open => !open && onClose()} />
        {formError && <Banner status="error" title={formError} />}
        <TextInput label="What happened" isRequired placeholder="For example: waited 15 minutes, called the parent, no answer" value={reason} onChange={setReason} status={statusFor('reason')} />
        <Text color="secondary" size="sm">The student is not charged and the class is not paid.</Text>
        <HStack gap={2} hAlign="end">
          <Button label="Cancel" variant="ghost" onClick={onClose} />
          <Button label="Record no-show" variant="destructive" isLoading={saving} onClick={() => save('/erp/teacher/attendance', {no_show: 1, lesson_id: lesson.id, reason}, ['reason', 'lesson_id'])} />
        </HStack>
      </VStack>
    </Drawer>
  );
}

export function LessonList({lessons, today, onSaved, empty}: {lessons: Lesson[]; today: string; onSaved: () => void; empty: ReactNode}) {
  if (lessons.length === 0) return <>{empty}</>;
  return (
    <Grid columns={{minWidth: 300, repeat: 'fill'}} gap={3}>
      {lessons.map(lesson => (
        <LessonCard key={lesson.id} lesson={lesson} today={today} onSaved={onSaved} />
      ))}
    </Grid>
  );
}
