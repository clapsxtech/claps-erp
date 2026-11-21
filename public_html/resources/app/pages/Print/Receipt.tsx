// Payment or refund acknowledgement.
import {useParams} from 'react-router';
import {VStack} from '@astryxdesign/core/Layout';
import {Heading, Text} from '@astryxdesign/core/Text';
import {Link} from '@astryxdesign/core/Link';
import {Banner} from '@astryxdesign/core/Banner';
import {useResource} from '../../lib/hooks';
import {DocumentState, PrintControls, useDocumentTitle} from './PrintFrame';

interface ReceiptData {
  id: number;
  kind: 'payment' | 'refund';
  title: string;
  businessName: string;
  businessPhone: string | null;
  reference: string;
  studentName: string | null;
  studentCode: string | null;
  amountLabel: string;
  amount: string;
  recordedAt: string;
  installationTimezone: string;
  statement: string;
  reversal: {reference: string} | null;
  replacement: {id: number; reference: string} | null;
}

export default function Receipt() {
  const {unit, id} = useParams();
  const {data, error, reload} = useResource<ReceiptData>(`/app-api/units/${unit}/print/receipt/${id}`);
  useDocumentTitle(data ? `${data.title} ${data.reference}` : undefined);
  if (!data) return <DocumentState error={error} onRetry={reload} />;
  return (
    <VStack gap={3}>
      <PrintControls printLabel="Print acknowledgement" back={{label: 'Back to payments', href: `/app/u/${unit}/payments?tab=ledger`}} />
      <Heading level={1}>{data.title}</Heading>
      {data.reversal && (
        <Banner
          status="error"
          title="REVERSED / CORRECTED RECORD"
          description={`This original acknowledgement is no longer valid as an active receipt. Bookkeeping reversal reference: ${data.reversal.reference}. No bank refund is implied.`}
          collapsible={false}
        />
      )}
      {data.replacement && (
        <Text>
          {'Replacement reference: '}
          <Link href={`/print/u/${unit}/receipt/${data.replacement.id}`}>{data.replacement.reference}</Link>
        </Text>
      )}
      <Text weight="semibold">{data.businessPhone ? `${data.businessName} · ${data.businessPhone}` : data.businessName}</Text>
      <Text>{`Reference: ${data.reference}`}</Text>
      <Text>{`Student: ${data.studentName} (${data.studentCode})`}</Text>
      <Text weight="semibold" hasTabularNumbers>{`${data.amountLabel}: ${data.amount}`}</Text>
      <Text>{`Recorded at ${data.recordedAt} (installation time: ${data.installationTimezone})`}</Text>
      <Text color="secondary">{data.statement}</Text>
    </VStack>
  );
}
