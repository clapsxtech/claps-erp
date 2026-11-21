import {useSearchParams} from 'react-router';
import {VStack, HStack} from '@astryxdesign/core/Layout';
import {Card} from '@astryxdesign/core/Card';
import {Text} from '@astryxdesign/core/Text';
import {Button} from '@astryxdesign/core/Button';
import {List, ListItem} from '@astryxdesign/core/List';
import {EmptyState} from '@astryxdesign/core/EmptyState';
import {PageError, PageLoading} from '../../components/PageState';
import {plainDate, useParentData, type Result} from './shared';

interface ResultsData {
  data: Result[];
  meta: {page: number; lastPage: number; total: number};
}

export default function ParentResults() {
  const [params, setParams] = useSearchParams();
  const page = Math.max(1, Number(params.get('page')) || 1);
  const {data, error, reload} = useParentData<ResultsData>('/parent-api/sections/results', {page});

  if (error) return <PageError error={error} onRetry={reload} />;
  if (!data) return <PageLoading />;
  if (data.data.length === 0) return <EmptyState title="No results recorded yet" description="Test and assessment results will appear here when your child's teacher adds them." isCompact />;

  const go = (next: number) => setParams(next > 1 ? {page: String(next)} : {});

  return (
    <VStack gap={3}>
      <Card padding={0}>
        <List hasDividers>
          {data.data.map(result => (
            <ListItem
              key={result.id}
              align="start"
              label={`${result.subject} · ${result.assessment}`}
              description={
                <VStack gap={1}>
                  <Text color="secondary">{plainDate(result.assessedOn)}</Text>
                  {result.note && <Text>Teacher's note: {result.note}</Text>}
                </VStack>
              }
              endContent={
                <Text size="lg" hasTabularNumbers>
                  <Text size="lg" weight="bold" hasTabularNumbers>
                    {String(result.score)}
                  </Text>{' '}
                  / {result.maximum}
                </Text>
              }
            />
          ))}
        </List>
      </Card>
      {data.meta.lastPage > 1 && (
        <HStack gap={2} vAlign="center" hAlign="between">
          <Button label="Newer" variant="secondary" isDisabled={page <= 1} onClick={() => go(page - 1)} />
          <Text color="secondary" size="sm">
            Page {data.meta.page} of {data.meta.lastPage}
          </Text>
          <Button label="Older" variant="secondary" isDisabled={page >= data.meta.lastPage} onClick={() => go(page + 1)} />
        </HStack>
      )}
    </VStack>
  );
}
