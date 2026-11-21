import {VStack, HStack} from '@astryxdesign/core/Layout';
import {Card} from '@astryxdesign/core/Card';
import {Heading, Text} from '@astryxdesign/core/Text';
import {Button} from '@astryxdesign/core/Button';
import {List, ListItem} from '@astryxdesign/core/List';
import {EmptyState} from '@astryxdesign/core/EmptyState';
import {Toned} from '../../components/Amount';
import {Status} from '../../components/Status';
import {PageError, PageLoading} from '../../components/PageState';
import {BalanceCard} from './Overview';
import {plainDate, rupees, useParentData} from './shared';

interface FeesData {
  balancePaise: number;
  requests: {id: number; reference: string; requestedPaise: number; paidPaise: number; outstandingPaise: number; dueOn: string | null; status: 'pending' | 'fulfilled' | 'cancelled'}[];
  invoices: {id: number; number: string; issueDate: string; period: string | null; totalPaise: number; isCreditNote: boolean; isCancelled: boolean}[];
  receipts: {id: number; date: string; reference: string; isRefund: boolean; amountPaise: number; isWithdrawn: boolean}[];
  months: {month: string; label: string}[];
}

const STATUS = {pending: {label: 'To pay', tone: 'due'}, fulfilled: {label: 'Paid', tone: 'paid'}, cancelled: {label: 'Cancelled', tone: 'cancelled'}} as const;

export default function ParentFees() {
  const {data, error, reload} = useParentData<FeesData>('/parent-api/sections/fees');
  if (error) return <PageError error={error} onRetry={reload} />;
  if (!data) return <PageLoading />;
  const open = data.requests.filter(r => r.status === 'pending' && r.outstandingPaise > 0);

  return (
    <VStack gap={6}>
      <BalanceCard paise={data.balancePaise} openRequests={open.length} />

      <VStack gap={2}>
        <Heading level={2}>Fee requests</Heading>
        {data.requests.length === 0 ? (
          <EmptyState title="No fee requests" isCompact />
        ) : (
          <Card padding={0}>
            <List hasDividers>
              {data.requests.map(request => (
                <ListItem
                  key={request.id}
                  align="start"
                  href={`/parent/documents/request/${request.id}`}
                  label={request.reference}
                  description={
                    <VStack gap={1}>
                      <Text color="secondary" size="sm">
                        Requested {rupees(request.requestedPaise)} · Paid {rupees(request.paidPaise)}
                      </Text>
                      <Text color="secondary" size="sm">
                        Due {request.dueOn ? plainDate(request.dueOn) : '—'}
                      </Text>
                    </VStack>
                  }
                  endContent={
                    <VStack gap={1} hAlign="end">
                      <Toned strong tone={request.outstandingPaise > 0 && request.status === 'pending' ? 'error' : undefined}>
                        {rupees(request.outstandingPaise)}
                      </Toned>
                      <Status value={STATUS[request.status]?.tone ?? request.status} label={STATUS[request.status]?.label ?? request.status} />
                    </VStack>
                  }
                />
              ))}
            </List>
          </Card>
        )}
        {data.requests.length > 0 && (
          <Text color="secondary" size="sm">
            The amount on the right is still outstanding. Tap a request to view or print it.
          </Text>
        )}
      </VStack>

      <VStack gap={2}>
        <Heading level={2}>Invoices</Heading>
        {data.invoices.length === 0 ? (
          <EmptyState title="No invoices yet" isCompact />
        ) : (
          <Card padding={0}>
            <List hasDividers>
              {data.invoices.map(invoice => (
                <ListItem
                  key={invoice.id}
                  href={`/parent/documents/invoice/${invoice.id}`}
                  label={`${invoice.number}${invoice.isCreditNote ? ' (credit note)' : invoice.isCancelled ? ' (cancelled)' : ''}`}
                  description={
                    <Text color="secondary" size="sm">
                      {plainDate(invoice.issueDate)}
                      {invoice.period ? ` · Period ${invoice.period}` : ''}
                    </Text>
                  }
                  endContent={<Toned>{rupees(invoice.totalPaise)}</Toned>}
                />
              ))}
            </List>
          </Card>
        )}
      </VStack>

      <VStack gap={2}>
        <Heading level={2}>Receipts and refunds</Heading>
        {data.receipts.length === 0 ? (
          <EmptyState title="No payments recorded yet" isCompact />
        ) : (
          <Card padding={0}>
            <List hasDividers>
              {data.receipts.map(entry => (
                <ListItem
                  key={entry.id}
                  href={`/parent/documents/receipt/${entry.id}`}
                  label={`${entry.reference}${entry.isWithdrawn ? ' (withdrawn)' : ''}`}
                  description={
                    <Text color="secondary" size="sm">
                      {plainDate(entry.date)}
                    </Text>
                  }
                  endContent={<Toned tone={entry.isRefund ? undefined : 'success'}>{entry.isRefund ? `Refund ${rupees(entry.amountPaise)}` : rupees(entry.amountPaise)}</Toned>}
                />
              ))}
            </List>
          </Card>
        )}
      </VStack>

      <VStack gap={2}>
        <Heading level={2}>Monthly statements</Heading>
        {data.months.length === 0 ? (
          <Text color="secondary">No statements yet.</Text>
        ) : (
          <HStack gap={2} wrap="wrap">
            {data.months.map(month => (
              <Button key={month.month} label={month.label} variant="secondary" href={`/parent/statement?month=${month.month}`} />
            ))}
          </HStack>
        )}
      </VStack>
    </VStack>
  );
}
