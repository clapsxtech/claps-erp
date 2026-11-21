// Printable family documents: fee request, payment or refund acknowledgement,
// invoice or credit note, and the monthly statement. On screen they sit in a
// card with Print and Back; on paper only the document prints.
import type {ReactNode} from 'react';
import {useParams, useSearchParams} from 'react-router';
import * as stylex from '@stylexjs/stylex';
import {VStack, HStack, StackItem} from '@astryxdesign/core/Layout';
import {Card} from '@astryxdesign/core/Card';
import {Grid} from '@astryxdesign/core/Grid';
import {Heading, Text} from '@astryxdesign/core/Text';
import {Button} from '@astryxdesign/core/Button';
import {Banner} from '@astryxdesign/core/Banner';
import {Table, type TableColumn} from '@astryxdesign/core/Table';
import {MetadataList, MetadataListItem} from '@astryxdesign/core/MetadataList';
import {EmptyState} from '@astryxdesign/core/EmptyState';
import {List, ListItem} from '@astryxdesign/core/List';
import {useIsPhone} from '../../lib/hooks';
import {Toned} from '../../components/Amount';
import {PageError, PageLoading} from '../../components/PageState';
import {SignOutButton} from './Layout';
import {lessonWhen, parentSession, plainDate, rupees, useParentData} from './shared';

const styles = stylex.create({
  screenOnly: {display: {default: null, '@media print': 'none'}},
  paper: {
    borderWidth: {default: null, '@media print': 0},
    boxShadow: {default: null, '@media print': 'none'},
    padding: {default: null, '@media print': 0},
  },
  page: {padding: {default: null, '@media print': 0}},
  preWrap: {whiteSpace: 'pre-line'},
});

type Centre = {name: string; phone: string | null};

interface ReceiptDoc {
  type: 'receipt';
  centre: Centre;
  receipt: {reference: string; isRefund: boolean; amountPaise: number; date: string; isWithdrawn: boolean};
}
interface RequestDoc {
  type: 'request';
  centre: Centre;
  request: {reference: string; requestedPaise: number; paidPaise: number; outstandingPaise: number; dueOn: string | null; status: string; notes: string | null};
}
type InvoiceLine = {
  id: number;
  description: string;
  sac: string | null;
  taxablePaise: number;
  amountPaise: number;
};
interface InvoiceDoc {
  type: 'invoice';
  centre: Centre;
  invoice: {
    title: string;
    number: string;
    isCreditNote: boolean;
    isCancelled: boolean;
    issueDate: string;
    periodLabel: string | null;
    credited: {number: string; issueDate: string} | null;
    supplier: {name: string; address: string | null; state: string | null; taxId: string | null; phone: string | null};
    billingName: string;
    placeOfSupply: string | null;
    hasGst: boolean;
    gstRatePercent: number;
    lines: InvoiceLine[];
    subtotalPaise: number;
    igstPaise: number;
    cgstPaise: number;
    sgstPaise: number;
    totalPaise: number;
    amountInWords: string;
    creditReason: string | null;
  };
}
type Doc = ReceiptDoc | RequestDoc | InvoiceDoc;

/** The shared print frame: controls on screen only, the document itself in a card that prints flat. */
function DocumentFrame({children}: {children: ReactNode}) {
  const isPhone = useIsPhone();
  return (
    <VStack hAlign="center">
            <VStack width="100%" maxWidth={860}>
          <VStack padding={4} gap={4} xstyle={styles.page}>
            <HStack gap={2} wrap="wrap" vAlign="center" xstyle={styles.screenOnly}>
              <Button label="Back to fees" variant="ghost" href="/parent/fees" />
              <StackItem size="fill">
                <Text color="secondary" size="sm">
                  Parent portal
                </Text>
              </StackItem>
              <Button label="Print" variant="primary" onClick={() => window.print()} />
              <SignOutButton />
            </HStack>
            <Card padding={isPhone ? 4 : 6} xstyle={styles.paper}>
              {children}
            </Card>
          </VStack>
            </VStack>
          </VStack>
  );
}

function CentreLine({centre}: {centre: Centre}) {
  return (
    <Text color="secondary">
      {centre.name}
      {centre.phone ? ` · ${centre.phone}` : ''}
    </Text>
  );
}

