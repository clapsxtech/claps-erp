import {useState} from 'react';
import {useParams} from 'react-router';
import {VStack, HStack} from '@astryxdesign/core/Layout';
import {Card} from '@astryxdesign/core/Card';
import {Heading, Text} from '@astryxdesign/core/Text';
import {Link} from '@astryxdesign/core/Link';
import {Button} from '@astryxdesign/core/Button';
import {MoreMenu} from '@astryxdesign/core/MoreMenu';
import {Token} from '@astryxdesign/core/Token';
import {Banner} from '@astryxdesign/core/Banner';
import {MetadataList, MetadataListItem} from '@astryxdesign/core/MetadataList';
import {Table} from '@astryxdesign/core/Table';
import {List, ListItem} from '@astryxdesign/core/List';
import {EmptyState} from '@astryxdesign/core/EmptyState';
import {useToast} from '@astryxdesign/core/Toast';
import {Page, PageError, PageLoading} from '../../components/PageState';
import {Status} from '../../components/Status';
import {Toned} from '../../components/Amount';
import {useResource} from '../../lib/hooks';
import {ApiError, postJson} from '../../lib/api';
import {date, dateTime, titleCase} from '../../lib/format';
import {unitById} from '../../lib/session';
import {FollowUpCell, TransferActions, useResolveTransfer, type AdmissionsCan, type LeadRow, type TransferRow} from './Admissions';
import {LeadForms, type DemoTarget, type LeadFormKind} from './LeadForms';
import {businessAction} from './access';

interface LeadProfile {
  lead: LeadRow & {
    rawName: string;
    email: string | null;
    country: string | null;
    studentPhone: string | null;
    notes: string | null;
    lostReason: string | null;
    joinedOn: string | null;
    admissionCode: string | null;
    studentId: number | null;
    studentCode: string | null;
    learningRequirement: string | null;
    concern: string | null;
    aboutStudent: string | null;
    aboutParent: string | null;
    awaitingAllocation: boolean;
    joinedMessage: string | null;
  };
  activities: {id: number; kind: string; channel: string | null; outcome: string | null; summary: string; occurredAt: string; actorName: string | null}[];
  demos: {id: number; scheduledOn: string; scheduledTime: string; status: string; meetLink: string | null; isMatched: boolean; feedbackOutcome: string | null; feedbackNote: string | null; teacherName: string | null; subjectName: string | null}[];
  reminders: {id: number; dueOn: string; dueTime: string | null; text: string; isOverdue: boolean}[];
  transfers: TransferRow[];
  members: {value: string; label: string}[];
  can: AdmissionsCan;
  today: string;
  timezone: string;
}

const show = (value: string | null | undefined, fallback = 'Not recorded') => (value && value.trim() !== '' ? value : fallback);

