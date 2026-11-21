// Sales report: activity by staff, follow-ups due, and
// outcomes by counsellor and campaign for the leads this user can see. Collections
// appear only for money roles; the server decides that.
import {useParams} from 'react-router';
import {VStack, HStack} from '@astryxdesign/core/Layout';
import {Card} from '@astryxdesign/core/Card';
import {Heading, Text} from '@astryxdesign/core/Text';
import {DateInput} from '@astryxdesign/core/DateInput';
import {Button} from '@astryxdesign/core/Button';
import {type TableColumn} from '@astryxdesign/core/Table';
import {RowTable} from '../../components/RowTable';
import {EmptyState} from '@astryxdesign/core/EmptyState';
import {Page, PageError, PageLoading} from '../../components/PageState';
import {Metric, MetricRow} from '../../components/Metric';
import {Toned} from '../../components/Amount';
import {useListQuery, useResource} from '../../lib/hooks';
import {date, money, number} from '../../lib/format';
import {unitById} from '../../lib/session';
import {percent, todayIn} from './shared';

type ISODate = `${number}${number}${number}${number}-${number}${number}-${number}${number}`;

interface Outcome extends Record<string, unknown> {
  enquiries: number;
  demoLeads: number;
  joined: number;
  lost: number;
}

interface Report {
  from: string;
  to: string;
  timezone: string;
  staff: {name: string | null; enquiries: number; repeats: number; contacts: number; demos: number; feedback: number; registrations: number}[];
  due: {today: number; recent: number; older: number; unscheduled: number};
  counsellors: (Outcome & {counsellor: string})[];
  campaigns: (Outcome & {source: string})[];
  collections: {currency: string; netPaise: number}[] | null;
}

type Row = Record<string, unknown>;

const num = (value: unknown) => <Text hasTabularNumbers>{number(Number(value))}</Text>;

function outcomeColumns(first: {key: string; header: string}): TableColumn<Row>[] {
  return [
    {key: first.key, header: first.header, renderCell: r => <Text>{String(r[first.key])}</Text>},
    {key: 'enquiries', header: 'Enquiries', align: 'end', renderCell: r => num(r.enquiries)},
    {key: 'demoLeads', header: 'With a demo', align: 'end', renderCell: r => num(r.demoLeads)},
    {key: 'joined', header: 'Joined', align: 'end', renderCell: r => num(r.joined)},
    {key: 'lost', header: 'Lost', align: 'end', renderCell: r => num(r.lost)},
    {
      key: 'conversion',
      header: 'Conversion',
      align: 'end',
      renderCell: r => <Text hasTabularNumbers>{Number(r.enquiries) ? percent((100 * Number(r.joined)) / Number(r.enquiries)) : '—'}</Text>,
    },
  ];
}

const staffColumns: TableColumn<Row>[] = [
  {key: 'name', header: 'Staff', renderCell: r => <Text>{(r.name as string | null) || 'System / unavailable account'}</Text>},
  {key: 'enquiries', header: 'New enquiries', align: 'end', renderCell: r => num(r.enquiries)},
  {key: 'repeats', header: 'Repeat enquiries', align: 'end', renderCell: r => num(r.repeats)},
  {key: 'contacts', header: 'Contact attempts', align: 'end', renderCell: r => num(r.contacts)},
  {key: 'demos', header: 'Demos booked', align: 'end', renderCell: r => num(r.demos)},
  {key: 'feedback', header: 'Demo feedback', align: 'end', renderCell: r => num(r.feedback)},
  {key: 'registrations', header: 'Registrations', align: 'end', renderCell: r => num(r.registrations)},
];

function Section({title, description, rows, columns, idKey, empty}: {title: string; description: string; rows: Row[]; columns: TableColumn<Row>[]; idKey: string; empty: string}) {
  return (
    <Card padding={4}>
      <VStack gap={3}>
        <VStack gap={1}>
          <Heading level={2}>{title}</Heading>
          <Text color="secondary">{description}</Text>
        </VStack>
        {rows.length === 0 ? <EmptyState title={empty} isCompact /> : <RowTable<Row> data={rows} columns={columns} idKey={r => String(r[idKey] ?? 'none')} hasHover textOverflow="truncate" />}
      </VStack>
    </Card>
  );
}

