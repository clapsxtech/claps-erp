// Scheduling and attendance action forms. Each posts to the
// WorkspaceController::mutate route. When opened from a lesson, the lesson,
// assignment or student is fixed instead of picked.
import type {ActionField} from './ActionDrawer';
import {CONFIRMATION_CHANNELS, newReference, workspaceAction} from './shared';

export type LessonActionKey = 'reschedule' | 'cancel' | 'attendance' | 'no-show' | 'extra' | 'exception-student' | 'exception-teacher' | 'pause-classes' | 'stop-classes' | 'switch-teacher';

/** The bits of a lesson the forms need; absent when the form is opened from the page header. */
export interface LessonContext {
  id: number;
  enrollmentId: number;
  studentId: number;
  studentName: string;
  teacherId: number;
  teacherName: string;
  subjectName: string;
  localDate: string;
  startTime: string;
  durationMinutes: number;
}

export interface ActionConfig {
  action: string;
  /** Permission action name in unit.actions. */
  permission: string;
  title: string;
  description?: string;
  submitLabel: string;
  successMessage: string;
  fields: ActionField[];
  fixed?: Record<string, unknown>;
  initial?: Record<string, string | string[]>;
  alwaysSend?: string[];
  confirmText?: string;
  isDestructive?: boolean;
}

const agreement: ActionField[] = [
  {kind: 'confirm', name: 'teacher_confirmed', label: 'Teacher confirmed', required: true},
  {kind: 'confirm', name: 'student_confirmed', label: 'Parent / student confirmed', required: true},
  {kind: 'select', name: 'confirmation_channel', label: 'Confirmation channel', required: true, options: CONFIRMATION_CHANNELS},
  {kind: 'textarea', name: 'confirmation_note', label: 'Confirmation note', required: true, description: 'Who confirmed, when, and the agreed time.'},
];

export const ACTION_PERMISSION: Record<LessonActionKey, string> = {
  reschedule: 'reschedule',
  cancel: 'cancel',
  attendance: 'attendance',
  'no-show': 'no-show',
  extra: 'extra',
  'exception-student': 'exception',
  'exception-teacher': 'exception',
  'pause-classes': 'pause-classes',
  'stop-classes': 'stop-classes',
  'switch-teacher': 'switch-teacher',
};