export default function LeadDetail() {
  const params = useParams();
  const unitId = Number(params.unit);
  const unit = unitById(unitId);
  const showToast = useToast();
  const {data, error, isLoading, reload} = useResource<LeadProfile>(`/app-api/units/${unitId}/admissions/${params.lead}`);
  const [form, setForm] = useState<{kind: LeadFormKind; demo?: DemoTarget} | null>(null);
  const resolve = useResolveTransfer(unitId, reload);

  if (isLoading) return <PageLoading />;
  if (error || !data) return <Page title="Lead"><PageError error={error ?? new Error('Not found')} onRetry={reload} /></Page>;

  const {lead, can} = data;
  const active = lead.status === 'LEAD';
  const editable = lead.status !== 'JOINED' && can.updateLead;
  const open = (kind: LeadFormKind) => setForm({kind});

  async function toggleHot() {
    try {
      await postJson(businessAction(unitId, 'admission-update-lead'), {lead_id: lead.id, is_hot: lead.isHot ? 0 : 1});
      showToast({body: lead.isHot ? 'Hot priority removed.' : 'Marked as a hot lead.', uniqueID: `hot-${lead.id}`});
      reload();
    } catch (reason) {
      showToast({body: reason instanceof ApiError ? reason.message : 'Could not reach the server. Nothing was changed.', uniqueID: `hot-error-${lead.id}`});
    }
  }

  async function completeReminder(id: number) {
    try {
      await postJson(businessAction(unitId, 'admission-complete-reminder'), {reminder_id: id});
      showToast({body: 'Reminder done.', uniqueID: `reminder-${id}`});
      reload();
    } catch (reason) {
      showToast({body: reason instanceof ApiError ? reason.message : 'Could not reach the server. Nothing was changed.', uniqueID: `reminder-error-${id}`});
    }
  }

  const more = [
    ...(lead.whatsapp ? [{label: 'WhatsApp', href: lead.whatsapp, target: '_blank' as const, rel: 'noopener noreferrer'}] : []),
    ...(lead.email ? [{label: 'Email', href: `mailto:${lead.email}`}] : []),
    ...(can.reminder ? [{label: 'Add reminder', onClick: () => open('reminder')}] : []),
    ...(editable ? [{label: 'Edit details', onClick: () => open('edit')}, {label: lead.isHot ? 'Remove hot priority' : 'Mark as hot', onClick: toggleHot}, {label: 'Handoff notes', onClick: () => open('handoff')}] : []),
    ...(can.transfer && lead.status !== 'JOINED' ? [{label: 'Transfer to a colleague', onClick: () => open('transfer')}] : []),
    ...(editable && lead.status === 'LOST' ? [{label: 'Reopen lead', onClick: () => open('reopen')}] : []),
    ...(editable && active ? [{type: 'divider' as const}, {label: 'Close as lost', variant: 'destructive' as const, onClick: () => open('lost')}] : []),
  ];

  return (
    <Page
      title={lead.name}
      description={[`Lead #${lead.id}`, lead.grade, lead.subject, lead.syllabus].filter(Boolean).join(' · ')}
      crumbs={[{label: 'Admissions', href: `/app/u/${unitId}/admissions`}, {label: lead.name}]}
      actions={
        <>
          <Button label="Call" variant="secondary" href={`tel:${lead.dial}`} />
          {can.followup && <Button label="Log follow-up" variant="primary" onClick={() => open('followup')} />}
          {active && can.scheduleDemo && <Button label="Schedule demo" variant="secondary" onClick={() => open('demo')} />}
          {active && can.convert && <Button label="Register student" variant="secondary" onClick={() => open('convert')} />}
          {active && <Button label="Send teacher profile" variant="secondary" href={`/app/u/${unitId}/admissions/teachers?for_lead=${lead.id}`} />}
          {more.length > 0 && <MoreMenu label="More lead actions" items={more} alignment="end" />}
        </>
      }>
      {lead.status === 'JOINED' && (
        <Banner
          status="success"
          title={`Registered ${date(lead.joinedOn)}${lead.admissionCode ? ` · ${lead.admissionCode}` : ''}`}
          description="Share the registration details with the admin group on WhatsApp."
          endContent={
            <HStack gap={2}>
              {lead.joinedMessage && <Button label="Share on WhatsApp" variant="secondary" href={lead.joinedMessage} target="_blank" />}
              {lead.studentId && can.openStudent && <Button label="Open student" variant="secondary" href={`/app/u/${unitId}/students/${lead.studentId}`} />}
            </HStack>
          }
        />
      )}
      {lead.status === 'LOST' && <Banner status="warning" title="Closed as lost" description={show(lead.lostReason)} />}
      {lead.awaitingAllocation && <Banner status="info" title="Demo went well" description="Academics will allocate the regular class in Teacher matching." />}

      <Card padding={4}>
        <VStack gap={3}>
          <HStack gap={2} vAlign="center">
            <Heading level={2}>Next step</Heading>
            <Status value={lead.stage} label={lead.stageLabel} />
            {lead.isHot && <Token label="Hot" size="sm" color="orange" />}
          </HStack>
          <MetadataList columns="multi" orientation="vertical" label={{position: 'top'}}>
            <MetadataListItem label="Next follow-up"><FollowUpCell row={lead} /></MetadataListItem>
            <MetadataListItem label="Interest">{show(lead.interestStatus)}</MetadataListItem>
            <MetadataListItem label="Owner">{show(lead.assignedName, 'Unassigned')}</MetadataListItem>
            <MetadataListItem label="Phone"><Link href={`tel:${lead.dial}`}>{lead.phone}</Link></MetadataListItem>
            <MetadataListItem label="Student WhatsApp">{show(lead.studentPhone)}</MetadataListItem>
            <MetadataListItem label="Email">{show(lead.email)}</MetadataListItem>
            <MetadataListItem label="Source">{show(lead.source, 'Not set')}</MetadataListItem>
            <MetadataListItem label="Enquired">{date(lead.enquiryDate)}</MetadataListItem>
          </MetadataList>
          {lead.notes && <Text color="secondary">{lead.notes}</Text>}
        </VStack>
      </Card>

      {data.transfers.length > 0 && (
        <Card padding={4}>
          <VStack gap={3}>
            <Heading level={2}>Pending transfer</Heading>
            <List hasDividers edge="flush">
              {data.transfers.map(t => (
                <ListItem key={t.id} label={`${t.fromName ?? 'Previous owner'} → ${t.toName ?? 'Colleague'}`} description={`Requested ${dateTime(t.createdAt)}`} endContent={<TransferActions row={t} onResolve={resolve} />} />
              ))}
            </List>
          </VStack>
        </Card>
      )}

      <Card padding={4}>
        <VStack gap={3}>
          <HStack gap={2} vAlign="center" hAlign="between">
            <Heading level={2}>Reminders</Heading>
            {can.reminder && <Button label="Add reminder" size="sm" variant="ghost" onClick={() => open('reminder')} />}
          </HStack>
          {data.reminders.length === 0 ? (
            <EmptyState title="No open reminders" isCompact />
          ) : (
            <List hasDividers edge="flush">
              {data.reminders.map(r => (
                <ListItem
                  key={r.id}
                  label={r.text}
                  description={<Toned tone={r.isOverdue ? 'error' : undefined}>{`${date(r.dueOn)}${r.dueTime ? ` · ${r.dueTime}` : ''}${r.isOverdue ? ' · overdue' : ''}`}</Toned>}
                  endContent={can.completeReminder ? <Button label="Done" size="sm" variant="secondary" onClick={() => completeReminder(r.id)} /> : undefined}
                />
              ))}
            </List>
          )}
        </VStack>
      </Card>

      <Card padding={4}>
        <VStack gap={3}>
          <HStack gap={2} vAlign="center" hAlign="between">
            <Heading level={2}>Demos</Heading>
            {can.matching && active && <Link href={`/app/u/${unitId}/matching?lead_id=${lead.id}`}>Find a teacher in Teacher matching</Link>}
          </HStack>
          {data.demos.length === 0 ? (
            <EmptyState title="No demos yet" description="Schedule a demo when the family is ready." isCompact
              actions={active && can.scheduleDemo ? <Button label="Schedule demo" variant="secondary" onClick={() => open('demo')} /> : undefined} />
          ) : (
            <Table
              data={data.demos}
              idKey="id"
              textOverflow="truncate"
              columns={[
                {key: 'scheduledOn', header: 'When', renderCell: d => <Text hasTabularNumbers>{`${date(d.scheduledOn)} · ${d.scheduledTime}`}</Text>},
                {key: 'teacherName', header: 'Teacher', renderCell: d => <Text>{d.teacherName ?? 'Not assigned'}</Text>},
                {key: 'subjectName', header: 'Subject', renderCell: d => <Text>{d.subjectName ?? '—'}</Text>},
                {key: 'status', header: 'Status', renderCell: d => <Status value={d.status.toLowerCase()} />},
                {key: 'feedbackOutcome', header: 'Feedback', renderCell: d => <Text color="secondary">{[d.feedbackOutcome, d.feedbackNote].filter(Boolean).join(' · ') || '—'}</Text>},
                {
                  key: 'id',
                  header: '',
                  align: 'end',
                  renderCell: d => (
                    <HStack gap={1} hAlign="end">
                      {d.meetLink && <Button label="Join" size="sm" variant="ghost" href={d.meetLink} target="_blank" />}
                      {!d.isMatched && can.updateDemo && <Button label="Update" size="sm" variant="secondary" onClick={() => setForm({kind: 'demo-update', demo: {id: d.id, scheduledOn: d.scheduledOn, scheduledTime: d.scheduledTime}})} />}
                      {d.isMatched && can.matching && <Button label="Feedback" size="sm" variant="secondary" href={`/app/u/${unitId}/matching?lead_id=${lead.id}&demo_id=${d.id}`} />}
                    </HStack>
                  ),
                },
              ]}
            />
          )}
        </VStack>
      </Card>

      <Card padding={4}>
        <VStack gap={3}>
          <HStack gap={2} vAlign="center" hAlign="between">
            <Heading level={2}>Registration handoff</Heading>
            {editable && <Button label="Edit notes" size="sm" variant="ghost" onClick={() => open('handoff')} />}
          </HStack>
          <MetadataList columns="multi" orientation="vertical" label={{position: 'top'}}>
            <MetadataListItem label="Learning needs">{show(lead.learningRequirement)}</MetadataListItem>
            <MetadataListItem label="Parent concerns">{show(lead.concern)}</MetadataListItem>
            <MetadataListItem label="About the student">{show(lead.aboutStudent)}</MetadataListItem>
            <MetadataListItem label="Parent preferences">{show(lead.aboutParent)}</MetadataListItem>
          </MetadataList>
        </VStack>
      </Card>

      <Card padding={4}>
        <VStack gap={3}>
          <Heading level={2}>Activity</Heading>
          {data.activities.length === 0 ? (
            <EmptyState title="No activity yet" isCompact />
          ) : (
            <List hasDividers edge="flush">
              {data.activities.map(a => (
                <ListItem
                  key={a.id}
                  label={titleCase(a.kind)}
                  description={<Text type="supporting">{[a.summary, a.channel || a.outcome ? `${titleCase(a.channel ?? '')} · ${a.outcome ?? ''}` : null].filter(Boolean).join(' — ')}</Text>}
                  endContent={<Text color="secondary" size="sm">{`${dateTime(a.occurredAt)} · ${a.actorName ?? 'System'}`}</Text>}
                />
              ))}
            </List>
          )}
        </VStack>
      </Card>

      {unit && (
        <LeadForms
          unitId={unitId}
          timezone={data.timezone}
          kind={form?.kind ?? null}
          lead={{...lead, rawName: lead.rawName}}
          demo={form?.demo}
          members={data.members}
          onClose={() => setForm(null)}
          onSaved={reload}
        />
      )}
    </Page>
  );
}
