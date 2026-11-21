// Every admissions write, as drawers over the admission-* actions. The
// list (row menu) and the lead page open the same forms, so a sales user logs a
// call or books a demo in one tap from either place.
import {useNavigate} from 'react-router';
import {Text} from '@astryxdesign/core/Text';
import {ActionDrawer, dateShortcuts, todayIn} from './ActionDrawer';
import {businessAction} from './access';

export type LeadFormKind = 'create' | 'followup' | 'demo' | 'reminder' | 'edit' | 'handoff' | 'lost' | 'reopen' | 'convert' | 'transfer' | 'demo-update';

export interface LeadFormTarget {
  id: number;
  name: string;
  nextFollowUpOn?: string | null;
  rawName?: string;
  phone?: string;
  country?: string | null;
  email?: string | null;
  grade?: string | null;
  subject?: string | null;
  syllabus?: string | null;
  source?: string | null;
  studentPhone?: string | null;
  notes?: string | null;
  learningRequirement?: string | null;
  concern?: string | null;
  aboutStudent?: string | null;
  aboutParent?: string | null;
}

export interface DemoTarget {
  id: number;
  scheduledOn: string;
  scheduledTime: string;
}

const CHANNELS = [
  {value: 'call', label: 'Call'},
  {value: 'whatsapp', label: 'WhatsApp'},
  {value: 'email', label: 'Email'},
  {value: 'other', label: 'Other'},
];
const OUTCOMES = ['Interested', 'No reply', 'Call back later', 'Fees issue', 'Demo requested', 'Not interested', 'Other'].map(v => ({value: v, label: v}));
const INTEREST = ['Interested', 'No reply', 'Fees issue', 'Next year', 'Not interested'].map(v => ({value: v, label: v}));
const opt = (v: string | null | undefined) => v ?? '';