export function lessonAction(key: LessonActionKey, unitId: number, timezone: string, lesson?: LessonContext): ActionConfig {
  const pick = (name: string, label: string, lookup: 'lessons' | 'enrollments' | 'students' | 'teachers'): ActionField[] =>
    lesson ? [] : [{kind: 'lookup', name, label, lookup, required: true}];
  const base = {action: workspaceAction(unitId, ACTION_PERMISSION[key]), permission: ACTION_PERMISSION[key]};
  switch (key) {
    case 'reschedule':
      return {
        ...base,
        title: 'Reschedule lesson',
        description: 'Moves this lesson with a renewed agreement from both sides. The original stays in history.',
        submitLabel: 'Reschedule',
        successMessage: 'Lesson rescheduled.',
        fields: [
          ...pick('lesson_id', 'Lesson', 'lessons'),
          {kind: 'date', name: 'date', label: 'New date', required: true},
          {kind: 'time', name: 'start_time', label: 'New start time', required: true},
          {kind: 'text', name: 'timezone', label: 'Timezone', required: true},
          {kind: 'text', name: 'reason', label: 'Reason', required: true},
          ...agreement,
        ],
        fixed: lesson ? {lesson_id: lesson.id} : undefined,
        initial: {timezone, date: lesson?.localDate ?? '', start_time: lesson?.startTime ?? '', confirmation_channel: 'whatsapp'},
      };
    case 'cancel':
      return {
        ...base,
        title: 'Cancel lesson without charge',
        description: 'The student is not charged and the teacher is not paid. The lesson stays visible for history.',
        submitLabel: 'Cancel lesson',
        successMessage: 'Lesson cancelled.',
        fields: [...pick('lesson_id', 'Lesson', 'lessons'), {kind: 'text', name: 'reason', label: 'Reason', required: true}],
        fixed: lesson ? {lesson_id: lesson.id} : undefined,
        confirmText: 'This cancels the lesson. It cannot be un-cancelled; schedule an extra class to replace it.',
        isDestructive: true,
      };
    case 'attendance':
      return {
        ...base,
        title: 'Record attended minutes',
        description: 'Submitting does not charge. Import and approval stay separate steps on the Attendance screen.',
        submitLabel: 'Record attendance',
        successMessage: 'Attendance recorded.',
        fields: [
          ...pick('lesson_id', 'Lesson', 'lessons'),
          {kind: 'number', name: 'actual_minutes', label: 'Actual minutes', required: true, min: 1},
          {kind: 'text', name: 'actual_starts_at', label: 'Actual start if moved (UTC)', placeholder: 'YYYY-MM-DD HH:MM:SS'},
          {kind: 'textarea', name: 'change_reason', label: 'Reason for changed time or duration'},
        ],
        fixed: lesson ? {lesson_id: lesson.id} : undefined,
        initial: lesson ? {actual_minutes: String(lesson.durationMinutes)} : undefined,
        // Always post this key; its presence makes a changed duration need a reason.
        alwaysSend: ['actual_starts_at', 'change_reason'],
      };
    case 'no-show':
      return {
        ...base,
        title: 'Record a student no-show',
        description: 'No student charge and no teacher pay, like a cancellation.',
        submitLabel: 'Record no-show',
        successMessage: 'Recorded as a no-show.',
        fields: [...pick('lesson_id', 'Lesson', 'lessons'), {kind: 'textarea', name: 'reason', label: 'What happened', required: true}],
        fixed: lesson ? {lesson_id: lesson.id} : undefined,
      };
    case 'extra':
      return {
        ...base,
        title: 'Schedule a demo or extra class',
        description: lesson ? `Same assignment: ${lesson.studentName} with ${lesson.teacherName}, ${lesson.subjectName}.` : 'A one-off lesson for an existing teaching assignment.',
        submitLabel: 'Schedule lesson',
        successMessage: 'Lesson scheduled.',
        fields: [
          ...pick('enrollment_id', 'Teaching assignment', 'enrollments'),
          {kind: 'select', name: 'kind', label: 'Kind', required: true, options: [{value: 'extra', label: 'Extra: agreed rates'}, {value: 'demo', label: 'Demo: free, teacher unpaid'}]},
          {kind: 'date', name: 'date', label: 'Date', required: true},
          {kind: 'time', name: 'start_time', label: 'Start time', required: true},
          {kind: 'number', name: 'duration_minutes', label: 'Minutes', required: true, min: 5},
          {kind: 'text', name: 'timezone', label: 'Timezone', required: true},
          ...agreement,
        ],
        // A fresh stable reference per open, so a double submit cannot book twice.
        fixed: {...(lesson ? {enrollment_id: lesson.enrollmentId} : {}), reference: newReference('extra')},
        initial: {kind: 'extra', timezone, duration_minutes: String(lesson?.durationMinutes ?? 60), confirmation_channel: 'whatsapp'},
      };
    case 'exception-student':
    case 'exception-teacher': {
      const party = key === 'exception-student' ? 'student' : 'teacher';
      const name = party === 'student' ? lesson?.studentName : lesson?.teacherName;
      return {
        ...base,
        title: lesson ? `Mark ${name} unavailable` : `Record ${party} unavailability`,
        description: 'Leave both times blank for a full day. For a timed window, enter both times and a timezone. Resolve overlapping lessons first; none is cancelled silently.',
        submitLabel: 'Save unavailability',
        successMessage: 'Unavailability saved.',
        fields: [
          ...(lesson ? [] : [{kind: 'lookup', name: `${party}_id`, label: party === 'student' ? 'Student' : 'Teacher', lookup: party === 'student' ? 'students' : 'teachers', required: true} as ActionField]),
          {kind: 'date', name: 'date', label: 'Local date', required: true},
          {kind: 'time', name: 'start_time', label: 'Start time'},
          {kind: 'time', name: 'end_time', label: 'End time', description: '00:00 means the next midnight.'},
          {kind: 'text', name: 'timezone', label: 'Timezone', description: 'Required for a timed window, e.g. Asia/Kolkata.'},
          {kind: 'text', name: 'reason', label: 'Reason', required: true},
        ],
        fixed: lesson ? {[`${party}_id`]: party === 'student' ? lesson.studentId : lesson.teacherId} : undefined,
        initial: {date: lesson?.localDate ?? ''},
      };
    }
    case 'pause-classes':
      return {
        ...base,
        title: lesson ? `Pause ${lesson.studentName}'s classes` : "Pause a student's classes",
        description: 'Cancels every scheduled lesson in these dates for all subjects, without charge. Lessons after the break stay booked.',
        submitLabel: 'Pause classes',
        successMessage: 'Classes paused.',
        fields: [
          ...pick('student_id', 'Student', 'students'),
          {kind: 'date', name: 'from', label: 'First day off', required: true},
          {kind: 'date', name: 'through', label: 'Last day off', required: true},
          {kind: 'text', name: 'reason', label: 'Reason', required: true},
        ],
        fixed: lesson ? {student_id: lesson.studentId} : undefined,
        initial: {from: lesson?.localDate ?? ''},
        confirmText: 'Every scheduled lesson in these dates will be cancelled for all subjects.',
        isDestructive: true,
      };
    case 'stop-classes':
      return {
        ...base,
        title: lesson ? `Stop ${lesson.studentName}'s classes` : "Stop a student's classes",
        description: 'Cancels every lesson after this day and ends each teaching assignment. The balance is kept; record any refund in Money.',
        submitLabel: 'Stop classes',
        successMessage: 'Classes stopped.',
        fields: [
          ...pick('student_id', 'Student', 'students'),
          {kind: 'date', name: 'last_day', label: 'Last class day', required: true},
          {kind: 'text', name: 'reason', label: 'Reason', required: true},
        ],
        fixed: lesson ? {student_id: lesson.studentId} : undefined,
        confirmText: 'All later lessons are cancelled and every assignment for this student ends. The student is marked stopped once no classes remain.',
        isDestructive: true,
      };
    case 'switch-teacher':
      return {
        ...base,
        title: 'Change the teacher',
        description: lesson
          ? `Moves ${lesson.studentName}'s ${lesson.subjectName} classes from the chosen date to the new teacher at the same weekly times.`
          : "Moves one subject's classes to a new teacher at the same weekly times, after eligibility and availability checks.",
        submitLabel: 'Change teacher',
        successMessage: 'Teacher changed.',
        fields: [
          ...pick('enrollment_id', 'Current assignment', 'enrollments'),
          {kind: 'lookup', name: 'teacher_id', label: 'New teacher', lookup: 'teachers', required: true},
          {kind: 'date', name: 'from', label: 'First class with the new teacher', required: true},
          {kind: 'money', name: 'teacher_rate_inr', label: 'New teacher hourly rate (INR)', required: true},
          {kind: 'money', name: 'student_rate_inr', label: 'Student hourly rate (INR)', description: 'Leave blank to keep the current rate.'},
          {kind: 'text', name: 'reason', label: 'Reason', required: true},
          ...agreement,
        ],
        fixed: lesson ? {enrollment_id: lesson.enrollmentId} : undefined,
        initial: {confirmation_channel: 'whatsapp', from: lesson?.localDate ?? ''},
        confirmText: 'Later lessons with the current teacher are cancelled and rebooked with the new teacher. Nothing changes if any check fails.',
        isDestructive: true,
      };
  }
}
