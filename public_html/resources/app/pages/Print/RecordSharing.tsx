// Records that a monthly parent statement was shared: posts every submitted entry
// of the statement, with its review snapshot, to the tuition
// "monthly-review" operation. The server converts the deadline from the team's time.
import {ConfirmActionDialog} from '../Money/shared';
import type {StatementData} from './Statement';

export function RecordSharingDialog({
  isOpen,
  onOpenChange,
  unitId,
  statement,
  onDone,
}: {
  isOpen: boolean;
  onOpenChange: (open: boolean) => void;
  unitId: number;
  statement: Pick<StatementData, 'month' | 'student' | 'businessTimezone' | 'review'> | undefined;
  onDone?: () => void;
}) {
  if (!statement) return null;
  const entries = statement.review.entries;
  return (
    <ConfirmActionDialog
      isOpen={isOpen}
      onOpenChange={onOpenChange}
      title={`Record statement sharing · ${statement.student.name}`}
      description={`${statement.month} · ${entries.length} submitted ${entries.length === 1 ? 'entry' : 'entries'}`}
      fields={[
        {kind: 'datetime', name: 'deadline_at', label: `Objection deadline (${statement.businessTimezone} time)`, required: true},
        {kind: 'textarea', name: 'note', label: 'Sharing evidence', required: true, placeholder: 'When and where the parent received this statement'},
      ]}
      fixed={{
        attendance_ids: entries.map(entry => entry.attendanceId),
        snapshots: Object.fromEntries(entries.map(entry => [String(entry.attendanceId), entry.snapshot])),
      }}
      url={`/erp/units/${unitId}/tuition/monthly-review`}
      confirmTitle="Record statement sharing?"
      confirmDescription="Each listed entry is marked as shared with this deadline. Silence counts as acceptance only after the deadline; it is recorded with your name and time."
      confirmLabel="Record statement sharing"
      successMessage="Statement sharing recorded for the selected attendance entries."
      isDestructive={false}
      onDone={onDone}
    />
  );
}
