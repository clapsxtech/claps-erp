// KPI tiles. A tile with onClick or href is a shortcut: tapping "12 waiting for
// approval" opens that exact filtered list, so the number is also the way in.
import type {ReactNode} from 'react';
import {Grid} from '@astryxdesign/core/Grid';
import {Card} from '@astryxdesign/core/Card';
import {ClickableCard} from '@astryxdesign/core/ClickableCard';
import {VStack} from '@astryxdesign/core/Layout';
import {Text} from '@astryxdesign/core/Text';
import {Toned, type Tone} from './Amount';

export function MetricRow({children}: {children: ReactNode}) {
  return (
    <Grid columns={{minWidth: 140, repeat: 'fill'}} gap={3}>
      {children}
    </Grid>
  );
}

export function Metric({
  label,
  value,
  hint,
  tone,
  href,
  onClick,
  isActive,
}: {
  label: string;
  value: string;
  hint?: string;
  tone?: Tone;
  href?: string;
  onClick?: () => void;
  isActive?: boolean;
}) {
  const content = (
    <VStack gap={1}>
      <Text color="secondary" size="sm">
        {label}
      </Text>
      <Toned tone={tone} size="xl" strong>
        {value}
      </Toned>
      {hint && (
        <Text color="secondary" size="sm">
          {hint}
        </Text>
      )}
    </VStack>
  );
  if (href || onClick) {
    return (
      <ClickableCard padding={4} href={href} onClick={onClick} label={`${label}: ${value}`}>
        {content}
      </ClickableCard>
    );
  }
  return <Card padding={4}>{content}</Card>;
}
