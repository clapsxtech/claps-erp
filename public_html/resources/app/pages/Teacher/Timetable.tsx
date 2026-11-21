// Every dated lesson in the range, grouped by day in the teacher's time zone.
import {VStack} from '@astryxdesign/core/Layout';
import {Banner} from '@astryxdesign/core/Banner';
import {EmptyState} from '@astryxdesign/core/EmptyState';
import {PageError, PageLoading} from '../../components/PageState';
import {Filters, LessonList, Section, SubHeading, dayLabel, type Lesson, useWorkspace} from './shared';

export default function Timetable() {
  const ws = useWorkspace();
  const data = ws.data;
  const title = 'My timetable';
  if (ws.error && !data) return <Section title={title}><PageError error={ws.error} onRetry={ws.reload} /></Section>;
  if (!data) return <Section title={title}><PageLoading /></Section>;

  const days = new Map<string, Lesson[]>();
  for (const lesson of data.lessons) days.set(lesson.date, [...(days.get(lesson.date) ?? []), lesson]);

  return (
    <Section title={title} description={`Times in ${data.range.timezone}`}>
      <Filters data={data} />
      {data.range.truncated && <Banner status="warning" title="Showing the first 500 lessons. Narrow the date range to see the remaining lessons." />}
      {days.size === 0 ? (
        <EmptyState title="No lessons in this date range" description="Try another date range, or ask your administrator to publish your timetable." />
      ) : (
        [...days.entries()].map(([date, lessons]) => (
          <VStack key={date} gap={3}>
            <SubHeading title={dayLabel(date, data.range.today)} note={`${lessons.length} lesson${lessons.length === 1 ? '' : 's'}`} />
            <LessonList lessons={lessons} today={data.range.today} onSaved={ws.reload} empty={null} />
          </VStack>
        ))
      )}
    </Section>
  );
}
