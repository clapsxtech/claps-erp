// Historical timetable (/app/records/timetable, by teacher) and student timetable
// (/app/records/student-timetable, by student): pick a person, see their week as seven
// day sections, like the classic screens. Read-only; links are http(s) only (server-checked).
import {useLocation} from 'react-router';
import {VStack, HStack} from '@astryxdesign/core/Layout';
import {Grid} from '@astryxdesign/core/Grid';
import {Card} from '@astryxdesign/core/Card';
import {Heading, Text} from '@astryxdesign/core/Text';
import {List, ListItem} from '@astryxdesign/core/List';
import {Link} from '@astryxdesign/core/Link';
import {Selector} from '@astryxdesign/core/Selector';
import {Button} from '@astryxdesign/core/Button';
import {Icon} from '@astryxdesign/core/Icon';
import {EmptyState} from '@astryxdesign/core/EmptyState';
import {Skeleton} from '@astryxdesign/core/Skeleton';
import {ArrowDownTrayIcon} from '@heroicons/react/24/outline';
import {Page, PageError} from '../../components/PageState';
import {number} from '../../lib/format';
import {useRecordSet, exportHref} from './RecordSet';
import {AdminOnly, CRUMBS, type RecordRow} from './shared';

const DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

function Week({byStudent}: {byStudent: boolean}) {
  const slug = byStudent ? 'student-timetable' : 'timetable';
  const who = byStudent ? 'student' : 'teacher';
  const {query, setQuery, list} = useRecordSet(slug, {per_page: '100', page: '1', sort: 'day', dir: 'asc'});
  const set = list.data?.set;
  const picked = byStudent ? query.student : query.teacher;
  const options = set?.filters.find(f => f.key === who)?.options ?? [];
  const lessons = picked ? list.data?.data ?? [] : [];

  if (list.error && !list.data) {
    return (
      <Page title={byStudent ? 'Student timetable' : 'Historical timetable'} crumbs={CRUMBS}>
        <PageError error={list.error} onRetry={list.reload} />
      </Page>
    );
  }

  const person = (lesson: RecordRow) => String((byStudent ? lesson.teacherName : lesson.studentName) ?? '—');
  const phone = (lesson: RecordRow) => (byStudent ? lesson.teacherNumber : lesson.studentNumber) as string | null;

  return (
    <Page
      title={set?.title ?? (byStudent ? 'Student timetable' : 'Historical timetable')}
      description={set?.description}
      crumbs={CRUMBS}
      actions={
        <Button
          label={picked ? 'Export this week' : 'Export all lessons'}
          variant="secondary"
          href={exportHref(slug, {[who]: picked})}
          icon={<Icon icon={ArrowDownTrayIcon} size="sm" />}
          {...{download: true}}
        />
      }>
      <HStack gap={2} wrap="wrap" vAlign="center">
        <Selector
          label={byStudent ? 'Student' : 'Teacher'}
          placeholder={byStudent ? 'Choose a student' : 'Choose a teacher'}
          hasSearch
          hasClear
          width={320}
          value={picked || null}
          onChange={value => setQuery({[who]: value ?? ''})}
          options={options}
          isLoading={list.isLoading}
        />
        {picked && list.data && (
          <Text color="secondary" hasTabularNumbers>
            {`${number(list.data.meta.total)} lessons a week`}
          </Text>
        )}
      </HStack>
      {!list.data && <Skeleton height={240} width="100%" />}
      {list.data && !picked && (
        <EmptyState
          title={byStudent ? 'Choose a student' : 'Choose a teacher'}
          description={`${number(list.data.meta.total)} weekly lessons are on record. Pick a ${who} to see their week.`}
        />
      )}
      {list.data && picked && (
        <Grid columns={{minWidth: 280, repeat: 'fill'}} gap={3}>
          {DAYS.map(day => {
            const today = lessons.filter(lesson => lesson.day === day);
            return (
              <Card key={day} padding={4}>
                <VStack gap={2}>
                  <Heading level={3}>{day.charAt(0).toUpperCase() + day.slice(1)}</Heading>
                  {today.length === 0 ? (
                    <Text color="secondary">No classes</Text>
                  ) : (
                    <List hasDividers edge="flush">
                      {today.map(lesson => (
                        <ListItem
                          key={lesson.rowKey}
                          label={`${lesson.time ?? ''} · ${person(lesson)}`}
                          description={[lesson.subject, lesson.hour ? `${lesson.hour} h` : null, phone(lesson)].filter(Boolean).join(' · ')}
                          endContent={
                            lesson.link ? (
                              <Link href={String(lesson.link)} isExternalLink>
                                Join
                              </Link>
                            ) : undefined
                          }
                        />
                      ))}
                    </List>
                  )}
                </VStack>
              </Card>
            );
          })}
        </Grid>
      )}
      {list.data && picked && list.data.meta.total > lessons.length && (
        <Text color="secondary">{`Showing the first ${lessons.length} lessons; export to see all ${list.data.meta.total}.`}</Text>
      )}
    </Page>
  );
}

export default function RecordsTimetable() {
  const byStudent = useLocation().pathname.includes('student-timetable');
  return (
    <AdminOnly title="Timetable">
      <Week key={String(byStudent)} byStudent={byStudent} />
    </AdminOnly>
  );
}
