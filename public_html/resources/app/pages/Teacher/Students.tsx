// Active assigned students with the admissions handoff notes that help plan
// lessons. No guardian contact, meeting links or rates.
import {VStack, HStack} from '@astryxdesign/core/Layout';
import {Card} from '@astryxdesign/core/Card';
import {Grid} from '@astryxdesign/core/Grid';
import {Heading, Text} from '@astryxdesign/core/Text';
import {Button} from '@astryxdesign/core/Button';
import {EmptyState} from '@astryxdesign/core/EmptyState';
import {MetadataList, MetadataListItem} from '@astryxdesign/core/MetadataList';
import {PageError, PageLoading} from '../../components/PageState';
import {Section, useWorkspace} from './shared';

export default function Students() {
  const ws = useWorkspace();
  const data = ws.data;
  const title = 'My students';
  if (ws.error && !data) return <Section title={title}><PageError error={ws.error} onRetry={ws.reload} /></Section>;
  if (!data) return <Section title={title}><PageLoading /></Section>;

  return (
    <Section title={title} description={`${data.assignments.length} active assignment${data.assignments.length === 1 ? '' : 's'}`}>
      {data.assignments.length === 0 ? (
        <EmptyState title="No students assigned yet" description="Your administrator can assign students and subjects to your teacher account." />
      ) : (
        <Grid columns={{minWidth: 280, repeat: 'fill'}} gap={3}>
          {data.assignments.map(assignment => (
            <Card key={assignment.id} padding={4}>
              <VStack gap={3}>
                <VStack gap={0.5}>
                  <Text color="secondary" size="sm">{assignment.studentCode}</Text>
                  <Heading level={3}>{assignment.studentName}</Heading>
                  <Text color="secondary">{assignment.subjectName}</Text>
                </VStack>
                <MetadataList>
                  <MetadataListItem label="Grade">{assignment.grade || '—'}</MetadataListItem>
                  <MetadataListItem label="Syllabus">{assignment.syllabus || '—'}</MetadataListItem>
                  {assignment.learningRequirement && <MetadataListItem label="Learning needs">{assignment.learningRequirement}</MetadataListItem>}
                  {assignment.concern && <MetadataListItem label="Parent's concerns">{assignment.concern}</MetadataListItem>}
                  {assignment.aboutStudent && <MetadataListItem label="About the student">{assignment.aboutStudent}</MetadataListItem>}
                </MetadataList>
                <HStack gap={2} wrap="wrap">
                  <Button label="Record a result" variant="secondary" href={`/teacher/results?assignment=${assignment.id}`} />
                  <Button label="Lessons" variant="ghost" href={`/teacher/timetable?student=${assignment.studentId}`} />
                </HStack>
              </VStack>
            </Card>
          ))}
        </Grid>
      )}
    </Section>
  );
}