export function LeadForms({
  unitId,
  timezone,
  kind,
  lead,
  demo,
  members = [],
  onClose,
  onSaved,
}: {
  unitId: number;
  timezone: string;
  kind: LeadFormKind | null;
  lead?: LeadFormTarget | null;
  demo?: DemoTarget | null;
  members?: {value: string; label: string}[];
  onClose: () => void;
  onSaved: () => void;
}) {
  const navigate = useNavigate();
  const today = todayIn(timezone);
  const onOpenChange = (open: boolean) => {
    if (!open) onClose();
  };
  const common = {unitId, onOpenChange, onSaved};
  const leadId = lead ? {lead_id: lead.id} : {};

  return (
    <>
      <ActionDrawer
        {...common}
        isOpen={kind === 'create'}
        title="New lead"
        description="Start with the parent's phone. Add the student's name and other details when you know them."
        fields={[
          {kind: 'tel', name: 'phone', label: 'Parent / contact phone', required: true, placeholder: '+91 98765 43210'},
          {kind: 'text', name: 'name', label: 'Student name', placeholder: 'Add later if not known'},
          {kind: 'text', name: 'grade', label: 'Class / grade', placeholder: 'e.g. Grade 8'},
          {kind: 'text', name: 'subject', label: 'Subject', placeholder: 'e.g. Mathematics'},
          {kind: 'text', name: 'syllabus', label: 'Syllabus', placeholder: 'e.g. CBSE'},
          {kind: 'text', name: 'source', label: 'Source', placeholder: 'Meta Ads, referral, WhatsApp…'},
          {kind: 'check', name: 'is_hot', label: 'Hot lead', description: 'Shows first in follow-up queues.'},
          {kind: 'date', name: 'enquiry_date', label: 'Enquiry date', required: true, max: today},
          {kind: 'text', name: 'country', label: 'Country code', description: 'IN for India; needed for WhatsApp links on 10-digit numbers.'},
          {kind: 'email', name: 'email', label: 'Email'},
          {kind: 'tel', name: 'student_phone', label: 'Student WhatsApp'},
          {kind: 'textarea', name: 'notes', label: 'Notes'},
          {kind: 'text', name: 'existing_lead_id', label: 'Existing lead number (repeat enquiry)', description: 'If this phone already has an enquiry, enter its number to record a repeat enquiry.'},
          {kind: 'check', name: 'create_separate', label: 'Separate student sharing this phone', description: 'For a sibling, keep the enquiries apart.'},
        ]}
        initial={{country: 'IN', enquiry_date: today}}
        action={businessAction(unitId, 'admission-create-lead')}
        submitLabel="Create lead"
        successMessage="Lead saved."
        onSaved={result => {
          onSaved();
          const id = (result as {id?: number} | null)?.id;
          if (id) navigate(`/app/u/${unitId}/admissions/${id}`);
        }}
      />
      {lead && (
        <>
          <ActionDrawer
            {...common}
            isOpen={kind === 'followup'}
            title="Log follow-up"
            description={`${lead.name}. Saving records the contact; it does not send a message.`}
            fields={[
              {kind: 'choice', name: 'channel', label: 'How did you contact them?', required: true, options: CHANNELS},
              {kind: 'select', name: 'outcome', label: 'Outcome', required: true, options: OUTCOMES},
              {kind: 'textarea', name: 'note', label: 'Conversation notes', required: true},
              {kind: 'date', name: 'next_due_on', label: 'Next follow-up', description: 'Keep a date for active conversations. Empty clears it.'},
              {kind: 'select', name: 'interest_status', label: 'Interest / obstacle', options: INTEREST, description: 'Leave empty to keep the current value.'},
            ]}
            hidden={leadId}
            initial={{channel: 'call', next_due_on: opt(lead.nextFollowUpOn)}}
            shortcuts={{next_due_on: dateShortcuts(timezone)}}
            action={businessAction(unitId, 'admission-followup')}
            submitLabel="Save follow-up"
            successMessage="Follow-up saved."
          />
          <ActionDrawer
            {...common}
            isOpen={kind === 'demo'}
            title="Schedule a demo"
            description={`${lead.name}. Pick the teacher in Teacher matching, which checks eligibility, availability and clashes.`}
            fields={[
              {kind: 'date', name: 'scheduled_on', label: 'Date', required: true, min: today},
              {kind: 'time', name: 'scheduled_time', label: `Time (${timezone})`, required: true},
              {kind: 'url', name: 'meet_link', label: 'Meeting link'},
              {kind: 'textarea', name: 'scheduling_notes', label: 'Notes for the team'},
            ]}
            hidden={leadId}
            initial={{scheduled_on: today}}
            shortcuts={{scheduled_on: dateShortcuts(timezone, [[0, 'Today'], [1, 'Tomorrow'], [2, 'In 2 days']])}}
            action={businessAction(unitId, 'admission-schedule-demo')}
            submitLabel="Schedule demo"
            successMessage="Demo scheduled."
          />
          <ActionDrawer
            {...common}
            isOpen={kind === 'reminder'}
            title="Add reminder"
            description={`${lead.name}. Appears in your reminders on the due date.`}
            fields={[
              {kind: 'text', name: 'text', label: 'Reminder', required: true, placeholder: 'e.g. Check parent feedback after the demo'},
              {kind: 'date', name: 'due_on', label: 'Due', required: true},
              {kind: 'time', name: 'due_time', label: `Time (${timezone})`},
            ]}
            hidden={leadId}
            initial={{due_on: today}}
            shortcuts={{due_on: dateShortcuts(timezone)}}
            action={businessAction(unitId, 'admission-reminder')}
            submitLabel="Add reminder"
            successMessage="Reminder added."
          />
          <ActionDrawer
            {...common}
            isOpen={kind === 'edit'}
            title="Edit lead"
            fields={[
              {kind: 'text', name: 'name', label: 'Student name'},
              {kind: 'tel', name: 'phone', label: 'Parent / contact phone', required: true},
              {kind: 'text', name: 'country', label: 'Country code', description: 'IN for a 10-digit Indian number, or include the calling code.'},
              {kind: 'email', name: 'email', label: 'Email'},
              {kind: 'text', name: 'grade', label: 'Class / grade'},
              {kind: 'text', name: 'subject', label: 'Subject'},
              {kind: 'text', name: 'syllabus', label: 'Syllabus'},
              {kind: 'text', name: 'source', label: 'Source'},
              {kind: 'tel', name: 'student_phone', label: 'Student WhatsApp'},
              {kind: 'textarea', name: 'notes', label: 'Notes'},
            ]}
            hidden={leadId}
            initial={{
              name: opt(lead.rawName), phone: opt(lead.phone), country: opt(lead.country), email: opt(lead.email), grade: opt(lead.grade),
              subject: opt(lead.subject), syllabus: opt(lead.syllabus), source: opt(lead.source), student_phone: opt(lead.studentPhone), notes: opt(lead.notes),
            }}
            action={businessAction(unitId, 'admission-update-lead')}
            sendEmpty
            submitLabel="Save lead"
            successMessage="Lead updated."
          />
          <ActionDrawer
            {...common}
            isOpen={kind === 'handoff'}
            title="Registration handoff"
            description="Carried to the student record when the lead is registered."
            fields={[
              {kind: 'textarea', name: 'learning_requirement', label: 'Learning needs'},
              {kind: 'textarea', name: 'concern', label: 'Parent concerns'},
              {kind: 'textarea', name: 'about_student', label: 'About the student'},
              {kind: 'textarea', name: 'about_parent', label: 'Parent communication preferences'},
            ]}
            hidden={leadId}
            initial={{learning_requirement: opt(lead.learningRequirement), concern: opt(lead.concern), about_student: opt(lead.aboutStudent), about_parent: opt(lead.aboutParent)}}
            action={businessAction(unitId, 'admission-update-lead')}
            sendEmpty
            submitLabel="Save notes"
            successMessage="Handoff notes saved."
          />
          <ActionDrawer
            {...common}
            isOpen={kind === 'lost'}
            title="Close as lost"
            description={`${lead.name}. The lead leaves active queues; you can reopen it later.`}
            fields={[{kind: 'textarea', name: 'lost_reason', label: 'Reason', required: true}]}
            hidden={{...leadId, status: 'LOST'}}
            action={businessAction(unitId, 'admission-update-lead')}
            submitLabel="Mark lost"
            submitVariant="destructive"
            successMessage="Lead closed as lost."
          />
          <ActionDrawer
            {...common}
            isOpen={kind === 'reopen'}
            title="Reopen lead"
            description={`${lead.name} goes back into the active pipeline.`}
            fields={[]}
            hidden={{...leadId, status: 'LEAD'}}
            action={businessAction(unitId, 'admission-update-lead')}
            submitLabel="Reopen"
            successMessage="Lead reopened."
          />
          <ActionDrawer
            {...common}
            isOpen={kind === 'convert'}
            title="Register as student"
            description="Creates the student and carries over the handoff notes. Teaching schedules are confirmed separately."
            intro={<Text color="secondary">Record a payment only if the money was already received. It needs a unique receipt reference and goes to the shared ledger.</Text>}
            fields={[
              {kind: 'text', name: 'student_name', label: 'Student name', required: true},
              {kind: 'date', name: 'joined_on', label: 'Joined on', required: true, max: today},
              {kind: 'text', name: 'student_code', label: 'Student code', placeholder: 'Generated if empty'},
              {kind: 'tel', name: 'student_phone', label: 'Student WhatsApp'},
              {kind: 'money', name: 'payment_amount_inr', label: 'Initial payment received (₹)'},
              {kind: 'text', name: 'payment_reference', label: 'Receipt reference'},
            ]}
            hidden={leadId}
            initial={{student_name: opt(lead.rawName), joined_on: today, student_phone: opt(lead.studentPhone)}}
            action={businessAction(unitId, 'admission-convert')}
            submitLabel="Register student"
            successMessage="Student registered."
          />
          <ActionDrawer
            {...common}
            isOpen={kind === 'transfer'}
            title="Transfer to a colleague"
            description="You keep the lead until your colleague accepts."
            fields={[{kind: 'select', name: 'to_user_id', label: 'Colleague', required: true, options: members}]}
            hidden={leadId}
            action={businessAction(unitId, 'admission-transfer')}
            submitLabel="Request transfer"
            successMessage="Transfer requested."
          />
        </>
      )}
      {demo && (
        <ActionDrawer
          {...common}
          isOpen={kind === 'demo-update'}
          title="Update demo"
          description="Record how the demo went, or move it to a new time."
          fields={[
            {kind: 'select', name: 'status', label: 'Status', required: true, options: [
              {value: 'ATTENDED', label: 'Attended'},
              {value: 'NO_SHOW', label: 'No show'},
              {value: 'RESCHEDULED', label: 'Rescheduled'},
              {value: 'CANCELLED', label: 'Cancelled'},
            ]},
            {kind: 'date', name: 'scheduled_on', label: 'Date', required: true},
            {kind: 'time', name: 'scheduled_time', label: `Time (${timezone})`, required: true},
          ]}
          hidden={{demo_id: demo.id}}
          initial={{status: 'ATTENDED', scheduled_on: demo.scheduledOn, scheduled_time: demo.scheduledTime}}
          action={businessAction(unitId, 'admission-update-demo')}
          submitLabel="Update demo"
          successMessage="Demo updated."
        />
      )}
    </>
  );
}
