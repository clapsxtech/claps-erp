// Lessons waiting for attendance (with one-tap submit, a different time, or a
// no-show), then what was already recorded and what is still to come.
import {VStack} from '@astryxdesign/core/Layout';
import {Banner} from '@astryxdesign/core/Banner';
import {Text} from '@astryxdesign/core/Text';
import {PageError, PageLoading} from '../../components/PageState';
import {Filters, LessonList, Section, SubHeading, useWorkspace} from './shared';

export default function Attendance() {
  const ws = useWorkspace();
  const data = ws.data;
  const title = 'My lessons and attendance';
  if (ws.error && !data) return <Section title={title}><PageError error={ws.error} onRetry={ws.reload} /></Section>;
  if (!data) return <Section title={title}><PageLoading /></Section>;

  const today = data.range.today;
  const pending = data.lessons.filter(lesson => lesson.needsAttendance);
  const recorded = data.lessons.filter(lesson => lesson.attendance).reverse();
  const upcoming = data.lessons.filter(lesson => !lesson.needsAttendance && !lesson.attendance && lesson.isUpcoming);
  const other = data.lessons.filter(lesson => !lesson.needsAttendance && !lesson.attendance && !lesson.isUpcoming).reverse();

  return (
    <Section title={title} description="Record actual teaching time after the lesson ends. Submissions go to an administrator for review.">
      <Filters data={data} />
      {data.range.truncated && <Banner status="warning" title="Showing the first 500 lessons. Narrow the date range to see the remaining lessons." />}
      <VStack gap={3}>
        <SubHeading title={`Waiting for attendance (${pending.length})`} />
        <LessonList lessons={pending} today={today} onSaved={ws.reload} empty={<Text color="secondary">Nothing to submit. You're up to date.</Text>} />
      </VStack>
      <VStack gap={3}>
        <SubHeading title={`Recorded (${recorded.length})`} note="Ask an administrator to correct an existing record." />
        <LessonList lessons={recorded} today={today} onSaved={ws.reload} empty={<Text color="secondary">No attendance recorded in this date range.</Text>} />
      </VStack>
      {upcoming.length > 0 && (
        <VStack gap={3}>
          <SubHeading title={`Upcoming (${upcoming.length})`} note="Attendance opens when each lesson ends." />
          <LessonList lessons={upcoming.slice(0, 12)} today={today} onSaved={ws.reload} empty={null} />
        </VStack>
      )}
      {other.length > 0 && (
        <VStack gap={3}>
          <SubHeading title={`Cancelled, rescheduled or no-show (${other.length})`} />
          <LessonList lessons={other} today={today} onSaved={ws.reload} empty={null} />
        </VStack>
      )}
    </Section>
  );
}
