import {useState} from 'react';
import {useParams} from 'react-router';
import {VStack, HStack} from '@astryxdesign/core/Layout';
import {Card} from '@astryxdesign/core/Card';
import {Heading, Text} from '@astryxdesign/core/Text';
import {Link} from '@astryxdesign/core/Link';
import {Button} from '@astryxdesign/core/Button';
import {MoreMenu} from '@astryxdesign/core/MoreMenu';
import {Banner} from '@astryxdesign/core/Banner';
import {MetadataList, MetadataListItem} from '@astryxdesign/core/MetadataList';
import {List, ListItem} from '@astryxdesign/core/List';
import {EmptyState} from '@astryxdesign/core/EmptyState';
import {Page, PageError, PageLoading} from '../../components/PageState';
import {Status} from '../../components/Status';
import {useResource} from '../../lib/hooks';
import {date, dateTime, titleCase} from '../../lib/format';
import {unitById} from '../../lib/session';
import {ActionDrawer, dateShortcuts} from './ActionDrawer';
import {businessAction, canAct} from './access';

interface CandidateProfile {
  candidate: {
    id: number;
    name: string;
    phone: string | null;
    email: string | null;
    teacherCode: string | null;
    teacherId: number | null;
    stage: string;
    mergedIntoId: number | null;
    qualification: string | null;
    subjects: string | null;
    grades: string | null;
    syllabuses: string | null;
    medium: string | null;
    availability: string | null;
    sourceStatus: string | null;
    cvUrl: string | null;
    registeredAt: string | null;
  };
  duplicates: {id: number; name: string; phone: string | null; teacherCode: string | null; stage: string}[];
  activities: {id: number; kind: string; note: string; nextFollowUpOn: string | null; createdAt: string; actorName: string | null}[];
  sources: {id: number; kind: string; sourceKey: string; createdAt: string; fields: Record<string, string>}[];
  can: {manage: boolean; qualify: boolean; openTeacher: boolean};
}

type FormKind = 'review' | 'contact' | 'register' | 'approve' | 'correct' | 'merge';

const show = (value: string | null | undefined, fallback = 'Not recorded') => (value && value.trim() !== '' ? value : fallback);
const KIND_LABELS: Record<string, string> = {
  'teacher-candidate-create': 'Application received',
  'teacher-candidate-update': 'Profile reviewed',
  'teacher-candidate-contact': 'Contacted',
  'teacher-candidate-register': 'Registration completed',
  'teacher-candidate-approve': 'Approved into teacher pool',
};