export default function SalesReport() {
  const unitId = Number(useParams().unit);
  const unit = unitById(unitId);
  const today = unit ? todayIn(unit.timezone) : '';
  const [query, setQuery] = useListQuery({from: '', to: ''});
  const {data, error, isLoading, isRefreshing, reload} = useResource<Report>(`/app-api/units/${unitId}/overview/sales-report`, query);

  if (isLoading) return <PageLoading />;
  if (!data) {
    return (
      <Page title="Sales report">
        <PageError error={error ?? new Error('Not available')} onRetry={reload} />
      </Page>
    );
  }

  const monthStart = today ? `${today.slice(0, 8)}01` : '';
  const base = `/app/u/${unitId}/admissions`;

  return (
    <Page
      title="Sales report"
      description={`${date(data.from)} to ${date(data.to)} · ${data.timezone} · up to 93 days`}
      crumbs={[{label: 'Admissions', href: base}, {label: 'Sales report'}]}>
      <HStack gap={2} vAlign="end" wrap="wrap">
        <DateInput label="From" value={(query.from || data.from) as ISODate} max={(query.to || data.to) as ISODate} onChange={value => setQuery({from: value ?? ''})} />
        <DateInput label="To" value={(query.to || data.to) as ISODate} min={(query.from || data.from) as ISODate} onChange={value => setQuery({to: value ?? ''})} />
        <Button label="Today" variant="ghost" onClick={() => setQuery({from: '', to: ''})} />
        {monthStart && <Button label="This month" variant="ghost" onClick={() => setQuery({from: monthStart, to: today})} />}
      </HStack>
      {error && <PageError error={error} onRetry={reload} />}
      {isRefreshing && <Text color="secondary">Updating…</Text>}

      <VStack gap={2}>
        <Heading level={2}>Follow-ups needing attention now</Heading>
        <MetricRow>
          <Metric label="Due today" value={number(data.due.today)} href={`${base}?view=today`} />
          <Metric label="Overdue 1–3 days" value={number(data.due.recent)} tone={data.due.recent ? 'warning' : undefined} href={`${base}?view=today`} />
          <Metric label="Overdue more than 3 days" value={number(data.due.older)} tone={data.due.older ? 'error' : undefined} href={`${base}?view=today`} />
          <Metric label="No next action" value={number(data.due.unscheduled)} href={base} />
        </MetricRow>
      </VStack>

      <Section
        title="Sales activity"
        description="Recorded events by the person who performed them, for leads you can currently access. Registrations use the date recorded, not a backdated joining date."
        rows={data.staff as Row[]}
        columns={staffColumns}
        idKey="name"
        empty="No recorded activity in this period"
      />
      <Section
        title="Outcomes by counsellor"
        description="Leads first enquired in this period, grouped by their current owner. Transferred leads count for whoever holds them now."
        rows={data.counsellors as Row[]}
        columns={outcomeColumns({key: 'counsellor', header: 'Counsellor'})}
        idKey="counsellor"
        empty="No enquiries started in this period"
      />
      <Section
        title="Campaign outcomes"
        description="Leads first enquired in this period, grouped by source. Outcomes are their current state; each lead counts once; demos include cancelled bookings."
        rows={data.campaigns as Row[]}
        columns={outcomeColumns({key: 'source', header: 'Source'})}
        idKey="source"
        empty="No enquiries started in this period"
      />

      {data.collections && (
        <Card padding={4}>
          <VStack gap={2}>
            <Heading level={2}>Team collections in this period</Heading>
            <Text color="secondary">Student receipts less receipt reversals, on each entry's date. These totals are not staff commission.</Text>
            {data.collections.length === 0 ? (
              <Text>No receipts or receipt reversals in this period.</Text>
            ) : (
              data.collections.map(row => (
                <Toned key={row.currency} size="lg" strong>
                  {row.currency === 'INR' ? money(row.netPaise) : `${row.currency} ${(row.netPaise / 100).toFixed(2)}`}
                </Toned>
              ))
            )}
          </VStack>
        </Card>
      )}
      <Text color="secondary" size="sm">
        Ad spend and incentive rules are not configured; ROI and incentive payouts are not calculated.
      </Text>
    </Page>
  );
}
