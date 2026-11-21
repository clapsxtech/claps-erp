// Shared pieces of the public teacher forms (/attendance, /resultform,
// /performance, /timetable). There is no sign-in: a teacher picks their name
// and types their date of birth, and every POST sends it again so the form
// controllers verify it each time. The date of birth lives only in component
// state; it is never put in the URL, storage or on screen.
import {useEffect, useRef, useState, type ReactNode} from 'react';
import {VStack, HStack, StackItem} from '@astryxdesign/core/Layout';
import {Heading, Text} from '@astryxdesign/core/Text';
import {Card} from '@astryxdesign/core/Card';
import {Banner} from '@astryxdesign/core/Banner';
import {Button} from '@astryxdesign/core/Button';
import {Selector} from '@astryxdesign/core/Selector';
import {TextInput} from '@astryxdesign/core/TextInput';
import {readSession} from '../../lib/session';

export type PublicForm = 'attendance' | 'result' | 'performance' | 'timetable';

export interface Option {
  id: number;
  name: string;
}

export interface PublicSession {
  kind: 'public';
  form: PublicForm;
  teachers?: string[];
  subjects?: Option[];
  topics?: Option[];
  maxDate?: string;
  /** Sheets are the system of record for now: the server refuses saves. */
  readOnly: boolean;
}

export function publicSession(): PublicSession {
  return readSession<PublicSession>();
}

export type ISODate = `${number}${number}${number}${number}-${number}${number}-${number}${number}`;

