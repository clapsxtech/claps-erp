// Teaching reports for a date range: the summary figures, recorded attendance
// and assessment history, filtered by student. No rates or pay.
import {VStack} from '@astryxdesign/core/Layout';
import {List, ListItem} from '@astryxdesign/core/List';
import {Text} from '@astryxdesign/core/Text';
import {Banner} from '@astryxdesign/core/Banner';
import {EmptyState} from '@astryxdesign/core/EmptyState';
import {PageError, PageLoading} from '../../components/PageState';
import {Status} from '../../components/Status';
import {Filters, Metrics, RangeNote, Section, SubHeading, shortDate, useWorkspace} from './shared';
import {ResultsHistory} from './ResultsHistory';

export default function Reports() {
  const ws = useWorkspace();
  const data = ws.data;
  const title = 'My teaching reports';
  if (ws.error && !data) return <Section title={title}><PageError error={ws.error} onRetry={ws.reload} /></Section>;
  if (!data) return <Section title={title}><PageLoading /></Section>;

  const recorded = data.lessons.filter(lesson => lesson.attendance);
  return (
    <Section title={title} description="Attendance and assessment history for the dates you choose.">
      <Filters data={data} />
      <VStack gap={2}>
        <Metrics data={data} />
        <RangeNote data={data} />
      </VStack>
      {data.range.truncated && <Banner status="warning" title="Showing the first 500 lessons. Narrow the date range to see the remaining lessons." />}
      <VStack gap={3}>
        <SubHeading title="Attendance history" note={`${recorded.length} recorded lesson${recorded.length === 1 ? '' : 's'}`} />
        {recorded.length === 0 ? (
          <EmptyState isCompact title="No attendance in this date range" description="Submitted attendance will appear here." />
        ) : (
          <List hasDividers edge="flush">
            {recorded.map(lesson => (
              <ListItem
                key={lesson.id}
                align="first-line"
                label={`${lesson.studentName} · ${lesson.subjectName}`}
                description={`${shortDate(lesson.date)} · ${lesson.startTime}–${lesson.endTime}`}
                endContent={
                  <VStack gap={0} hAlign="end">
                    <Text hasTabularNumbers weight="semibold">{`${lesson.attendance!.minutes} min`}</Text>
                    <Status value={lesson.attendance!.status} />
                  </VStack>
                }
              />
            ))}
          </List>
        )}
      </VStack>
      <ResultsHistory data={data} />
    </Section>
  );
}
