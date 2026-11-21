// /attendance: a teacher records one lesson (work hours). Saves through the
// classic POST /upload-workhour, which re-verifies the teacher, prices the
// lesson from the assignment and de-duplicates by submission key.
import {useRef, useState} from 'react';
import {VStack} from '@astryxdesign/core/Layout';
import {Heading, Text} from '@astryxdesign/core/Text';
import {Card} from '@astryxdesign/core/Card';
import {Banner} from '@astryxdesign/core/Banner';
import {Button} from '@astryxdesign/core/Button';
import {Selector} from '@astryxdesign/core/Selector';
import {TextInput} from '@astryxdesign/core/TextInput';
import {DateInput} from '@astryxdesign/core/DateInput';
import {RadioList, RadioListItem} from '@astryxdesign/core/RadioList';
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

const DURATIONS = [
  {value: '0.5', label: '30 minutes'},
  {value: '0.75', label: '45 minutes'},
  {value: '1', label: '1 hour'},
  {value: '1.25', label: '1 hour 15 minutes'},
  {value: '1.5', label: '1 hour 30 minutes'},
  {value: '2', label: '2 hours'},
];

const FIELDS = ['student_name', 'student_code', 'subject', 'date', 'chapter', 'topic', 'hour_taken', 'homework_status'];

interface Draft {
  student?: string;
  subject?: string;
  date?: ISODate;
  chapter: string;
  topic: string;
  hour_taken?: string;
  homework_status: string;
}

const blank = (): Draft => ({date: today(), chapter: '', topic: '', homework_status: ''});

export default function Attendance() {
  const session = publicSession();
  const [verified, setVerified] = useState<Verified | null>(null);
  const [draft, setDraft] = useState<Draft>(blank);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [banner, setBanner] = useState<string>();
  const [saving, setSaving] = useState(false);
  const [saved, setSaved] = useState<{id: number} | null>(null);
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
    if (!draft.date) missing.date = 'Choose the class date.';
    if (!draft.chapter.trim()) missing.chapter = 'Enter the chapter.';
    if (!draft.topic.trim()) missing.topic = 'Enter the topic.';
    if (!draft.hour_taken) missing.hour_taken = 'Choose how long the lesson was.';
    if (!draft.homework_status) missing.homework_status = 'Say whether homework was set.';
    setErrors(missing);
    setBanner(undefined);
    if (Object.keys(missing).length) return;
    inFlight.current = true;
    setSaving(true);
    try {
      const result = await postForm<{success: boolean; id: number}>('/upload-workhour', {
        submission_key: key.current,
        teacher_name: verified.teacherName,
        teacher_code: verified.teacherCode,
        password: verified.password,
        student_name: student!.student_name,
        student_code: student!.student_code,
        subject: draft.subject,
        date: draft.date,
        chapter: draft.chapter.trim(),
        topic: draft.topic.trim(),
        hour_taken: draft.hour_taken,
        homework_status: draft.homework_status,
      });
      // Only a confirmed save (or replay) moves on to a new key.
      key.current = newSubmissionKey();
      setSaved({id: result.id});
    } catch (reason) {
      if (reason instanceof FormError) {
        if (reason.status === 403) {
          setVerified(null);
        }
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

  const description = 'Record a lesson you taught. Rates and totals are worked out by the office when it is saved.';

  if (saved && verified) {
    return (
      <PublicLayout title="Record a lesson" description={description}>
        <Saved
          title="Lesson recorded"
          detail="Your lesson is saved and will show in the attendance reports."
          reference={`WH-${saved.id}`}
          replayed={false}
          againLabel="Record another lesson"
          onAgain={() => {
            setSaved(null);
            setDraft(current => ({...blank(), student: current.student, subject: current.subject}));
          }}
          onFinish={forget}
        />
      </PublicLayout>
    );
  }

  return (
    <PublicLayout title="Record a lesson" description={description}>
      {session.readOnly && <ReadOnlyNotice />}
      {!verified ? (
        <VStack gap={4}>
          {banner && <Banner status="error" title={banner} />}
          <IdentifyStep
            teachers={session.teachers ?? []}
            lookupUrl="/studentinfom"
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
              <Heading level={2}>Lesson details</Heading>
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
                <DateInput
                  label="Class date"
                  isRequired
                  value={draft.date}
                  onChange={value => set('date')(value)}
                  max={session.maxDate as ISODate | undefined}
                  status={errorStatus(errors.date)}
                />
                <TextInput label="Chapter" isRequired placeholder="Chapter covered" value={draft.chapter} onChange={set('chapter')} status={errorStatus(errors.chapter)} />
                <TextInput label="Topic" isRequired placeholder="Topic taught" value={draft.topic} onChange={set('topic')} status={errorStatus(errors.topic)} />
                <Selector
                  label="Lesson duration"
                  isRequired
                  placeholder="Choose duration"
                  options={DURATIONS}
                  value={draft.hour_taken}
                  onChange={value => set('hour_taken')(value ?? undefined)}
                  presentation="adaptive"
                  width="100%"
                  status={errorStatus(errors.hour_taken)}
                />
                <RadioList
                  label="Was homework set?"
                  isRequired
                  orientation="horizontal"
                  value={draft.homework_status}
                  onChange={set('homework_status')}
                  status={errorStatus(errors.homework_status)}
                >
                  <RadioListItem value="yes" label="Yes" />
                  <RadioListItem value="no" label="No" />
                </RadioList>
                <Button label="Save lesson" variant="primary" size="lg" width="100%" isLoading={saving} isDisabled={session.readOnly} onClick={save} />
              </>
            )}
          </VStack>
        </Card>
      )}
    </PublicLayout>
  );
}
