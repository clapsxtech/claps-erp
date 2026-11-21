// Trends (classic /business/insights): the last six months side by side. Astryx has no
// chart component, so each measure is a tile with a month-by-month bar list built from
// ProgressBar, plus the full table. Headlines are the last complete month.
import {useParams} from 'react-router';
import {VStack, HStack, StackItem} from '@astryxdesign/core/Layout';
import {Card} from '@astryxdesign/core/Card';
import {Grid} from '@astryxdesign/core/Grid';
import {Heading, Text} from '@astryxdesign/core/Text';
import {ProgressBar} from '@astryxdesign/core/ProgressBar';
import {Table, type TableColumn} from '@astryxdesign/core/Table';
import {Page, PageError, PageLoading} from '../../components/PageState';
import {Toned} from '../../components/Amount';
import {useResource} from '../../lib/hooks';
import {money, number} from '../../lib/format';
import {unitById} from '../../lib/session';
import {percent} from './shared';

interface Month {
  month: string;
  label: string;
  short: string;
  enquiries: number;
  demos: number;
  joined: number;
  conversion: number | null;
  newStudents: number;
  activeStudents: number;
  hours: number;
  cancelled: number;
  noShows: number;
  noShowRate: number | null;
  billedPaise: number | null;
  collectedPaise: number | null;
  refundsPaise: number | null;
  teacherCostPaise: number | null;
  marginPaise: number | null;
  duesPaise: number | null;
}

type Kind = 'count' | 'percent' | 'hours' | 'money';
type Key = Exclude<keyof Month, 'month' | 'label' | 'short'>;

const TILES: {key: Key; label: string; group: 'Sales' | 'Teaching' | 'Money'; kind: Kind}[] = [
  {key: 'enquiries', label: 'Enquiries', group: 'Sales', kind: 'count'},
  {key: 'joined', label: 'Students joined', group: 'Sales', kind: 'count'},
  {key: 'conversion', label: 'Conversion', group: 'Sales', kind: 'percent'},
  {key: 'activeStudents', label: 'Active students', group: 'Teaching', kind: 'count'},
  {key: 'hours', label: 'Hours taught', group: 'Teaching', kind: 'hours'},
  {key: 'noShowRate', label: 'No-show rate', group: 'Teaching', kind: 'percent'},
  {key: 'billedPaise', label: 'Billed (class charges)', group: 'Money', kind: 'money'},
  {key: 'collectedPaise', label: 'Collected', group: 'Money', kind: 'money'},
  {key: 'teacherCostPaise', label: 'Teacher cost', group: 'Money', kind: 'money'},
  {key: 'marginPaise', label: 'Gross margin', group: 'Money', kind: 'money'},
  {key: 'duesPaise', label: 'Owed at month end', group: 'Money', kind: 'money'},
  {key: 'refundsPaise', label: 'Refunds', group: 'Money', kind: 'money'},
];

const ROWS: {key: Key; label: string; kind: Kind}[] = [
  {key: 'enquiries', label: 'Enquiries', kind: 'count'},
  {key: 'demos', label: 'Demos booked', kind: 'count'},
  {key: 'joined', label: 'Students joined', kind: 'count'},
  {key: 'conversion', label: 'Conversion (joined ÷ enquiries)', kind: 'percent'},
  {key: 'newStudents', label: 'New students registered', kind: 'count'},
  {key: 'activeStudents', label: 'Active students (had a class)', kind: 'count'},
  {key: 'hours', label: 'Hours taught', kind: 'hours'},
  {key: 'cancelled', label: 'Classes cancelled', kind: 'count'},
  {key: 'noShows', label: 'No-shows', kind: 'count'},
  {key: 'noShowRate', label: 'No-show rate', kind: 'percent'},
  {key: 'billedPaise', label: 'Billed', kind: 'money'},
  {key: 'collectedPaise', label: 'Collected', kind: 'money'},
  {key: 'refundsPaise', label: 'Refunds', kind: 'money'},
  {key: 'teacherCostPaise', label: 'Teacher cost', kind: 'money'},
  {key: 'marginPaise', label: 'Gross margin (billed − teacher cost)', kind: 'money'},
  {key: 'duesPaise', label: 'Owed by families at month end', kind: 'money'},
];

function fmt(value: number | null, kind: Kind): string {
  if (value === null) return '—';
  if (kind === 'money') return money(value, {whole: true});
  if (kind === 'percent') return percent(value);
  if (kind === 'hours') return `${value.toFixed(1)} h`;
  return number(value);
}

