// One status word always gets one colour, everywhere in the app.
import {HStack} from '@astryxdesign/core/Layout';
import {StatusDot, type StatusDotVariant} from '@astryxdesign/core/StatusDot';
import {Text} from '@astryxdesign/core/Text';
import {titleCase} from '../lib/format';

const TONES: Record<string, StatusDotVariant> = {
  active: 'success', approved: 'success', completed: 'success', paid: 'success', confirmed: 'success', present: 'success', converted: 'success', done: 'success', posted: 'success', imported: 'success',
  scheduled: 'accent', open: 'accent', proposed: 'accent', submitted: 'accent', new: 'accent', 'in_progress': 'accent', demo: 'accent', draft: 'accent',
  pending: 'warning', paused: 'warning', partial: 'warning', rescheduled: 'warning', due: 'warning', overdue: 'error', 'follow_up': 'warning',
  cancelled: 'neutral', stopped: 'neutral', inactive: 'neutral', ended: 'neutral', archived: 'neutral', reversed: 'neutral', lost: 'neutral',
  rejected: 'error', no_show: 'error', failed: 'error', blocked: 'error',
};

export function statusTone(value: string | null | undefined): StatusDotVariant {
  return TONES[(value ?? '').toLowerCase().replace(/\s+/g, '_')] ?? 'neutral';
}

export function Status({value, label}: {value: string | null | undefined; label?: string}) {
  const text = label ?? titleCase(value);
  return (
    <HStack gap={1.5} vAlign="center">
      <StatusDot variant={statusTone(value)} label={text} />
      <Text maxLines={1}>{text}</Text>
    </HStack>
  );
}