export function today(): ISODate {
  const now = new Date();
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}` as ISODate;
}

/** A fresh idempotency key for one submission. Kept across retries; replaced only after a confirmed save. */
export function newSubmissionKey(): string {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') return crypto.randomUUID();
  const bytes = new Uint8Array(16);
  crypto.getRandomValues(bytes);
  bytes[6] = (bytes[6] & 0x0f) | 0x40;
  bytes[8] = (bytes[8] & 0x3f) | 0x80;
  const hex = Array.from(bytes, b => b.toString(16).padStart(2, '0')).join('');
  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

export class FormError extends Error {
  status: number;
  fieldErrors: Record<string, string[]>;
  constructor(status: number, message: string, fieldErrors: Record<string, string[]> = {}) {
    super(message);
    this.status = status;
    this.fieldErrors = fieldErrors;
  }
}

function csrfToken(): string {
  return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
}

/**
 * POST JSON to a public form route. The form controllers answer either
 * `{msg}` (results, performance) or Laravel's `{message, errors}`, so both
 * are read. A redirect is never treated as success.
 */
export async function postForm<T>(url: string, body: Record<string, unknown>): Promise<T> {
  let response: Response;
  try {
    response = await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-TOKEN': csrfToken(),
      },
      body: JSON.stringify(body),
      redirect: 'manual',
    });
  } catch {
    throw new FormError(0, 'The connection dropped. Check your signal and press the button again; it will not be saved twice.');
  }
  if (response.type === 'opaqueredirect' || (response.status >= 300 && response.status < 400)) {
    throw new FormError(0, 'The server did not confirm this. Reload the page and try again.');
  }
  let json: {msg?: string; message?: string; errors?: Record<string, string[]>; success?: boolean} & Record<string, unknown> = {};
  try {
    json = await response.json();
  } catch {
    // Not JSON (for example a proxy error page).
  }
  if (response.ok && json.success !== false) return json as T;
  const server = json.msg || json.message;
  const copy: Record<number, string> = {
    403: 'We could not verify that teacher and date of birth. Check both and try again.',
    419: 'This page has expired. Reload it and try again; nothing was saved.',
    429: 'Too many attempts. Wait a minute, then try again.',
    500: 'The record could not be saved. You can safely press the button again.',
    503: 'The service is unavailable right now. Try again in a few minutes.',
  };
  const message =
    response.status === 403 || response.status === 419 || response.status === 429
      ? copy[response.status]
      : server || copy[response.status] || 'Something went wrong. Nothing was saved.';
  throw new FormError(response.status, message, json.errors ?? {});
}

/** Field errors for the fields on screen; anything else goes to the banner. */
export function splitErrors(error: FormError, fields: string[]): {placed: Record<string, string>; banner: string} {
  const placed: Record<string, string> = {};
  const other: string[] = [];
  for (const [key, messages] of Object.entries(error.fieldErrors)) {
    if (fields.includes(key)) placed[key] = messages[0];
    else other.push(...messages);
  }
  const banner = other.length ? other.join(' ') : Object.keys(placed).length ? 'Please check the highlighted fields. Nothing has been saved yet.' : error.message;
  return {placed, banner};
}

export function errorStatus(message?: string) {
  return message ? {type: 'error' as const, message} : undefined;
}

/** Public layout: brand line and one narrow column. No staff chrome. */
export function PublicLayout({title, description, children}: {title: string; description?: ReactNode; children: ReactNode}) {
  useEffect(() => {
    document.title = `${title} · ClapsLearn`;
  }, [title]);
  return (
    <VStack as="main" hAlign="center" paddingInline={4} paddingBlock={5}>
      <VStack width="100%" maxWidth={560} gap={5}>
        <VStack gap={1}>
          <Text type="label" color="accent" weight="semibold">
            ClapsLearn
          </Text>
          <Heading level={1}>{title}</Heading>
          {description && <Text color="secondary">{description}</Text>}
        </VStack>
        {children}
      </VStack>
    </VStack>
  );
}

/**
 * Masks a date of birth as dd/mm/yyyy while the teacher types digits, so the
 * number pad works on phones. Anything typed with other characters is left as is.
 */
export function formatDob(value: string): string {
  if (!/^[\d/]*$/.test(value)) return value;
  const d = value.replace(/\D/g, '').slice(0, 8);
  return d.slice(0, 2) + (d.length > 2 ? `/${d.slice(2, 4)}` : '') + (d.length > 4 ? `/${d.slice(4)}` : '');
}

export function DobInput({value, onChange, error, onEnter}: {value: string; onChange: (value: string) => void; error?: string; onEnter?: () => void}) {
  return (
    <TextInput
      label="Date of birth"
      description="Your verification code, as dd/mm/yyyy."
      type="password"
      inputMode="numeric"
      autoComplete="off"
      placeholder="dd/mm/yyyy"
      enterKeyHint="go"
      isRequired
      value={value}
      onChange={next => onChange(formatDob(next))}
      onEnter={onEnter}
      status={errorStatus(error)}
    />
  );
}

export interface StudentChoice {
  student_name: string;
  student_code: string;
}

export interface Verified {
  teacherName: string;
  teacherCode: string;
  /** Kept in memory only, and sent again with the save so the server re-verifies it. */
  password: string;
  students: StudentChoice[];
}

/**
 * Step 1 of the upload forms: pick your name, type your date of birth, and
 * the lookup route returns your students. The lookup keeps the
 * teacher-credentials throttle.
 */
export function IdentifyStep({
  teachers,
  lookupUrl,
  onVerified,
}: {
  teachers: string[];
  lookupUrl: string;
  onVerified: (verified: Verified) => void;
}) {
  const [teacher, setTeacher] = useState<string>();
  const [dob, setDob] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [banner, setBanner] = useState<string>();
  const [busy, setBusy] = useState(false);
  const inFlight = useRef(false);

  async function verify() {
    if (inFlight.current) return;
    const missing: Record<string, string> = {};
    if (!teacher) missing.teacher_name = 'Choose your name.';
    if (!dob.trim()) missing.password = 'Enter your date of birth.';
    setErrors(missing);
    setBanner(undefined);
    if (Object.keys(missing).length) return;
    inFlight.current = true;
    setBusy(true);
    try {
      const result = await postForm<{students: StudentChoice[]; teacher_code: string}>(lookupUrl, {teacher_name: teacher, password: dob});
      const seen = new Set<string>();
      const students = (result.students ?? [])
        .map(s => ({student_name: String(s.student_name ?? ''), student_code: String(s.student_code ?? '')}))
        .filter(s => {
          const key = `${s.student_code}\u0000${s.student_name}`;
          if (seen.has(key)) return false;
          seen.add(key);
          return true;
        });
      onVerified({teacherName: teacher!, teacherCode: result.teacher_code, password: dob, students});
    } catch (reason) {
      if (reason instanceof FormError) {
        const {placed, banner: message} = splitErrors(reason, ['teacher_name', 'password']);
        setErrors(placed);
        setBanner(message);
      } else {
        setBanner('Something went wrong. Try again.');
      }
    } finally {
      inFlight.current = false;
      setBusy(false);
    }
  }

  return (
    <Card padding={5}>
      <VStack gap={4}>
        <VStack gap={1}>
          <Heading level={2}>Who are you?</Heading>
          <Text color="secondary">Step 1 of 2. Your students appear once we have checked your details.</Text>
        </VStack>
        {banner && <Banner status="error" title={banner} />}
        <Selector
          label="Your name"
          isRequired
          placeholder="Choose your name"
          options={teachers.map(name => ({value: name, label: name}))}
          value={teacher}
          onChange={value => {
            setTeacher(value ?? undefined);
            setErrors(current => ({...current, teacher_name: ''}));
          }}
          hasSearch={teachers.length > 8}
          searchPlaceholder="Type your name"
          presentation="adaptive"
          width="100%"
          emptyText="No teachers are set up yet."
          status={errorStatus(errors.teacher_name)}
        />
        <DobInput value={dob} onChange={setDob} error={errors.password} onEnter={verify} />
        <Button label="Continue" variant="primary" size="lg" width="100%" isLoading={busy} onClick={verify} />
      </VStack>
    </Card>
  );
}

/** "Signed in as" strip for step 2, with a way to switch teacher. */
export function VerifiedAs({name, onChange}: {name: string; onChange: () => void}) {
  return (
    <HStack gap={3} vAlign="center">
      <StackItem size="fill">
        <VStack gap={0}>
          <Text type="supporting" color="secondary">
            Verified teacher
          </Text>
          <Text weight="semibold">{name}</Text>
        </VStack>
      </StackItem>
      <Button label="Not you?" variant="ghost" size="sm" onClick={onChange} />
    </HStack>
  );
}

export function studentOptions(students: StudentChoice[]) {
  return students.map((student, index) => ({
    value: String(index),
    label: student.student_name,
    description: student.student_code ? `Code ${student.student_code}` : undefined,
  }));
}

/** Confirmation screen after a save: what happened and a reference to quote. */
export function Saved({
  title,
  reference,
  replayed,
  detail,
  againLabel,
  onAgain,
  onFinish,
}: {
  title: string;
  reference: string;
  replayed: boolean;
  detail: string;
  againLabel: string;
  onAgain: () => void;
  onFinish: () => void;
}) {
  return (
    <Card padding={5}>
      <VStack gap={4}>
        <Banner status="success" title={replayed ? 'Already saved' : title} description={replayed ? 'This exact entry was saved earlier, so nothing was added twice.' : detail} />
        <VStack gap={1}>
          <Text type="supporting" color="secondary">
            Reference
          </Text>
          <Text type="display-3" weight="semibold">
            {reference}
          </Text>
          <Text type="supporting" color="secondary">
            Quote this if you need to ask the office about this entry.
          </Text>
        </VStack>
        <VStack gap={2}>
          <Button label={againLabel} variant="primary" size="lg" width="100%" onClick={onAgain} />
          <Button label="Finish" variant="secondary" size="lg" width="100%" onClick={onFinish} />
        </VStack>
      </VStack>
    </Card>
  );
}

/** Clears the in-memory credential when the tab is hidden for good. */
export function useForgetOnLeave(forget: () => void) {
  const ref = useRef(forget);
  ref.current = forget;
  useEffect(() => {
    const handler = () => ref.current();
    window.addEventListener('pagehide', handler);
    return () => window.removeEventListener('pagehide', handler);
  }, []);
}

export function ReadOnlyNotice() {
  return (
    <Banner
      status="warning"
      title="Saving is paused"
      description="The office is recording these in the shared sheet for now. Your entry will not be saved here."
    />
  );
}
