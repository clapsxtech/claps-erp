// Payment request.
import {useParams} from 'react-router';
import {VStack} from '@astryxdesign/core/Layout';
import {Heading, Text} from '@astryxdesign/core/Text';
import {useResource} from '../../lib/hooks';
import {DocumentState, PrintControls, useDocumentTitle} from './PrintFrame';

interface RequestData {
  id: number;
  businessName: string;
  businessPhone: string | null;
  studentName: string | null;
  studentCode: string | null;
  reference: string;
  status: string;
  requested: string;
  covered: string;
  outstanding: string;
  due: string;
  notes: string | null;
}

export default function PaymentRequest() {
  const {unit, id} = useParams();
  const {data, error, reload} = useResource<RequestData>(`/app-api/units/${unit}/print/request/${id}`);
  useDocumentTitle(data ? `Payment request ${data.reference}` : undefined);
  if (!data) return <DocumentState error={error} onRetry={reload} />;
  return (
    <VStack gap={3}>
      <PrintControls printLabel="Print request" back={{label: 'Back to payments', href: `/app/u/${unit}/payments?tab=requests`}} />
      <Heading level={1}>Payment request</Heading>
      <Text weight="semibold">{data.businessPhone ? `${data.businessName} · ${data.businessPhone}` : data.businessName}</Text>
      <Text>{`${data.studentName} · ${data.studentCode}`}</Text>
      <Text>{`Reference: ${data.reference}`}</Text>
      <Text>{`Status: ${data.status}`}</Text>
      <Text hasTabularNumbers>{`Requested: ${data.requested}`}</Text>
      <Text hasTabularNumbers>{`Covered by active received payments: ${data.covered}`}</Text>
      <Text hasTabularNumbers weight="semibold">{`Outstanding request: ${data.outstanding}`}</Text>
      <Text>{`Due: ${data.due}`}</Text>
      {data.notes && <Text>{data.notes}</Text>}
      <Text color="secondary">This is a request, not proof of payment or a tax invoice. No message was sent automatically.</Text>
    </VStack>
  );
}
