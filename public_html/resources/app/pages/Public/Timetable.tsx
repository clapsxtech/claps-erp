// /timetable and /timetableReport: a teacher types their name and date of
// birth and sees only their own lessons, from POST /get-teacher-dob.
// The page embeds no teacher directory.
import {useRef, useState} from 'react';
import {VStack} from '@astryxdesign/core/Layout';
import {Heading, Text} from '@astryxdesign/core/Text';
import {Card} from '@astryxdesign/core/Card';
import {Banner} from '@astryxdesign/core/Banner';
import {Button} from '@astryxdesign/core/Button';
import {TextInput} from '@astryxdesign/core/TextInput';
import {Link} from '@astryxdesign/core/Link';
import {List, ListItem} from '@astryxdesign/core/List';
import {DobInput, FormError, PublicLayout, VerifiedAs, errorStatus, postForm, splitErrors, useForgetOnLeave} from './shared';

const DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as const;
type Day = (typeof DAYS)[number];

interface Lesson {
  time: string | null;
  subject: string | null;
  hour: string | null;
  student_name: string | null;
  link: string | null;
}

const title = (day: Day) => day.charAt(0).toUpperCase() + day.slice(1);

function todayName(): Day {
  return DAYS[(new Date().getDay() + 6) % 7];
}

export default function Timetable() {
  const [name, setName] = useState('');
  const [dob, setDob] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [banner, setBanner] = useState<string>();
  const [busy, setBusy] = useState(false);
  const [schedule, setSchedule] = useState<{teacher: string; days: Record<Day, Lesson[]>} | null>(null);
  const inFlight = useRef(false);

  function forget() {
    setSchedule(null);
    setDob('');
    setErrors({});
    setBanner(undefined);
  }
  useForgetOnLeave(forget);

  async function view() {
    if (inFlight.current) return;
    const missing: Record<string, string> = {};
    if (!name.trim()) missing.teacher_name = 'Enter your name.';
    if (!dob.trim()) missing.password = 'Enter your date of birth.';
    setErrors(missing);
    setBanner(undefined);
    if (Object.keys(missing).length) return;
    inFlight.current = true;
    setBusy(true);
    try {
      const result = await postForm<{days: Record<Day, Lesson[]>}>('/get-teacher-dob', {teacher_name: name.trim(), password: dob});
      const days = {} as Record<Day, Lesson[]>;
      for (const day of DAYS) days[day] = result.days?.[day] ?? [];
      setSchedule({teacher: name.trim(), days});
      // The credential is not needed once the timetable is shown.
      setDob('');
    } catch (reason) {
      if (reason instanceof FormError) {
        const {placed, banner: message} = splitErrors(reason, ['teacher_name', 'password']);
        setErrors(placed);
        setBanner(reason.status === 403 ? 'We could not verify that name and date of birth. Type your name exactly as the office has it.' : message);
      } else {
        setBanner('Something went wrong. Try again.');
      }
    } finally {
      inFlight.current = false;
      setBusy(false);
    }
  }

  const description = 'See your lessons for the week. Only your own lessons are shown.';

  if (schedule) {
    const current = todayName();
    const ordered = [...DAYS.slice(DAYS.indexOf(current)), ...DAYS.slice(0, DAYS.indexOf(current))];
    const total = DAYS.reduce((sum, day) => sum + schedule.days[day].length, 0);
    return (
      <PublicLayout title="My timetable" description={description}>
        <VerifiedAs name={schedule.teacher} onChange={forget} />
        {total === 0 && <Banner status="info" title="No lessons are scheduled for you" description="If this looks wrong, ask the office to check the timetable." />}
        {ordered.map(day => (
          <VStack key={day} gap={2}>
            <Heading level={2}>{day === current ? `Today · ${title(day)}` : title(day)}</Heading>
            {schedule.days[day].length === 0 ? (
              <Text color="secondary">No lessons.</Text>
            ) : (
              <Card padding={2}>
                <List hasDividers edge="flush">
                  {schedule.days[day].map((lesson, index) => (
                    <ListItem
                      key={`${day}-${index}`}
                      label={`${lesson.time || 'Time not set'} · ${lesson.student_name || 'Student'}`}
                      description={[lesson.subject, lesson.hour ? `${lesson.hour} h` : null].filter(Boolean).join(' · ') || undefined}
                      endContent={
                        lesson.link ? (
                          <Link href={lesson.link} isExternalLink>
                            Join
                          </Link>
                        ) : undefined
                      }
                    />
                  ))}
                </List>
              </Card>
            )}
          </VStack>
        ))}
      </PublicLayout>
    );
  }

  return (
    <PublicLayout title="My timetable" description={description}>
      <Card padding={5}>
        <VStack gap={4}>
          <Heading level={2}>Who are you?</Heading>
          {banner && <Banner status="error" title={banner} />}
          <TextInput
            label="Your name"
            description="As the office has it."
            isRequired
            autoComplete="off"
            autoCapitalize="words"
            hasAutoCorrect={false}
            value={name}
            onChange={value => {
              setName(value);
              setErrors(current => ({...current, teacher_name: ''}));
            }}
            status={errorStatus(errors.teacher_name)}
          />
          <DobInput value={dob} onChange={setDob} error={errors.password || errors.dob} onEnter={view} />
          <Button label="View timetable" variant="primary" size="lg" width="100%" isLoading={busy} onClick={view} />
        </VStack>
      </Card>
    </PublicLayout>
  );
}