function StudentLine() {
  const {student} = parentSession();
  return (
    <Text>
      {student?.name} · {student?.code}
    </Text>
  );
}

function Receipt({doc}: {doc: ReceiptDoc}) {
  const {receipt, centre} = doc;
  const {student} = parentSession();
  return (
    <VStack gap={4}>
      <Heading level={1}>{receipt.isRefund ? 'Refund acknowledgement' : 'Payment acknowledgement'}</Heading>
      {receipt.isWithdrawn && <Banner status="error" title="WITHDRAWN" description="This record was corrected by the centre and is no longer valid. Please check your latest receipts." />}
      <CentreLine centre={centre} />
      <MetadataList orientation="horizontal">
        <MetadataListItem label="Reference">{receipt.reference}</MetadataListItem>
        <MetadataListItem label="Student">
          {student?.name} ({student?.code})
        </MetadataListItem>
        <MetadataListItem label={receipt.isRefund ? 'Paid back to the family' : 'Received'}>
          <Toned strong size="lg">
            {rupees(receipt.amountPaise)}
          </Toned>
        </MetadataListItem>
        <MetadataListItem label="Date">{plainDate(receipt.date)}</MetadataListItem>
      </MetadataList>
      <Text color="secondary" size="sm">
        This acknowledges a recorded {receipt.isRefund ? 'refund' : 'payment'}. It is not a tax invoice.
      </Text>
    </VStack>
  );
}

const REQUEST_STATUS: Record<string, string> = {pending: 'Pending', fulfilled: 'Fulfilled', cancelled: 'Cancelled'};

function PaymentRequest({doc}: {doc: RequestDoc}) {
  const {request, centre} = doc;
  return (
    <VStack gap={4}>
      <Heading level={1}>Fee request</Heading>
      <VStack gap={1}>
        <CentreLine centre={centre} />
        <StudentLine />
        <Text>Reference: {request.reference}</Text>
      </VStack>
      <MetadataList orientation="horizontal">
        <MetadataListItem label="Requested">{rupees(request.requestedPaise)}</MetadataListItem>
        <MetadataListItem label="Paid so far">{rupees(request.paidPaise)}</MetadataListItem>
        <MetadataListItem label="Outstanding">
          <Toned strong size="lg" tone={request.outstandingPaise > 0 && request.status === 'pending' ? 'error' : undefined}>
            {rupees(request.outstandingPaise)}
          </Toned>
        </MetadataListItem>
        <MetadataListItem label="Due date">{request.dueOn ? plainDate(request.dueOn) : 'Not specified'}</MetadataListItem>
        <MetadataListItem label="Status">{REQUEST_STATUS[request.status] ?? request.status}</MetadataListItem>
      </MetadataList>
      {request.notes && <Text xstyle={styles.preWrap}>{request.notes}</Text>}
    </VStack>
  );
}

