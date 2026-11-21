// Records & administration overview (/app/records): every read-only record set from the
// classic /tools page, grouped as before, with its row count. Superadmin only.
import {VStack} from '@astryxdesign/core/Layout';
import {Grid} from '@astryxdesign/core/Grid';
import {ClickableCard} from '@astryxdesign/core/ClickableCard';
import {Heading, Text} from '@astryxdesign/core/Text';
import {Banner} from '@astryxdesign/core/Banner';
import {Skeleton} from '@astryxdesign/core/Skeleton';
import {Page, PageError} from '../../components/PageState';
import {useResource} from '../../lib/hooks';
import {number} from '../../lib/format';
import {AdminOnly, RECORDS_API} from './shared';

interface Overview {
  groups: {key: string; title: string; description: string}[];
  sets: {slug: string; group: string; title: string; description: string; count: number; classicUrl: string}[];
}

function countLabel(slug: string, count: number): string {
  if (slug === 'settings') return count ? 'Configured' : 'Not set up';
  if (slug === 'timetable' || slug === 'student-timetable') return `${number(count)} weekly lessons`;
  return `${number(count)} ${count === 1 ? 'record' : 'records'}`;
}

function Overview() {
  const overview = useResource<Overview>(RECORDS_API);
  return (
    <Page title="Records & administration" description="Historical records imported from the original spreadsheets, and site administration. Everything here is read-only.">
      <Banner
        status="info"
        title="Read-only history"
        description="These records are no longer synced from Google Sheets or edited here. Day-to-day work happens in your team's screens; staff accounts are managed in Teams & accounts."
      />
      {overview.error && !overview.data && <PageError error={overview.error} onRetry={overview.reload} />}
      {!overview.data && !overview.error && <Skeleton height={240} width="100%" />}
      {overview.data?.groups.map(group => (
        <VStack key={group.key} gap={3}>
          <VStack gap={1}>
            <Heading level={2}>{group.title}</Heading>
            <Text color="secondary">{group.description}</Text>
          </VStack>
          <Grid columns={{minWidth: 260, repeat: 'fill'}} gap={3}>
            {overview.data!.sets
              .filter(set => set.group === group.key)
              .map(set => (
                <ClickableCard key={set.slug} padding={4} href={`/app/records/${set.slug}`} label={`${set.title}: ${countLabel(set.slug, set.count)}`}>
                  <VStack gap={1}>
                    <Text weight="semibold">{set.title}</Text>
                    <Text color="secondary" size="sm" maxLines={2}>
                      {set.description}
                    </Text>
                    <Text size="sm" hasTabularNumbers>
                      {countLabel(set.slug, set.count)}
                    </Text>
                  </VStack>
                </ClickableCard>
              ))}
          </Grid>
        </VStack>
      ))}
    </Page>
  );
}

export default function RecordsIndex() {
  return (
    <AdminOnly title="Records & administration">
      <Overview />
    </AdminOnly>
  );
}
