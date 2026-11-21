// /performance: a teacher records a performance remark for a student on a
// topic. Saves through the classic POST /performnce-upload (verified pair,
// one record and outbox event per submission key).
import {useRef, useState} from 'react';
import {VStack} from '@astryxdesign/core/Layout';
import {Heading, Text} from '@astryxdesign/core/Text';
import {Card} from '@astryxdesign/core/Card';
import {Banner} from '@astryxdesign/core/Banner';
import {Button} from '@astryxdesign/core/Button';
import {Selector} from '@astryxdesign/core/Selector';
import {TextArea} from '@astryxdesign/core/TextArea';
import {
  FormError,
  IdentifyStep,
  PublicLayout,
  ReadOnlyNotice,
  Saved,
  VerifiedAs,
  errorStatus,
  newSubmissionKey,
  postForm,
  publicSession,
  splitErrors,
  studentOptions,
  useForgetOnLeave,
  type Verified,
} from './shared';

const FIELDS = ['student_name', 'student_code', 'topic', 'remark'];

interface Draft {
  student?: string;
  topic?: string;
  remark: string;
}

const blank = (): Draft => ({remark: ''});

export default function Performance() {
  const session = publicSession();
  const [verified, setVerified] = useState<Verified | null>(null);
  const [draft, setDraft] = useState<Draft>(blank);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [banner, setBanner] = useState<string>();
  const [saving, setSaving] = useState(false);
  const [saved, setSaved] = useState<{id: number; replayed: boolean} | null>(null);
  const key = useRef(newSubmissionKey());
  const inFlight = useRef(false);

  function forget() {
    setVerified(null);
    setDraft(blank());
    setErrors({});
    setBanner(undefined);
    setSaved(null);
    key.current = newSubmissionKey();
  }
  useForgetOnLeave(forget);

  const set = <K extends keyof Draft>(name: K) => (value: Draft[K]) => {
    setDraft(current => ({...current, [name]: value}));
    setErrors(current => ({...current, [name === 'student' ? 'student_name' : name]: ''}));
  };

  async function save() {
    if (!verified || inFlight.current) return;
    const student = draft.student !== undefined ? verified.students[Number(draft.student)] : undefined;
    const missing: Record<string, string> = {};
    if (!student) missing.student_name = 'Choose the student.';
    if (!draft.topic) missing.topic = 'Choose the topic.';
    setErrors(missing);
    setBanner(undefined);
    if (Object.keys(missing).length) return;
    inFlight.current = true;
    setSaving(true);
    try {
      const result = await postForm<{success: boolean; id: number; replayed?: boolean; next_submission_key?: string}>('/performnce-upload', {
        submission_key: key.current,
        teacher_name: verified.teacherName,
        teacher_code: verified.teacherCode,
        password: verified.password,
        student_name: student!.student_name,
        student_code: student!.student_code,
        topic: draft.topic,
        remark: draft.remark.trim() || null,
      });
      key.current = result.next_submission_key || newSubmissionKey();
      setSaved({id: result.id, replayed: Boolean(result.replayed)});
    } catch (reason) {
      if (reason instanceof FormError) {
        if (reason.status === 403) setVerified(null);
        const {placed, banner: message} = splitErrors(reason, FIELDS);
        setErrors(placed);
        setBanner(message);
      } else {
        setBanner('Something went wrong. Press Save again; it will not be saved twice.');
      }
    } finally {
      inFlight.current = false;
      setSaving(false);
    }
  }

  const description = 'Record how a student is doing on a topic.';

  if (saved && verified) {
    return (
      <PublicLayout title="Performance remark" description={description}>
        <Saved
          title="Remark saved"
          detail="The remark is saved and will be copied to the results sheet shortly."
          reference={`PF-${saved.id}`}
          replayed={saved.replayed}
          againLabel="Add another remark"
          onAgain={() => {
            setSaved(null);
            setDraft(current => ({...blank(), student: current.student}));
          }}
          onFinish={forget}
        />
      </PublicLayout>
    );
  }

  return (
    <PublicLayout title="Performance remark" description={description}>
      {session.readOnly && <ReadOnlyNotice />}
      {!verified ? (
        <VStack gap={4}>
          {banner && <Banner status="error" title={banner} />}
          <IdentifyStep
            teachers={session.teachers ?? []}
            lookupUrl="/studentinforesult"
            onVerified={next => {
              setBanner(undefined);
              setVerified(next);
              setDraft(current => ({...current, student: next.students.length === 1 ? '0' : undefined}));
            }}
          />
        </VStack>
      ) : (
        <Card padding={5}>
          <VStack gap={4}>
            <VStack gap={1}>
              <Heading level={2}>Remark</Heading>
              <Text color="secondary">Step 2 of 2.</Text>
            </VStack>
            <VerifiedAs name={verified.teacherName} onChange={forget} />
            {banner && <Banner status="error" title={banner} />}
            {verified.students.length === 0 ? (
              <Banner status="info" title="No students are assigned to you yet" description="Ask the office to assign your students, then try again." />
            ) : (
              <>
                <Selector
                  label="Student"
                  isRequired
                  placeholder="Choose the student"
                  options={studentOptions(verified.students)}
                  value={draft.student}
                  onChange={value => set('student')(value ?? undefined)}
                  hasSearch={verified.students.length > 8}
                  presentation="adaptive"
                  width="100%"
                  status={errorStatus(errors.student_name || errors.student_code)}
                />
                <Selector
                  label="Topic"
                  isRequired
                  placeholder="Choose the topic"
                  options={(session.topics ?? []).map(t => ({value: String(t.id), label: t.name}))}
                  value={draft.topic}
                  onChange={value => set('topic')(value ?? undefined)}
                  hasSearch={(session.topics ?? []).length > 8}
                  presentation="adaptive"
                  width="100%"
                  emptyText="No topics are set up yet."
                  status={errorStatus(errors.topic)}
                />
                <TextArea label="Remarks" isOptional value={draft.remark} onChange={set('remark')} status={errorStatus(errors.remark)} />
                <Button label="Save remark" variant="primary" size="lg" width="100%" isLoading={saving} isDisabled={session.readOnly} onClick={save} />
              </>
            )}
          </VStack>
        </Card>
      )}
    </PublicLayout>
  );
}
