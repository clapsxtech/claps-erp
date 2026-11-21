// KPI tiles. A tile with onClick or href is a shortcut: tapping "12 waiting for
// approval" opens that exact filtered list, so the number is also the way in.
// Tiles carry an icon and a soft colour so a screen reads at a glance: amber
// when something is waiting, red when something is wrong, green when all is well.
import type {ReactNode} from 'react';
import {Grid} from '@astryxdesign/core/Grid';
import {Card} from '@astryxdesign/core/Card';
import {ClickableCard} from '@astryxdesign/core/ClickableCard';
import {VStack, HStack, StackItem} from '@astryxdesign/core/Layout';
import {Text} from '@astryxdesign/core/Text';
import {Icon} from '@astryxdesign/core/Icon';
import {Toned, type Tone} from './Amount';
import type {IconComponent} from '../nav';
import {
  AcademicCapIcon,
  BanknotesIcon,
  BookOpenIcon,
  ChatBubbleLeftRightIcon,
  CalendarDaysIcon,
  ChartBarIcon,
  CheckCircleIcon,
  ClipboardDocumentListIcon,
  ClockIcon,
  DocumentDuplicateIcon,
  ExclamationTriangleIcon,
  PhoneArrowUpRightIcon,
  ReceiptPercentIcon,
  ScaleIcon,
  UserGroupIcon,
  UserPlusIcon,
  UsersIcon,
} from '@heroicons/react/24/outline';

// A tile with no icon of its own gets one from what its label counts, so every
// figure in the app carries a picture without each screen choosing one.
const LABEL_ICONS: [RegExp, IconComponent][] = [
  [/overdue|wrong|missing|without/i, ExclamationTriangleIcon],
  [/duplicate/i, DocumentDuplicateIcon],
  [/approv|attendance|review/i, CheckCircleIcon],
  [/quer/i, ChatBubbleLeftRightIcon],
  [/subject/i, BookOpenIcon],
  [/task/i, ClipboardDocumentListIcon],
  [/follow|call/i, PhoneArrowUpRightIcon],
  [/lead|enquir|joined|conversion/i, UserPlusIcon],
  [/bill|charge|invoice|request|tax/i, ReceiptPercentIcon],
  [/balance|trial|surplus|deficit/i, ScaleIcon],
  [/₹|pay|owed|outstanding|income|expense|dues|fee|collected|margin|amount|receipt/i, BanknotesIcon],
  [/teacher|candidate|applic/i, AcademicCapIcon],
  [/hour|minute|time/i, ClockIcon],
  [/lesson|class|slot|demo|schedule|today/i, CalendarDaysIcon],
  [/famil/i, UserGroupIcon],
  [/student|people|member|account/i, UsersIcon],
];

function iconFor(label: string): IconComponent {
  return LABEL_ICONS.find(([pattern]) => pattern.test(label))?.[1] ?? ChartBarIcon;
}

export type TileColor = 'default' | 'blue' | 'cyan' | 'green' | 'orange' | 'pink' | 'purple' | 'red' | 'teal' | 'yellow';

export function MetricRow({children}: {children: ReactNode}) {
  return (
    <Grid columns={{minWidth: 140, repeat: 'fill'}} gap={3}>
      {children}
    </Grid>
  );
}

const TONE_COLOR: Partial<Record<NonNullable<Tone>, TileColor>> = {warning: 'yellow', error: 'red', success: 'green'};

export function Metric({
  label,
  value,
  hint,
  tone,
  href,
  onClick,
  isActive,
  icon,
  color,
}: {
  label: string;
  value: string;
  hint?: string;
  tone?: Tone;
  href?: string;
  onClick?: () => void;
  isActive?: boolean;
  /** A small picture of what the number counts. */
  icon?: IconComponent;
  /** Soft background; a warning or error tone picks amber or red itself. */
  color?: TileColor;
}) {
  // The selected tile (a filter tile on a list) is tinted so you can see which list is showing.
  const variant = (tone && TONE_COLOR[tone]) || color || (isActive ? 'teal' : 'default');
  const content = (
    <VStack gap={1}>
      <HStack gap={2} vAlign="start">
        <StackItem size="fill">
          <Text color="secondary" size="sm">
            {label}
          </Text>
        </StackItem>
        <Icon icon={icon ?? iconFor(label)} size="sm" color={tone === 'error' ? 'error' : tone === 'warning' ? 'warning' : 'accent'} />
      </HStack>
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
      <ClickableCard padding={4} variant={variant} href={href} onClick={onClick} label={`${label}: ${value}`}>
        {content}
      </ClickableCard>
    );
  }
  return (
    <Card padding={4} variant={variant}>
      {content}
    </Card>
  );
}