function Invoice({doc}: {doc: InvoiceDoc}) {
  const isPhone = useIsPhone();
  const {invoice} = doc;
  const {student} = parentSession();
  const columns: TableColumn<InvoiceLine & {index: number}>[] = [
    {key: 'index', header: '#', width: {type: 'pixel', value: 48}, renderCell: row => <Text>{String(row.index)}</Text>},
    {key: 'description', header: 'Description', renderCell: row => <Text>{row.description}</Text>},
    ...(invoice.hasGst
      ? ([
          {key: 'sac', header: 'SAC', width: {type: 'pixel', value: 90}, renderCell: row => <Text>{row.sac ?? ''}</Text>},
          {key: 'taxablePaise', header: 'Taxable value', align: 'end', width: {type: 'pixel', value: 140}, renderCell: row => <Toned>{rupees(row.taxablePaise)}</Toned>},
        ] satisfies TableColumn<InvoiceLine & {index: number}>[])
      : []),
    {key: 'amountPaise', header: 'Amount', align: 'end', width: {type: 'pixel', value: 140}, renderCell: row => <Toned>{rupees(row.amountPaise)}</Toned>},
  ];
  const totals: {label: string; paise: number; strong?: boolean}[] = [];
  if (invoice.hasGst) {
    totals.push({label: 'Taxable value', paise: invoice.subtotalPaise});
    if (invoice.igstPaise) totals.push({label: `IGST ${invoice.gstRatePercent}%`, paise: invoice.igstPaise});
    else {
      totals.push({label: `CGST ${invoice.gstRatePercent / 2}%`, paise: invoice.cgstPaise});
      totals.push({label: `SGST ${invoice.gstRatePercent / 2}%`, paise: invoice.sgstPaise});
    }
  }
  totals.push({label: invoice.isCreditNote ? 'Total credited' : 'Total', paise: invoice.totalPaise, strong: true});

  return (
    <VStack gap={4}>
      <Heading level={1}>{invoice.title}</Heading>
      {invoice.isCancelled && <Banner status="error" title="CANCELLED — see credit note" />}
      <Grid columns={{minWidth: 240, repeat: 'fit'}} gap={4}>
        <VStack gap={0}>
          <Text weight="semibold">{invoice.supplier.name}</Text>
          {invoice.supplier.address && <Text xstyle={styles.preWrap}>{invoice.supplier.address}</Text>}
          {invoice.supplier.state && <Text>State: {invoice.supplier.state}</Text>}
          {invoice.supplier.taxId && <Text>GSTIN: {invoice.supplier.taxId}</Text>}
          {invoice.supplier.phone && <Text>Phone: {invoice.supplier.phone}</Text>}
        </VStack>
        <VStack gap={0}>
          <Text weight="semibold">
            {invoice.title} no. {invoice.number}
          </Text>
          <Text>Date: {plainDate(invoice.issueDate)}</Text>
          {invoice.periodLabel && <Text>For classes charged in: {invoice.periodLabel}</Text>}
          {invoice.credited && (
            <Text>
              Against invoice: {invoice.credited.number} dated {invoice.credited.issueDate}
            </Text>
          )}
        </VStack>
      </Grid>
      <VStack gap={0}>
        <Text>
          <Text weight="semibold">Billed to:</Text> {invoice.billingName} (student ID {student?.code})
        </Text>
        {invoice.placeOfSupply && <Text>Place of supply: {invoice.placeOfSupply}</Text>}
      </VStack>
      {isPhone ? (
        <List hasDividers edge="flush">
          {invoice.lines.map((line, i) => (
            <ListItem
              key={line.id}
              align="start"
              label={<Text textWrap="wrap">{`${i + 1}. ${line.description}`}</Text>}
              description={invoice.hasGst ? <Text color="secondary" size="sm">{`SAC ${line.sac ?? '—'} · Taxable ${rupees(line.taxablePaise)}`}</Text> : undefined}
              endContent={<Toned>{rupees(line.amountPaise)}</Toned>}
            />
          ))}
        </List>
      ) : (
        <Table data={invoice.lines.map((line, i) => ({...line, index: i + 1}))} columns={columns} idKey="id" density="compact" />
      )}
      <HStack hAlign="end">
        <VStack gap={1} width="100%" maxWidth={320}>
          {totals.map(row => (
            <HStack key={row.label} gap={3}>
              <StackItem size="fill">
                <Text weight={row.strong ? 'semibold' : undefined}>{row.label}</Text>
              </StackItem>
              <Toned strong={row.strong}>{rupees(row.paise)}</Toned>
            </HStack>
          ))}
        </VStack>
      </HStack>
      <Text>{invoice.amountInWords}</Text>
      <Text color="secondary" size="sm">
        {invoice.hasGst
          ? `Class fees include GST at ${invoice.gstRatePercent}%. Supply of commercial training and coaching services. Tax is not payable on reverse charge.`
          : 'No tax is charged on this invoice.'}
      </Text>
      {invoice.creditReason && (
        <Text color="secondary" size="sm">
          Reason: {invoice.creditReason}
        </Text>
      )}
    </VStack>
  );
}

export function ParentDocument() {
  const {type, id} = useParams();
  const {data, error, reload} = useParentData<Doc>(`/parent-api/documents/${type}/${id}`);
  if (error) {
    return (
      <DocumentFrame>
        <PageError error={error} onRetry={reload} />
      </DocumentFrame>
    );
  }
  if (!data) return <PageLoading />;
  return (
    <DocumentFrame>
      {data.type === 'receipt' && <Receipt doc={data} />}
      {data.type === 'request' && <PaymentRequest doc={data} />}
      {data.type === 'invoice' && <Invoice doc={data} />}
    </DocumentFrame>
  );
}

