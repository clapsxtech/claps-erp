// /resultform: a teacher uploads one test result. Saves through the classic
// POST /upload-result, which re-verifies the teacher/student pair and writes
// the record and its integration outbox event once per submission key.
import {useRef, useState} from 'react';
import {VStack, HStack, StackItem} from '@astryxdesign/core/Layout';
import {Heading, Text} from '@astryxdesign/core/Text';
import {Card} from '@astryxdesign/core/Card';
import {Banner} from '@astryxdesign/core/Banner';
import {Button} from '@astryxdesign/core/Button';
import {Selector} from '@astryxdesign/core/Selector';
import {TextInput} from '@astryxdesign/core/TextInput';
import {TextArea} from '@astryxdesign/core/TextArea';
import {DateInput} from '@astryxdesign/core/DateInput';
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
  today,
  useForgetOnLeave,
  type ISODate,
  type Verified,
} from './shared';

// The exact values the server accepts (ResultInput), casing included.
const MODES = ['Viva (live)', 'Written (live)', 'written (offline)', 'written + Viva(live)'];

const FIELDS = ['student_name', 'student_code', 'subject', 'chapter', 'mode_of_exam', 'examination_date', 'mark_scored', 'total_mark', 'remark'];

interface Draft {
  student?: string;
  subject?: string;
  chapter: string;
  mode_of_exam?: string;
  examination_date?: ISODate;
  mark_scored: string;
  total_mark: string;
  remark: string;
}

const blank = (): Draft => ({chapter: '', examination_date: today(), mark_scored: '', total_mark: '', remark: ''});
const decimal = (value: string) => value.replace(/[^0-9.]/g, '').replace(/(\..*)\./g, '$1');

export default function Result() {
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
    if (!draft.subject) missing.subject = 'Choose the subject.';
    if (!draft.chapter.trim()) missing.chapter = 'Enter the chapter or topic.';
    if (!draft.mode_of_exam) missing.mode_of_exam = 'Choose how the test was taken.';
    if (!draft.examination_date) missing.examination_date = 'Choose the test date.';
    if (!draft.mark_scored) missing.mark_scored = 'Enter the mark scored.';
    if (!draft.total_mark) missing.total_mark = 'Enter the total mark.';
    else if (draft.mark_scored && Number(draft.mark_scored) > Number(draft.total_mark)) missing.mark_scored = 'The mark cannot be more than the total.';
    setErrors(missing);
    setBanner(undefined);
    if (Object.keys(missing).length) return;
    inFlight.current = true;
    setSaving(true);
    try {
      const result = await postForm<{success: boolean; id: number; replayed?: boolean; next_submission_key?: string}>('/upload-result', {
        submission_key: key.current,
        teacher_name: verified.teacherName,
        teacher_code: verified.teacherCode,
        password: verified.password,
        student_name: student!.student_name,
        student_code: student!.student_code,
        subject: draft.subject,
        chapter: draft.chapter.trim(),
        mode_of_exam: draft.mode_of_exam,
        examination_date: draft.examination_date,
        mark_scored: draft.mark_scored,
        total_mark: draft.total_mark,
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

  const description = 'Upload a test result for one of your students.';

  if (saved && verified) {
    return (
      <PublicLayout title="Upload a result" description={description}>
        <Saved
          title="Result saved"
          detail="The result is saved and will be copied to the results sheet shortly."
          reference={`RS-${saved.id}`}
          replayed={saved.replayed}
          againLabel="Upload another result"
          onAgain={() => {
            setSaved(null);
            setDraft(current => ({...blank(), subject: current.subject, mode_of_exam: current.mode_of_exam, examination_date: current.examination_date}));
          }}
          onFinish={forget}
        />
      </PublicLayout>
    );
  }

  return (
    <PublicLayout title="Upload a result" description={description}>
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
              <Heading level={2}>Result details</Heading>
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
                  label="Subject"
                  isRequired
                  placeholder="Choose the subject"
                  options={(session.subjects ?? []).map(s => ({value: String(s.id), label: s.name}))}
                  value={draft.subject}
                  onChange={value => set('subject')(value ?? undefined)}
                  hasSearch={(session.subjects ?? []).length > 8}
                  presentation="adaptive"
                  width="100%"
                  emptyText="No subjects are set up yet."
                  status={errorStatus(errors.subject)}
                />
                <TextInput label="Chapter or topic" isRequired placeholder="What the test covered" value={draft.chapter} onChange={set('chapter')} status={errorStatus(errors.chapter)} />
                <Selector
                  label="Mode of exam"
                  isRequired
                  placeholder="Choose"
                  options={MODES.map(mode => ({value: mode, label: mode}))}
                  value={draft.mode_of_exam}
                  onChange={value => set('mode_of_exam')(value ?? undefined)}
                  presentation="adaptive"
                  width="100%"
                  status={errorStatus(errors.mode_of_exam)}
                />
                <DateInput label="Date of the test" isRequired value={draft.examination_date} onChange={value => set('examination_date')(value)} status={errorStatus(errors.examination_date)} />
                <HStack gap={3} vAlign="start">
                  <StackItem size="fill">
                    <TextInput label="Mark scored" isRequired inputMode="decimal" placeholder="0" value={draft.mark_scored} onChange={value => set('mark_scored')(decimal(value))} status={errorStatus(errors.mark_scored)} />
                  </StackItem>
                  <StackItem size="fill">
                    <TextInput label="Out of" isRequired inputMode="decimal" placeholder="0" value={draft.total_mark} onChange={value => set('total_mark')(decimal(value))} status={errorStatus(errors.total_mark)} />
                  </StackItem>
                </HStack>
                <TextArea label="Remarks" isOptional value={draft.remark} onChange={set('remark')} status={errorStatus(errors.remark)} />
                <Button label="Save result" variant="primary" size="lg" width="100%" isLoading={saving} isDisabled={session.readOnly} onClick={save} />
              </>
            )}
          </VStack>
        </Card>
      )}
    </PublicLayout>
  );
}
