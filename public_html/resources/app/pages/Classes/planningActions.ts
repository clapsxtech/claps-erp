// Weekly planning actions (availability, propose, confirm, materialize,
// end-agreement). Each posts to WorkspaceController::mutate; all
// scheduling rules stay in Scheduling.
import type {ActionField} from './ActionDrawer';
import type {ActionConfig} from './lessonActions';
import {CONFIRMATION_CHANNELS, workspaceAction} from './shared';

export type PlanningActionKey = 'availability' | 'propose' | 'confirm' | 'materialize' | 'end-agreement';

/** The agreement a row action works on; absent when opened from the tab header. */
export interface AgreementContext {
  id: number;
  label: string;
  startsOn: string;
  endsOn: string | null;
}

export const WEEKDAY_OPTIONS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'].map((label, i) => ({value: String(i + 1), label}));

export const PLANNING_LABELS: Record<PlanningActionKey, string> = {
  availability: 'Add availability',
  propose: 'Propose weekly slot',
  confirm: 'Confirm agreement',
  materialize: 'Generate lessons',
  'end-agreement': 'End agreement',
};

export function planningAction(key: PlanningActionKey, unitId: number, timezone: string, agreement?: AgreementContext): ActionConfig {
  const base = {action: workspaceAction(unitId, key), permission: key};
  const pickAgreement: ActionField[] = agreement ? [] : [{kind: 'lookup', name: 'agreement_id', label: 'Weekly slot (agreement)', lookup: 'agreements', required: true}];
  const fixed = agreement ? {agreement_id: agreement.id} : undefined;
  switch (key) {
    case 'availability':
      return {
        ...base,
        title: 'Add weekly availability',
        description: 'Record when a student OR a teacher is free each week. Choose one of them, not both. Split overnight windows.',
        submitLabel: 'Save availability',
        successMessage: 'Availability saved.',
        fields: [
          {kind: 'lookup', name: 'student_id', label: 'Student', lookup: 'students', description: 'Leave empty when recording a teacher.'},
          {kind: 'lookup', name: 'teacher_id', label: 'Teacher', lookup: 'teachers', description: 'Leave empty when recording a student.'},
          {kind: 'select', name: 'weekday', label: 'Weekday', required: true, options: WEEKDAY_OPTIONS},
          {kind: 'time', name: 'start_time', label: 'Start time', required: true},
          {kind: 'time', name: 'end_time', label: 'End time', required: true},
          {kind: 'text', name: 'timezone', label: 'Timezone', required: true},
          {kind: 'date', name: 'starts_on', label: 'Starts on', required: true},
          {kind: 'date', name: 'ends_on', label: 'Ends on'},
        ],
        initial: {timezone},
      };
    case 'propose':
      return {
        ...base,
        title: 'Propose a weekly slot',
        description: 'A recurring time for a teaching assignment. It must fit both sides’ availability, and it books nothing until both sides confirm.',
        submitLabel: 'Propose slot',
        successMessage: 'Weekly slot proposed. Confirm it once both sides agree.',
        fields: [
          {kind: 'lookup', name: 'enrollment_id', label: 'Teaching assignment', lookup: 'enrollments', required: true},
          {kind: 'select', name: 'weekday', label: 'Weekday', required: true, options: WEEKDAY_OPTIONS},
          {kind: 'time', name: 'start_time', label: 'Start time', required: true},
          {kind: 'number', name: 'duration_minutes', label: 'Duration (minutes)', required: true, min: 1, max: 480},
          {kind: 'text', name: 'timezone', label: 'Timezone', required: true},
          {kind: 'date', name: 'starts_on', label: 'Starts on', required: true},
          {kind: 'date', name: 'ends_on', label: 'Ends on', description: 'Leave empty to follow the assignment.'},
          {kind: 'lookup', name: 'replaces_agreement_id', label: 'Replaces weekly slot', lookup: 'agreements', description: 'Only when this replaces an ended slot.'},
        ],
        initial: {timezone, duration_minutes: '60'},
      };
    case 'confirm':
      return {
        ...base,
        title: 'Confirm weekly slot',
        description: agreement ? agreement.label : 'Record that both the teacher and the family agreed to the proposed time.',
        submitLabel: 'Confirm slot',
        successMessage: 'Weekly slot confirmed. Generate its lessons next.',
        fields: [
          ...pickAgreement,
          {kind: 'confirm', name: 'teacher_confirmed', label: 'Teacher confirmed', required: true},
          {kind: 'confirm', name: 'student_confirmed', label: 'Parent / student confirmed', required: true},
          {kind: 'select', name: 'confirmation_channel', label: 'Confirmation channel', required: true, options: CONFIRMATION_CHANNELS},
          {kind: 'textarea', name: 'confirmation_note', label: 'Confirmation note', required: true, description: 'Who confirmed, when, and the agreed time.'},
        ],
        fixed,
        initial: {confirmation_channel: 'whatsapp'},
      };
    case 'materialize':
      return {
        ...base,
        title: 'Generate dated lessons',
        description: agreement ? `${agreement.label}. Creates one scheduled lesson per week in the range; existing lessons are not duplicated.` : 'Creates one scheduled lesson per week of a confirmed slot.',
        submitLabel: 'Generate lessons',
        successMessage: 'Lessons generated. They now appear on the week view.',
        fields: [...pickAgreement, {kind: 'date', name: 'from', label: 'From', required: true}, {kind: 'date', name: 'through', label: 'Through', required: true}],
        fixed,
        initial: agreement ? {from: agreement.startsOn} : undefined,
      };
    case 'end-agreement':
      return {
        ...base,
        title: 'End weekly slot',
        description: 'Cancel or reschedule later generated lessons first. Then propose a replacement with new availability and agreement.',
        submitLabel: 'End slot',
        successMessage: 'Weekly slot ended.',
        fields: [...pickAgreement, {kind: 'date', name: 'ends_on', label: 'Last effective local date', required: true}],
        fixed,
        confirmText: 'No lessons are generated from this slot after that date. This does not cancel lessons that already exist.',
        isDestructive: true,
      };
  }
}