function TrendTile({label, kind, field, months}: {label: string; kind: Kind; field: Key; months: Month[]}) {
  const current = months[months.length - 2];
  const previous = months[months.length - 3];
  const now = current[field];
  const before = previous ? previous[field] : null;
  const max = Math.max(1, ...months.map(m => Math.abs(m[field] ?? 0)));
  const change = before !== null && now !== null && previous ? ` · ${now >= before ? 'up' : 'down'} from ${fmt(before, kind)} in ${previous.label}` : '';
  return (
    <Card padding={4}>
      <VStack gap={3}>
        <VStack gap={1}>
          <Text color="secondary" size="sm">
            {label}
          </Text>
          <Toned size="xl" strong tone={kind === 'money' && (now ?? 0) < 0 ? 'error' : undefined}>
            {fmt(now, kind)}
          </Toned>
          <Text color="secondary" size="sm">{`${current.label}${change}`}</Text>
        </VStack>
        <VStack gap={1}>
          {months.map((m, i) => {
            const value = m[field];
            const inProgress = i === months.length - 1;
            return (
              <HStack key={m.month} gap={2} vAlign="center">
                <Text color="secondary" size="sm" hasTabularNumbers>
                  {m.short}
                </Text>
                <StackItem size="fill">
                  <ProgressBar
                    label={`${m.label}: ${fmt(value, kind)}${inProgress ? ' (month in progress)' : ''}`}
                    isLabelHidden
                    value={Math.abs(value ?? 0)}
                    max={max}
                    variant={inProgress ? 'neutral' : (value ?? 0) < 0 ? 'error' : 'accent'}
                  />
                </StackItem>
                <Text size="sm" hasTabularNumbers>
                  {fmt(value, kind)}
                </Text>
              </HStack>
            );
          })}
        </VStack>
      </VStack>
    </Card>
  );
}

type TableRow = Record<string, string | number> & {id: string};

export default function Insights() {
  const unitId = Number(useParams().unit);
  const unit = unitById(unitId);
  const {data, error, isLoading, reload} = useResource<{months: Month[]; can: {seeMoney: boolean}}>(`/app-api/units/${unitId}/overview/insights`);

  if (isLoading) return <PageLoading />;
  if (error || !data || data.months.length < 2) {
    return (
      <Page title="Trends">
        <PageError error={error ?? new Error('No figures yet.')} onRetry={reload} />
      </Page>
    );
  }

  const {months} = data;
  const current = months[months.length - 2];
  const inProgress = months[months.length - 1];
  const groups = (['Sales', 'Teaching', 'Money'] as const).filter(group => group !== 'Money' || data.can.seeMoney);
  const rows = ROWS.filter(row => row.kind !== 'money' || data.can.seeMoney);

  const tableRows: TableRow[] = rows.map(row => {
    const out: TableRow = {id: row.key, measure: row.label};
    for (const m of months) out[m.month] = fmt(m[row.key], row.kind);
    return out;
  });
  const columns: TableColumn<TableRow>[] = [
    {key: 'measure', header: 'Measure', renderCell: r => <Text>{String(r.measure)}</Text>},
    ...months.map(m => ({key: m.month, header: m.label, align: 'end' as const, renderCell: (r: TableRow) => <Text hasTabularNumbers>{String(r[m.month])}</Text>})),
  ];

  return (
    <Page title="Trends" description={`${unit?.name ?? ''} · Headline figures are for ${current.label}, the last complete month. ${inProgress.label} is still in progress (grey bar).`}>
      {groups.map(group => (
        <VStack key={group} gap={3}>
          <Heading level={2}>{group}</Heading>
          <Grid columns={{minWidth: 260, repeat: 'fill'}} gap={3}>
            {TILES.filter(tile => tile.group === group).map(tile => (
              <TrendTile key={tile.key} label={tile.label} kind={tile.kind} field={tile.key} months={months} />
            ))}
          </Grid>
        </VStack>
      ))}
      <Card padding={4}>
        <VStack gap={3}>
          <Heading level={2}>All figures</Heading>
          <Table<TableRow> data={tableRows} columns={columns} idKey="id" hasHover textOverflow="truncate" />
          <Text color="secondary" size="sm">
            Billed counts class charges posted in the month; collected counts payments received, net of reversals. Reversals count against the month of the entry they undo.
          </Text>
        </VStack>
      </Card>
    </Page>
  );
}
