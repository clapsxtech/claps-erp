import {EmptyState} from '@astryxdesign/core/EmptyState';
import {Button} from '@astryxdesign/core/Button';
import {VStack} from '@astryxdesign/core/Layout';

export function NotFound({
  title = 'This page does not exist',
  description = 'The link may be old. Use search (⌘K) to find what you need.',
}: {
  title?: string;
  description?: string;
}) {
  return (
    <VStack padding={10}>
      <EmptyState title={title} description={description} actions={<Button label="Go home" href="/app" variant="secondary" />} />
    </VStack>
  );
}
