// Assign a teacher to a student for one subject (Scheduling::assign) and end an
// assignment prospectively (Scheduling::endAssignment). Used on the student
// profile's Classes tab and the teacher profile's Classes list. Both post to the
// WorkspaceController action routes, which hold eligibility, overlap and date rules.
import {Text} from '@astryxdesign/core/Text';
import {ActionDrawer, type ActionField} from './ActionDrawer';
import {ConfirmActionDialog} from '../Money/shared';
import {todayIn, workspaceAction} from './shared';
import {date} from '../../lib/format';

export interface EndableAssignment {
  id: number;
  /** e.g. "Maths with Asha Rao", shown in the confirmation. */
  label: string;
  startsOn: string;
  endsOn: string | null;
}

/** An active assignment can be ended when it is ongoing, or ended earlier when its end date is still ahead. */
export function isEndable(row: {status: string; endsOn: string | null}, today: string): boolean {
  return row.status === 'active' && (!row.endsOn || row.endsOn > today);
}

export function AssignTeacherDrawer({
  isOpen,
  onOpenChange,
  unitId,
  timezone,
  student,
  teacher,
  onSaved,
}: {
  isOpen: boolean;
  onOpenChange: (open: boolean) => void;
  unitId: number;
  timezone: string;
  /** The student when opened from their profile; otherwise picked in the form. */
  student?: {id: number; name: string};
  /** The teacher when opened from their profile; otherwise picked in the form. */
  teacher?: {id: number; name: string};
  onSaved?: () => void;
}) {
  const fields: ActionField[] = [
    ...(student ? [] : [{kind: 'lookup' as const, name: 'student_id', label: 'Student', lookup: 'students' as const, required: true}]),
    ...(teacher ? [] : [{kind: 'lookup' as const, name: 'teacher_id', label: 'Teacher', lookup: 'teachers' as const, required: true}]),
    {kind: 'lookup', name: 'subject_id', label: 'Subject', lookup: 'subjects', required: true, description: "The teacher must be eligible for this subject at the student's grade and syllabus."},
    {kind: 'date', name: 'starts_on', label: 'Starts on', required: true},
    {kind: 'date', name: 'ends_on', label: 'Ends on', description: 'Leave blank for an ongoing class.'},
    {kind: 'money', name: 'student_rate_inr', label: 'Student hourly rate (INR)', required: true, description: 'What the student is charged per hour, e.g. 500 or 450.50. 0 is allowed.'},
    {kind: 'money', name: 'teacher_rate_inr', label: 'Teacher hourly rate (INR)', required: true, description: 'What the teacher earns per hour. 0 is allowed.'},
  ];
  const fixed: Record<string, unknown> = {};
  if (student) fixed.student_id = student.id;
  if (teacher) fixed.teacher_id = teacher.id;
  const title = teacher && !student ? 'Assign a student' : 'Assign teacher';
  const who = [student ? `Student: ${student.name}` : null, teacher ? `Teacher: ${teacher.name}` : null].filter(Boolean).join(' · ');
  return (
    <ActionDrawer
      isOpen={isOpen}
      onOpenChange={onOpenChange}
      unitId={unitId}
      title={title}
      description="One teacher per student and subject. Add weekly class times on the Timetable afterwards."
      summary={who ? <Text color="secondary">{who}</Text> : undefined}
      fields={fields}
      initial={{starts_on: todayIn(timezone)}}
      fixed={fixed}
      action={workspaceAction(unitId, 'assign')}
      submitLabel={title}
      successMessage="Assignment saved."
      onSaved={() => onSaved?.()}
    />
  );
}

/** Pick the last class day, then confirm in an AlertDialog. Renders nothing when no assignment is chosen. */
export function EndAssignmentDialog({
  unitId,
  timezone,
  assignment,
  onClose,
  onDone,
}: {
  unitId: number;
  timezone: string;
  assignment: EndableAssignment | null;
  onClose: () => void;
  onDone?: () => void;
}) {
  if (!assignment) return null;
  const today = todayIn(timezone);
  const earliest = assignment.startsOn > today ? assignment.startsOn : today;
  return (
    <ConfirmActionDialog
      isOpen
      onOpenChange={open => !open && onClose()}
      title="End assignment"
      description={assignment.label}
      fields={[
        {
          kind: 'date',
          name: 'ends_on',
          label: 'Last class day',
          required: true,
          min: earliest,
          max: assignment.endsOn ?? undefined,
          description: 'Cancel or resolve any lessons after this day first. The weekly schedule ends on the same day.',
        },
      ]}
      fixed={{enrollment_id: assignment.id}}
      initial={{ends_on: earliest}}
      url={workspaceAction(unitId, 'end-assignment')}
      confirmTitle="End this assignment?"
      confirmDescription={`${assignment.label} ends after the chosen day${assignment.endsOn ? ` (currently ${date(assignment.endsOn)})` : ''}. An ended assignment cannot be extended; assign the teacher again to restart.`}
      confirmLabel="End assignment"
      successMessage="Assignment ended."
      onDone={() => {
        onClose();
        onDone?.();
      }}
    />
  );
}
