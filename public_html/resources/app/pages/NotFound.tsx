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

/** Shown when a screen crashes or its code fails to load, usually after a deploy. */
export function RouteError() {
  return (
    <VStack padding={10}>
      <EmptyState
        title="This screen did not load"
        description="The app may have just been updated. Reload to get the latest version; nothing you saved is lost."
        actions={<Button label="Reload" variant="primary" onClick={() => window.location.reload()} />}
      />
    </VStack>
  );
}