type StatementRow = {
  id: number;
  startsAt: string;
  teacher: string;
  subject: string;
  isDemo: boolean;
  minutes: number;
  chargePaise: number;
};
interface StatementData {
  month: string;
  monthLabel: string;
  rows: StatementRow[];
  totalMinutes: number;
  account: {openingPaise: number; lines: {label: string; paise: number}[]; closingPaise: number};
}

function signed(paise: number): string {
  return `${rupees(Math.abs(paise))}${paise > 0 ? ' credit' : paise < 0 ? ' due' : ''}`;
}

export function ParentStatement() {
  const isPhone = useIsPhone();
  const [params] = useSearchParams();
  const month = params.get('month') ?? '';
  const {centre, student} = parentSession();
  const {data, error, reload} = useParentData<StatementData>('/parent-api/statement', {month});
  if (error) {
    return (
      <DocumentFrame>
        <PageError error={error} onRetry={reload} />
      </DocumentFrame>
    );
  }
  if (!data) return <PageLoading />;
  const columns: TableColumn<StatementRow>[] = [
    {key: 'startsAt', header: 'Date and time', renderCell: row => <Text hasTabularNumbers>{lessonWhen(row.startsAt)}</Text>},
    {key: 'teacher', header: 'Teacher', renderCell: row => <Text>{row.teacher}</Text>},
    {key: 'subject', header: 'Subject', renderCell: row => <Text>{`${row.subject}${row.isDemo ? ' (free demo)' : ''}`}</Text>},
    {key: 'minutes', header: 'Minutes', align: 'end', renderCell: row => <Toned>{String(row.minutes)}</Toned>},
    {key: 'chargePaise', header: 'Class charge', align: 'end', renderCell: row => <Toned>{rupees(row.chargePaise)}</Toned>},
  ];
  return (
    <DocumentFrame>
      <VStack gap={4}>
        <VStack gap={1}>
          <Heading level={1}>{centre?.name ?? 'Statement'}</Heading>
          <Heading level={2}>Attendance statement · {data.monthLabel}</Heading>
          <Text color="secondary">
            {student?.name} · {student?.code} · Times shown in {student?.timezone}
          </Text>
        </VStack>
        {data.rows.length === 0 ? (
          <EmptyState title="No reported classes this month" description="Classes appear here once the teacher records them." isCompact />
        ) : isPhone ? (
          <List hasDividers edge="flush">
            {data.rows.map(row => (
              <ListItem
                key={row.id}
                align="start"
                label={lessonWhen(row.startsAt)}
                description={<Text color="secondary">{`${row.subject}${row.isDemo ? ' (free demo)' : ''} · ${row.teacher}`}</Text>}
                endContent={
                  <VStack gap={0} hAlign="end">
                    <Toned>{rupees(row.chargePaise)}</Toned>
                    <Text color="secondary" size="sm">{`${row.minutes} min`}</Text>
                  </VStack>
                }
              />
            ))}
          </List>
        ) : (
          <Table data={data.rows} columns={columns} idKey="id" density="compact" />
        )}
        <Text>Your child had {data.totalMinutes} minutes of class in this period. If anything below looks wrong, please let the centre know.</Text>
        <Text color="secondary" size="sm">
          Charges are worked out from the class time and your agreed hourly rate.
        </Text>
        <Heading level={2}>Account summary for {data.monthLabel}</Heading>
        <VStack gap={2}>
          <HStack gap={3}>
            <StackItem size="fill">
              <Text>Balance at the start of the month</Text>
            </StackItem>
            <Toned>{signed(data.account.openingPaise)}</Toned>
          </HStack>
          {data.account.lines.map(line => (
            <HStack gap={3} key={line.label}>
              <StackItem size="fill">
                <Text>{line.label}</Text>
              </StackItem>
              <Toned>{rupees(line.paise)}</Toned>
            </HStack>
          ))}
          <HStack gap={3}>
            <StackItem size="fill">
              <Text weight="semibold">Balance at the end of the month</Text>
            </StackItem>
            <Toned strong tone={data.account.closingPaise < 0 ? 'error' : undefined}>
              {signed(data.account.closingPaise)}
            </Toned>
          </HStack>
        </VStack>
        <Text color="secondary" size="sm">
          A credit means you have paid ahead; an amount due still needs paying. A class appears here once the centre has checked it, so this list can be a little behind the classes above.
        </Text>
      </VStack>
    </DocumentFrame>
  );
}
