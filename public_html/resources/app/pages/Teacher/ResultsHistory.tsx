// Saved assessment results for the date range, 25 per page, newest first.
import {useSearchParams} from 'react-router';
import {VStack, HStack, StackItem} from '@astryxdesign/core/Layout';
import {List, ListItem} from '@astryxdesign/core/List';
import {Text} from '@astryxdesign/core/Text';
import {Pagination} from '@astryxdesign/core/Pagination';
import {EmptyState} from '@astryxdesign/core/EmptyState';
import {SubHeading, shortDate, type Workspace} from './shared';

export function ResultsHistory({data}: {data: Workspace}) {
  const [, setParams] = useSearchParams();
  const {data: rows, meta} = data.results;
  return (
    <VStack gap={3}>
      <SubHeading title="Assessment history" note={`${meta.total} result${meta.total === 1 ? '' : 's'} from ${shortDate(data.range.from)} to ${shortDate(data.range.through)}`} />
      {rows.length === 0 ? (
        <EmptyState isCompact title="No results in this date range" description="Saved assessments will appear here." />
      ) : (
        <List hasDividers edge="flush">
          {rows.map(result => (
            <ListItem
              key={result.id}
              align="first-line"
              label={`${result.studentName} · ${result.assessment}`}
              description={`${shortDate(result.assessedOn)} · ${result.subjectName}${result.notes ? ` · ${result.notes}` : ''}`}
              endContent={
                <VStack gap={0} hAlign="end">
                  <Text weight="semibold" hasTabularNumbers>{`${result.score} / ${result.maximum}`}</Text>
                  <Text color="secondary" size="sm" hasTabularNumbers>{`${((100 * result.score) / Math.max(1, result.maximum)).toFixed(1)}%`}</Text>
                </VStack>
              }
            />
          ))}
        </List>
      )}
      {meta.lastPage > 1 && (
        <HStack>
          <StackItem size="fill">
            <Pagination
              page={meta.page}
              totalItems={meta.total}
              pageSize={meta.perPage}
              variant="compact"
              onChange={page =>
                setParams(current => {
                  const next = new URLSearchParams(current);
                  if (page > 1) next.set('results_page', String(page));
                  else next.delete('results_page');
                  return next;
                })
              }
            />
          </StackItem>
        </HStack>
      )}
    </VStack>
  );
}