export default function CandidateDetail() {
  const params = useParams();
  const unitId = Number(params.unit);
  const unit = unitById(unitId);
  const {data, error, isLoading, reload} = useResource<CandidateProfile>(`/app-api/units/${unitId}/recruitment/${params.candidate}`);
  const [form, setForm] = useState<FormKind | null>(null);

  if (isLoading) return <PageLoading />;
  if (error || !data) return <Page title="Teacher profile"><PageError error={error ?? new Error('Not found')} onRetry={reload} /></Page>;

  const c = data.candidate;
  const writable = data.can.manage && canAct(unit, 'teacher-candidate-update');
  const live = !c.mergedIntoId;
  const registered = ['REGISTERED', 'APPROVED'].includes(c.stage);
  const hidden = {candidate_id: c.id};
  const close = (open: boolean) => (open ? undefined : setForm(null));
  const common = {unitId, onOpenChange: close, onSaved: reload, hidden};
  const more = writable && live
    ? [
        {label: 'Record contact', onClick: () => setForm('contact')},
        {label: 'Correct identity', onClick: () => setForm('correct')},
        {label: 'Merge into another profile', onClick: () => setForm('merge')},
      ]
    : [];

  return (
    <Page
      title={c.name}
      description={[c.teacherCode, titleCase(c.stage.toLowerCase())].filter(Boolean).join(' · ')}
      crumbs={[{label: 'Teacher hiring', href: `/app/u/${unitId}/recruitment`}, {label: c.name}]}
      actions={
        writable && live && (
          <>
            {c.stage === 'SELECTED' && <Button label="Confirm registration" variant="primary" onClick={() => setForm('register')} />}
            {registered && <Button label={c.stage === 'APPROVED' ? 'Re-check teacher link' : 'Approve into pool'} variant="primary" onClick={() => setForm('approve')} />}
            <Button label="Review profile" variant={c.stage === 'SELECTED' || registered ? 'secondary' : 'primary'} onClick={() => setForm('review')} />
            <MoreMenu label="More profile actions" items={more} alignment="end" />
          </>
        )
      }>
      {c.mergedIntoId && (
        <Banner status="info" title="This profile was merged" endContent={<Button label="Open surviving profile" variant="secondary" href={`/app/u/${unitId}/recruitment/${c.mergedIntoId}`} />} />
      )}
      {c.stage === 'REVIEW' && (
        <Banner status="warning" title="Identity needs review" description="Compare the source records and contact details, then correct the identity or merge it, before selecting or registering this teacher." />
      )}

      <Card padding={4}>
        <VStack gap={3}>
          <HStack gap={2} vAlign="center">
            <Heading level={2}>Profile</Heading>
            <Status value={c.stage.toLowerCase()} label={titleCase(c.stage.toLowerCase())} />
          </HStack>
          <MetadataList columns="multi" orientation="vertical" label={{position: 'top'}}>
            <MetadataListItem label="Phone">{c.phone ? <Link href={`tel:+${c.phone}`}>{c.phone}</Link> : 'Needs review'}</MetadataListItem>
            <MetadataListItem label="Email">{show(c.email)}</MetadataListItem>
            <MetadataListItem label="Qualification">{show(c.qualification)}</MetadataListItem>
            <MetadataListItem label="Subjects">{show(c.subjects)}</MetadataListItem>
            <MetadataListItem label="Classes">{show(c.grades)}</MetadataListItem>
            <MetadataListItem label="Syllabuses">{show(c.syllabuses)}</MetadataListItem>
            <MetadataListItem label="Medium">{show(c.medium)}</MetadataListItem>
            <MetadataListItem label="Preferred times">{show(c.availability, 'Confirm with teacher')}</MetadataListItem>
            <MetadataListItem label="Source status">{show(c.sourceStatus, 'Not set')}</MetadataListItem>
            <MetadataListItem label="Registered">{c.registeredAt ? date(c.registeredAt.slice(0, 10)) : 'Not yet'}</MetadataListItem>
            <MetadataListItem label="CV">{c.cvUrl ? <Link href={c.cvUrl} target="_blank">Open CV</Link> : 'Not provided'}</MetadataListItem>
            <MetadataListItem label="Teacher pool">
              {c.teacherId ? (data.can.openTeacher ? <Link href={`/app/u/${unitId}/teachers/${c.teacherId}`}>Open teacher</Link> : 'Linked') : 'Not linked'}
            </MetadataListItem>
          </MetadataList>
          {data.can.qualify && c.teacherId && (
            <Text color="secondary">
              Next: <Link href={`/app/u/${unitId}/matching?prepare=${c.teacherId}`}>confirm subject eligibility and availability in Teacher matching</Link>.
            </Text>
          )}
          <Text color="secondary" size="sm">Registration and availability do not authorise class assignment. Subject eligibility and timetable confirmation still apply.</Text>
        </VStack>
      </Card>

      {data.duplicates.length > 0 && (
        <Card padding={4}>
          <VStack gap={3}>
            <Heading level={2}>Possible duplicates</Heading>
            <List hasDividers edge="flush">
              {data.duplicates.map(d => (
                <ListItem key={d.id} label={`#${d.id} ${d.name}`} description={[d.phone, d.teacherCode, titleCase(d.stage.toLowerCase())].filter(Boolean).join(' · ')} href={`/app/u/${unitId}/recruitment/${d.id}`} />
              ))}
            </List>
          </VStack>
        </Card>
      )}

      <Card padding={4}>
        <VStack gap={3}>
          <Heading level={2}>Review history</Heading>
          {data.activities.length === 0 ? (
            <EmptyState title="No contacts recorded yet" isCompact />
          ) : (
            <List hasDividers edge="flush">
              {data.activities.map(a => (
                <ListItem
                  key={a.id}
                  label={KIND_LABELS[a.kind] ?? titleCase(a.kind)}
                  description={<Text type="supporting">{`${a.note}${a.nextFollowUpOn ? ` — follow up ${date(a.nextFollowUpOn)}` : ''}`}</Text>}
                  endContent={<Text size="sm" color="secondary">{`${dateTime(a.createdAt)} · ${a.actorName ?? 'System'}`}</Text>}
                />
              ))}
            </List>
          )}
        </VStack>
      </Card>

      {data.sources.length > 0 && (
        <Card padding={4}>
          <VStack gap={3}>
            <Heading level={2}>Imported source records</Heading>
            <List hasDividers edge="flush">
              {data.sources.map(s => (
                <ListItem key={s.id} label={`${s.kind} · ${s.sourceKey}`} description={Object.entries(s.fields).map(([k, v]) => `${titleCase(k)}: ${v}`).join(' · ') || '—'} />
              ))}
            </List>
          </VStack>
        </Card>
      )}

      {writable && live && (
        <>
          <ActionDrawer
            {...common}
            isOpen={form === 'review'}
            title="Review profile"
            fields={[
              ...(registered ? [] : [{kind: 'select' as const, name: 'stage', label: 'Application stage', options: ['APPLIED', 'CONTACTED', 'SELECTED', 'REJECTED'].map(s => ({value: s, label: titleCase(s.toLowerCase())})), description: 'Leave empty to keep the current stage.'}]),
              {kind: 'text', name: 'qualification', label: 'Qualification'},
              {kind: 'text', name: 'subjects', label: 'Subjects'},
              {kind: 'text', name: 'grades', label: 'Classes'},
              {kind: 'text', name: 'syllabuses', label: 'Syllabuses'},
              {kind: 'text', name: 'medium', label: 'Medium'},
              {kind: 'text', name: 'availability', label: 'Preferred times'},
              {kind: 'email', name: 'email', label: 'Email'},
              {kind: 'url', name: 'cv_url', label: 'CV link'},
              {kind: 'textarea', name: 'note', label: 'Review note'},
            ]}
            initial={{
              stage: ['APPLIED', 'CONTACTED', 'SELECTED', 'REJECTED'].includes(c.stage) ? c.stage : '',
              qualification: c.qualification ?? '', subjects: c.subjects ?? '', grades: c.grades ?? '', syllabuses: c.syllabuses ?? '',
              medium: c.medium ?? '', availability: c.availability ?? '', email: c.email ?? '', cv_url: c.cvUrl ?? '',
            }}
            action={businessAction(unitId, 'teacher-candidate-update')}
            sendEmpty
            submitLabel="Save review"
            successMessage="Teacher profile saved."
          />
          <ActionDrawer
            {...common}
            isOpen={form === 'contact'}
            title="Record contact"
            description="An application moves to Contacted the first time."
            fields={[
              {kind: 'textarea', name: 'note', label: 'What happened', required: true},
              {kind: 'date', name: 'next_follow_up_on', label: 'Next follow-up'},
            ]}
            shortcuts={unit ? {next_follow_up_on: dateShortcuts(unit.timezone)} : undefined}
            action={businessAction(unitId, 'teacher-candidate-contact')}
            submitLabel="Save contact"
            successMessage="Contact recorded."
          />
          <ActionDrawer
            {...common}
            isOpen={form === 'register'}
            title="Confirm registration"
            description={`${c.name} has completed registration. Approval into the teacher pool is a separate step.`}
            fields={[{kind: 'textarea', name: 'note', label: 'Note'}]}
            action={businessAction(unitId, 'teacher-candidate-register')}
            submitLabel="Confirm registration"
            successMessage="Registration recorded."
          />
          <ActionDrawer
            {...common}
            isOpen={form === 'approve'}
            title="Approve into teacher pool"
            description="Links this profile to the teacher with this code in this team, creating the teacher if it does not exist yet."
            fields={[{kind: 'text', name: 'teacher_code', label: 'Teacher code', required: true, placeholder: 'e.g. CT104'}]}
            initial={{teacher_code: c.teacherCode ?? ''}}
            action={businessAction(unitId, 'teacher-candidate-approve')}
            submitLabel="Approve"
            successMessage="Teacher approved and linked."
          />
          <ActionDrawer
            {...common}
            isOpen={form === 'correct'}
            title="Correct identity"
            description="Fix the verified name, phone or code. Existing teacher links cannot be reassigned here."
            fields={[
              {kind: 'text', name: 'name', label: 'Verified name', required: true},
              {kind: 'tel', name: 'phone', label: 'Verified phone', required: true},
              {kind: 'text', name: 'teacher_code', label: 'Verified teacher code', description: 'Clear an incorrect, unlinked code by leaving this empty.'},
              {kind: 'textarea', name: 'note', label: 'Verification evidence', required: true},
            ]}
            hidden={{...hidden, decision: 'correct'}}
            initial={{name: c.name, phone: c.phone ?? '', teacher_code: c.teacherCode ?? ''}}
            action={businessAction(unitId, 'teacher-candidate-resolve')}
            sendEmpty
            submitLabel="Save identity"
            successMessage="Identity resolved."
          />
          <ActionDrawer
            {...common}
            isOpen={form === 'merge'}
            title="Merge into another profile"
            description="This profile's history moves to the surviving profile. Source rows are kept."
            fields={[
              {kind: 'text', name: 'target_id', label: 'Surviving profile number', required: true, description: data.duplicates.length ? `Possible: ${data.duplicates.map(d => `#${d.id}`).join(', ')}` : undefined},
              {kind: 'textarea', name: 'note', label: 'Evidence these are the same teacher', required: true},
            ]}
            hidden={{...hidden, decision: 'merge'}}
            action={businessAction(unitId, 'teacher-candidate-resolve')}
            submitLabel="Merge"
            submitVariant="destructive"
            successMessage="Profiles merged."
          />
        </>
      )}
    </Page>
  );
}
