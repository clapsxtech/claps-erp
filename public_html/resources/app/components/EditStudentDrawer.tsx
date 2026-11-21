// The Edit student drawer, shared by the student profile and the Students list
// (row action "Edit", for roles allowed student-update without the profile).
// It posts only changed fields to the student-update action; emptying the parent
// contact or class link clears them.
import {FormDrawer, type Field} from './FormDrawer';
import {titleCase} from '../lib/format';

export interface EditableStudent {
  id: number;
  name: string;
  status: string;
  registeredOn: string;
  grade: string;
  syllabus: string;
  guardianContact: string | null;
  meetingUrl: string | null;
  billingState: string | null;
}

export function EditStudentDrawer({
  unitId,
  student,
  states,
  isOpen,
  onOpenChange,
  onSaved,
}: {
  unitId: number;
  student: EditableStudent;
  /** Indian states for the GST place of supply. */
  states: string[];
  isOpen: boolean;
  onOpenChange: (open: boolean) => void;
  onSaved?: () => void;
}) {
  const fields: Field[] = [
    {kind: 'text', name: 'name', label: 'Name', required: true},
    {kind: 'select', name: 'status', label: 'Status', options: ['active', 'paused', 'stopped'].map(value => ({value, label: titleCase(value)})), description: 'Cancel future classes before pausing or stopping.'},
    {kind: 'date', name: 'registered_on', label: 'Joined'},
    {kind: 'text', name: 'grade', label: 'Grade', required: true},
    {kind: 'text', name: 'syllabus', label: 'Syllabus', required: true},
    {kind: 'tel', name: 'guardian_contact', label: 'Parent contact', description: 'Phone or email.'},
    {kind: 'url', name: 'meeting_url', label: 'Class link', placeholder: 'https://'},
    {kind: 'date', name: 'date_of_birth', label: 'Date of birth (parent portal sign-in)', description: 'Leave blank to keep the saved date.'},
    {kind: 'select', name: 'billing_state', label: 'Billing state (GST place of supply)', description: "Only if it differs from the team's state.", options: states.map(value => ({value, label: value}))},
  ];
  return (
    <FormDrawer
      isOpen={isOpen}
      onOpenChange={onOpenChange}
      title="Edit student"
      description="Only changed fields are saved; blank fields keep their saved values. Changes are audited."
      sendChangedOnly
      clearable={['guardian_contact', 'meeting_url']}
      fields={fields}
      initial={{
        name: student.name,
        status: student.status,
        registered_on: student.registeredOn,
        grade: student.grade,
        syllabus: student.syllabus,
        guardian_contact: student.guardianContact ?? '',
        meeting_url: student.meetingUrl ?? '',
        billing_state: student.billingState ?? '',
      }}
      hidden={{student_id: student.id}}
      action={`/erp/units/${unitId}/business-actions/student-update`}
      submitLabel="Save student"
      successMessage="Student updated."
      onSaved={onSaved}
    />
  );
}
