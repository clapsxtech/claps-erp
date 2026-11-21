import {VStack, HStack, StackItem} from '@astryxdesign/core/Layout';
import {Card} from '@astryxdesign/core/Card';
import {Grid} from '@astryxdesign/core/Grid';
import {Heading, Text} from '@astryxdesign/core/Text';
import {Button} from '@astryxdesign/core/Button';
import {List, ListItem} from '@astryxdesign/core/List';
import {EmptyState} from '@astryxdesign/core/EmptyState';
import * as stylex from '@stylexjs/stylex';
import {PageError, PageLoading} from '../../components/PageState';
import {balanceWord, kindSuffix, lessonDay, lessonTime, lessonWhen, parentSession, rupees, useParentData, type Lesson, type Result} from './shared';

const styles = stylex.create({
  due: {color: 'var(--color-text-error)'},
});

interface OverviewData {
  balancePaise: number;
  openRequests: number;
  upcoming: Lesson[];
  results: Result[];
}

/** The amount the family owes (or has paid ahead), big enough to read at a glance. */
export function BalanceCard({paise, openRequests, showFeesLink}: {paise: number; openRequests?: number; showFeesLink?: boolean}) {
  const {centre} = parentSession();
  const owes = paise < 0;
  return (
    <Card padding={5} variant={owes ? 'yellow' : 'green'}>
      <VStack gap={3}>
        <VStack gap={1}>
          <Text color="secondary" weight="semibold">
            Balance
          </Text>
          <Text size="4xl" weight="bold" hasTabularNumbers xstyle={owes ? styles.due : undefined}>
            {rupees(Math.abs(paise))}
          </Text>
          <Text weight="semibold">{balanceWord(paise)}</Text>
          {openRequests !== undefined && (
            <Text color="secondary">
              {openRequests === 0 ? 'No open fee requests' : openRequests === 1 ? '1 open fee request' : `${openRequests} open fee requests`}
            </Text>
          )}
        </VStack>
        {(showFeesLink || (owes && centre?.phone)) && (
          <HStack gap={2} wrap="wrap">
            {showFeesLink && <Button label="Fees, receipts and invoices" variant="primary" href="/parent/fees" />}
            {owes && centre?.phone && <Button label={`Call the centre: ${centre.phone}`} variant="secondary" href={`tel:${centre.phone.replace(/\s+/g, '')}`} />}
          </HStack>
        )}
      </VStack>
    </Card>
  );
}

export default function ParentOverview() {
  const {data, error, reload} = useParentData<OverviewData>('/parent-api/sections/overview');
  if (error) return <PageError error={error} onRetry={reload} />;
  if (!data) return <PageLoading />;
  const next = data.upcoming[0];
  const latest = data.results[0];

  return (
    <VStack gap={5}>
      <BalanceCard paise={data.balancePaise} openRequests={data.openRequests} showFeesLink />
      <Grid columns={{minWidth: 200, repeat: 'fill'}} gap={3}>
        <Card padding={4}>
          <VStack gap={1}>
            <Text color="secondary" size="sm">
              Next class
            </Text>
            <Text size="lg" weight="semibold">
              {next ? lessonWhen(next.startsAt) : 'None booked'}
            </Text>
            {next && <Text color="secondary">{next.subject}</Text>}
          </VStack>
        </Card>
        <Card padding={4}>
          <VStack gap={1}>
            <Text color="secondary" size="sm">
              Latest result
            </Text>
            <Text size="lg" weight="semibold">
              {latest ? `${latest.score}/${latest.maximum}` : '—'}
            </Text>
            {latest && (
              <Text color="secondary">
                {latest.subject} · {latest.assessment}
              </Text>
            )}
          </VStack>
        </Card>
      </Grid>

      <VStack gap={2}>
        <HStack vAlign="center">
          <StackItem size="fill">
            <Heading level={2}>Upcoming classes</Heading>
          </StackItem>
          <Button label="All classes" variant="ghost" size="sm" href="/parent/classes" />
        </HStack>
        {data.upcoming.length === 0 ? (
          <EmptyState title="No upcoming classes" description="Contact the centre to book classes." isCompact />
        ) : (
          <Card padding={0}>
            <List hasDividers>
              {data.upcoming.map(lesson => (
                <ListItem
                  key={lesson.id}
                  label={`${lesson.subject}${kindSuffix(lesson.kind)}`}
                  description={<Text color="secondary">with {lesson.teacher}</Text>}
                  endContent={
                    <VStack gap={0} hAlign="end">
                      <Text weight="semibold">{lessonDay(lesson.startsAt)}</Text>
                      <Text color="secondary" size="sm" hasTabularNumbers>
                        {lessonTime(lesson.startsAt)}–{lessonTime(lesson.endsAt)}
                      </Text>
                    </VStack>
                  }
                />
              ))}
            </List>
          </Card>
        )}
      </VStack>
    </VStack>
  );
}
