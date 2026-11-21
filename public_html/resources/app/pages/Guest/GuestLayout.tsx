// Centered, branded frame for sign-in and account screens. Full-width on phones.
import type {ReactNode} from 'react';
import {VStack, HStack} from '@astryxdesign/core/Layout';
import {Card} from '@astryxdesign/core/Card';
import {Avatar} from '@astryxdesign/core/Avatar';
import {Heading, Text} from '@astryxdesign/core/Text';
import {Link} from '@astryxdesign/core/Link';

export function GuestLayout({title, description, children, footer, width = 440}: {
  title: string;
  description?: ReactNode;
  children: ReactNode;
  footer?: ReactNode;
  width?: number;
}) {
  return (
    <VStack
      as="main"
      minHeight="100dvh"
      hAlign="center"
      vAlign="center"
      paddingInline={4}
      paddingBlock={8}
      gap={5}
      style={{background: 'var(--color-background-body)'}}
    >
      <Link href="/" label="ClapsLearn home">
        <HStack gap={2} vAlign="center">
          <Avatar name="C" size="md" shape="rounded" tooltip={false} />
          <VStack gap={0}>
            <Text weight="semibold" size="lg">ClapsLearn</Text>
            <Text color="secondary" size="sm">Operations</Text>
          </VStack>
        </HStack>
      </Link>
      <Card padding={6} width="100%" maxWidth={width} elevation="low">
        <VStack gap={5}>
          <VStack gap={1}>
            <Heading level={1}>{title}</Heading>
            {description && <Text color="secondary">{description}</Text>}
          </VStack>
          {children}
        </VStack>
      </Card>
      {footer && (
        <HStack gap={3} wrap="wrap" hAlign="center">
          {footer}
        </HStack>
      )}
    </VStack>
  );
}
