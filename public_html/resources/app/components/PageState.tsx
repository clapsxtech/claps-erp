import type {ReactNode} from 'react';
import {Layout, LayoutContent, VStack, HStack, StackItem} from '@astryxdesign/core/Layout';
import {Heading, Text} from '@astryxdesign/core/Text';
import {Spinner} from '@astryxdesign/core/Spinner';
import {EmptyState} from '@astryxdesign/core/EmptyState';
import {Button} from '@astryxdesign/core/Button';
import {Breadcrumbs, BreadcrumbItem} from '@astryxdesign/core/Breadcrumbs';

export function PageLoading() {
  return (
    <VStack padding={10} hAlign="center">
      <Spinner label="Loading" />
    </VStack>
  );
}

export function PageError({error, onRetry}: {error: Error; onRetry?: () => void}) {
  return (
    <EmptyState
      title="This screen could not load"
      description={error.message}
      actions={onRetry ? <Button label="Try again" variant="secondary" onClick={onRetry} /> : undefined}
    />
  );
}

/**
 * Every screen uses this frame so titles, actions and spacing line up across
 * the app. Actions sit top-right on desktop and wrap below the title on phones.
 */
export function Page({
  title,
  description,
  actions,
  crumbs,
  children,
  width = 1280,
  header,
}: {
  title: string;
  /** Replaces the title row with a custom welcome block (it must contain the page's h1). */
  header?: ReactNode;
  description?: ReactNode;
  actions?: ReactNode;
  crumbs?: {label: string; href?: string}[];
  children: ReactNode;
  width?: number;
}) {
  return (
    <Layout
      height="auto"
      contentWidth={width}
      content={
        <LayoutContent padding={0}>
          <VStack padding={5} gap={5}>
            <VStack gap={2}>
              {crumbs && crumbs.length > 0 && (
                <Breadcrumbs label="You are here">
                  {crumbs.map(crumb => (
                    <BreadcrumbItem key={crumb.label} href={crumb.href}>
                      {crumb.label}
                    </BreadcrumbItem>
                  ))}
                </Breadcrumbs>
              )}
              {header ?? (
              <HStack gap={3} vAlign="center" wrap="wrap">
                <StackItem size="fill">
                  <VStack gap={1}>
                    <Heading level={1}>{title}</Heading>
                    {description && <Text color="secondary">{description}</Text>}
                  </VStack>
                </StackItem>
                {actions && (
                  <HStack gap={2} wrap="wrap">
                    {actions}
                  </HStack>
                )}
              </HStack>
              )}
            </VStack>
            {children}
          </VStack>
        </LayoutContent>
      }
    />
  );
}
