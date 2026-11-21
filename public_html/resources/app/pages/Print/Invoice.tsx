// Invoice, tax invoice or credit note (classic erp/invoice.blade.php, staff copy).
import {useParams} from 'react-router';
import {VStack, HStack, StackItem} from '@astryxdesign/core/Layout';
import {Heading, Text} from '@astryxdesign/core/Text';
import {Banner} from '@astryxdesign/core/Banner';
import {Table, pixel, proportional, type TableColumn} from '@astryxdesign/core/Table';
import {useResource} from '../../lib/hooks';
import {DocumentState, PrintControls, SummaryTable, useDocumentTitle} from './PrintFrame';

interface Line extends Record<string, unknown> {
  number: number;
  description: string;
  sac: string | null;
  taxable: string;
  amount: string;
}

interface InvoiceData {
  id: number;
  title: string;
  documentType: string;
  number: string;
  isCancelled: boolean;
  supplier: {name: string; address: string | null; state: string | null; taxId: string | null; phone: string | null};
  issueDate: string;
  billingPeriod: string | null;
  credited: {number: string; issueDate: string} | null;
  billingName: string;
  studentCode: string | null;
  placeOfSupply: string | null;
  hasGst: boolean;
  lines: Line[];
  taxes: {label: string; amount: string}[];
  totalLabel: string;
  total: string;
  words: string;
  taxNote: string;
  reason: string | null;
}

export default function Invoice() {
  const {unit, id} = useParams();
  const {data, error, reload} = useResource<InvoiceData>(`/app-api/units/${unit}/print/invoice/${id}`);
  useDocumentTitle(data ? `${data.title} ${data.number}` : undefined);
  if (!data) return <DocumentState error={error} onRetry={reload} />;
  const s = data.supplier;
  const columns: TableColumn<Line>[] = [
    {key: 'number', header: '#', width: pixel(32), renderCell: l => <Text hasTabularNumbers>{String(l.number)}</Text>},
    {key: 'description', header: 'Description', width: proportional(1, {minWidth: 72}), renderCell: l => <Text>{l.description}</Text>},
    ...(data.hasGst
      ? ([
          {key: 'sac', header: 'SAC', width: pixel(68), renderCell: l => <Text>{l.sac ?? ''}</Text>},
          {key: 'taxable', header: 'Taxable value', align: 'end', width: pixel(104), renderCell: l => <Text hasTabularNumbers>{l.taxable}</Text>},
        ] as TableColumn<Line>[])
      : []),
    {key: 'amount', header: 'Amount', align: 'end', width: pixel(104), renderCell: l => <Text hasTabularNumbers>{l.amount}</Text>},
  ];
  const totals = [...data.taxes, {label: data.totalLabel, amount: data.total, isTotal: true}];
  return (
    <VStack gap={4}>
      <PrintControls printLabel="Print" back={{label: 'Back to invoices', href: `/app/u/${unit}/accounts?view=invoices`}} />
      <Heading level={1}>{data.title}</Heading>
      {data.isCancelled && <Banner status="error" title="CANCELLED — see credit note" collapsible={false} />}
      <HStack gap={4} wrap="wrap" vAlign="start">
        <StackItem size="fill">
          <VStack gap={0.5}>
            <Text weight="semibold">{s.name}</Text>
            {s.address?.split('\n').map((line, i) => <Text key={i}>{line}</Text>)}
            {s.state && <Text>{`State: ${s.state}`}</Text>}
            {s.taxId && <Text>{`GSTIN: ${s.taxId}`}</Text>}
            {s.phone && <Text>{`Phone: ${s.phone}`}</Text>}
          </VStack>
        </StackItem>
        <StackItem size="fill">
          <VStack gap={0.5}>
            <Text weight="semibold">{`${data.title} no. ${data.number}`}</Text>
            <Text>{`Date: ${data.issueDate}`}</Text>
            {data.billingPeriod && <Text>{`For classes charged in: ${data.billingPeriod}`}</Text>}
            {data.credited && <Text>{`Against invoice: ${data.credited.number} dated ${data.credited.issueDate}`}</Text>}
          </VStack>
        </StackItem>
      </HStack>
      <VStack gap={0.5}>
        <Text>
          <Text weight="semibold" display="inline">Billed to:</Text>
          {` ${data.billingName} (student ID ${data.studentCode})`}
        </Text>
        {data.placeOfSupply && <Text>{`Place of supply: ${data.placeOfSupply}`}</Text>}
      </VStack>
      <VStack isScrollable>
        <Table<Line> data={data.lines} columns={columns} idKey="number" density="compact" />
      </VStack>
      <HStack hAlign="end">
        <VStack width="100%" maxWidth="420px">
          <SummaryTable rows={totals} />
        </VStack>
      </HStack>
      <Text>{data.words}</Text>
      <Text size="sm" color="secondary">{data.taxNote}</Text>
      {data.reason && <Text size="sm" color="secondary">{`Reason: ${data.reason}`}</Text>}
    </VStack>
  );
}
