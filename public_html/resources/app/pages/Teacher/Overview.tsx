// Today: lessons waiting for attendance first (one tap each), then today's
// classes, then what is coming next.
import {VStack, HStack} from '@astryxdesign/core/Layout';
import {Grid} from '@astryxdesign/core/Grid';
import {ClickableCard} from '@astryxdesign/core/ClickableCard';
import {Text} from '@astryxdesign/core/Text';
import {Button} from '@astryxdesign/core/Button';
import {Banner} from '@astryxdesign/core/Banner';
import {EmptyState} from '@astryxdesign/core/EmptyState';
import {PageError, PageLoading} from '../../components/PageState';
import {OwnTasks} from '../Overview/OwnTasks';
import {LessonList, Metrics, RangeNote, Section, SubHeading, longDate, teacherSession, useWorkspace} from './shared';

export default function Overview() {
  const sess = teacherSession();
  const ws = useWorkspace();
  const data = ws.data;
  const title = `Hello, ${sess.teacher?.name.split(' ')[0] ?? 'teacher'}`;
  if (ws.error && !data) return <Section title={title}><PageError error={ws.error} onRetry={ws.reload} /></Section>;
  if (!data) return <Section title={title}><PageLoading /></Section>;

  const today = data.range.today;
  // Today first (ended ones keep their one-tap button), then earlier lessons still waiting, then what is next.
  const todays = data.lessons.filter(lesson => lesson.date === today);
  const earlier = data.lessons.filter(lesson => lesson.needsAttendance && lesson.date < today);
  const next = data.lessons.filter(lesson => lesson.isUpcoming && lesson.date > today).slice(0, 3);

  return (
    <Section title={title} description={`${longDate(today)} · ${data.range.timezone}`}>
      <VStack gap={3}>
        <SubHeading title="Today's lessons" note={todays.some(lesson => lesson.needsAttendance) ? 'Tap once to submit the scheduled minutes, or choose a different time or a no-show.' : undefined} />
        <LessonList lessons={todays} today={today} onSaved={ws.reload} empty={<Text color="secondary">No lessons today.</Text>} />
      </VStack>
      {earlier.length > 0 && (
        <VStack gap={3}>
          <SubHeading title={`Earlier lessons waiting for attendance (${earlier.length})`} />
          <LessonList lessons={earlier.slice(0, 3)} today={today} onSaved={ws.reload} empty={null} />
          {earlier.length > 3 && (
            <HStack>
              <Button label={`See all ${earlier.length} in Attendance`} variant="secondary" href="/teacher/attendance" />
            </HStack>
          )}
        </VStack>
      )}
      <VStack gap={3}>
        <SubHeading title="Coming up" />
        <LessonList
          lessons={next}
          today={today}
          onSaved={ws.reload}
          empty={<EmptyState isCompact title="No upcoming lessons" description="Ask your administrator to publish your timetable." />}
        />
      </VStack>
      <VStack gap={3}>
        <SubHeading title="My tasks" note="Tasks your team assigned to you. Finish the checklist, then complete the task." />
        <OwnTasks status="open" showTeam={false} emptyDescription="Nothing to do right now. Tasks your team assigns to you appear here." />
      </VStack>
      <Grid columns={{minWidth: 220, repeat: 'fill'}} gap={3}>
        <ClickableCard label="Add assessment result" href="/teacher/results" padding={4}>
          <VStack gap={1}>
            <Text weight="semibold">Add assessment result</Text>
            <Text color="secondary" size="sm">Record your students' progress →</Text>
          </VStack>
        </ClickableCard>
        <ClickableCard label="My timetable" href="/teacher/timetable" padding={4}>
          <VStack gap={1}>
            <Text weight="semibold">My timetable</Text>
            <Text color="secondary" size="sm">All your dated lessons →</Text>
          </VStack>
        </ClickableCard>
      </Grid>
      <VStack gap={2}>
        <Metrics data={data} />
        <RangeNote data={data} />
      </VStack>
      {data.range.truncated && <Banner status="warning" title="Showing the first 500 lessons. Narrow the date range in Timetable to see the rest." />}
    </Section>
  );
}
