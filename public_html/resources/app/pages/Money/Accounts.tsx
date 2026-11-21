// Accounts & tax. Books are kept automatically
// from Money; staff add manual journals (reversal only, never edited), issue
// invoice runs and cancel invoices with credit notes, all through the
// books-* actions. The overview load also posts any operational rows not yet in
// the books (a bounded batch), so it always loads first.
import {useState, type ReactNode} from 'react';
import {useParams, useSearchParams} from 'react-router';
import {VStack, HStack, StackItem} from '@astryxdesign/core/Layout';
import {Card} from '@astryxdesign/core/Card';
import {Heading, Text} from '@astryxdesign/core/Text';
import {Link} from '@astryxdesign/core/Link';
import {Button} from '@astryxdesign/core/Button';
import {Icon} from '@astryxdesign/core/Icon';
import {Selector} from '@astryxdesign/core/Selector';
import {DateInput} from '@astryxdesign/core/DateInput';
import {TextInput} from '@astryxdesign/core/TextInput';
import {TabList, Tab} from '@astryxdesign/core/TabList';
import {Table, type TableColumn} from '@astryxdesign/core/Table';
import {Banner} from '@astryxdesign/core/Banner';
import {EmptyState} from '@astryxdesign/core/EmptyState';
import {Pagination} from '@astryxdesign/core/Pagination';
import {MoreMenu} from '@astryxdesign/core/MoreMenu';
import {Drawer, DrawerHeader} from '@astryxdesign/core/Drawer';
import {useToast} from '@astryxdesign/core/Toast';
import {PlusIcon} from '@heroicons/react/24/outline';
import {Page, PageError, PageLoading} from '../../components/PageState';
import {FormDrawer} from '../../components/FormDrawer';
import {Status} from '../../components/Status';
import {Metric, MetricRow} from '../../components/Metric';
import {Toned} from '../../components/Amount';
import {useResource} from '../../lib/hooks';
import {ApiError, postJson} from '../../lib/api';
import {date, money, number, titleCase} from '../../lib/format';
import {session, unitById} from '../../lib/session';
import {actionUrl, ConfirmActionDialog, monthOptions, today} from './shared';

type View = 'overview' | 'journals' | 'ledger' | 'reports' | 'invoices' | 'tax' | 'chart';
type ISODate = `${number}${number}${number}${number}-${number}${number}-${number}${number}`;
type Row = Record<string, unknown>;

interface AccountRef {
  id: number;
  code: string;
  name: string;
  type: string;
}

interface Overview {
  team: {taxMode: string; taxId: string | null; state: string | null; gstRatePercent: number; sac: string};
  from: string;
  to: string;
  pendingPosts: number;
  trial: {rows: (AccountRef & {debitPaise: number; creditPaise: number})[]; debitPaise: number; creditPaise: number; isBalanced: boolean};
  pnl: {incomePaise: number; expensePaise: number; netPaise: number};
  types: string[];
  can: Record<'books-account' | 'books-journal' | 'books-journal-reverse' | 'books-invoice-run' | 'books-invoice-cancel', boolean>;
}

interface Journal {
  id: number;
  entryDate: string;
  reference: string;
  memo: string | null;
  source: string;
  reversesId: number | null;
  isReversed: boolean;
  debitPaise: number;
  creditPaise: number;
  lines: {accountCode: string; accountName: string; debitPaise: number; creditPaise: number}[];
}

interface Meta {
  page: number;
  perPage: number;
  total: number;
}

const VIEWS: View[] = ['overview', 'journals', 'ledger', 'reports', 'invoices', 'tax', 'chart'];

function Amount({paise}: {paise: number}) {
  return paise ? <Toned>{money(paise)}</Toned> : <Text color="secondary"> </Text>;
}

function SimpleTable<T extends Row>({rows, columns, idKey, empty}: {rows: T[]; columns: TableColumn<T>[]; idKey: (row: T) => string | number; empty: string}) {
  if (rows.length === 0) return <EmptyState title={empty} isCompact />;
  return <Table<T> data={rows} columns={columns} idKey={idKey} hasHover textOverflow="truncate" density="balanced" />;
}

function Pager({meta, onPage, label}: {meta: Meta | undefined; onPage: (page: number) => void; label: string}) {
  if (!meta || meta.total <= meta.perPage) return meta ? <Text color="secondary" hasTabularNumbers>{`${number(meta.total)} total`}</Text> : null;
  return (
    <HStack gap={3} vAlign="center" hAlign="between" wrap="wrap">
      <Text color="secondary" hasTabularNumbers>{`${number(meta.total)} total`}</Text>
      <Pagination page={meta.page} onChange={onPage} totalItems={meta.total} pageSize={meta.perPage} size="sm" label={label} />
    </HStack>
  );
}

export default function Accounts() {
  const unitId = Number(useParams().unit);
  const unit = unitById(unitId);
  const [params, setParams] = useSearchParams();
  const raw = params.get('view') as View | null;
  const view: View = raw && VIEWS.includes(raw) ? raw : 'overview';
  const from = params.get('from') ?? '';
  const to = params.get('to') ?? '';
  const setParam = (patch: Record<string, string>) =>
    setParams(
      current => {
        const next = new URLSearchParams(current);
        for (const [key, value] of Object.entries(patch)) {
          if (value) next.set(key, value);
          else next.delete(key);
        }
        return next;
      },
      {replace: true},
    );
  const overview = useResource<Overview>(`/app-api/units/${unitId}/accounts/overview`, {from, to});
  const [creating, setCreating] = useState<'journal' | 'invoice-run' | 'account' | null>(null);
  const [nonce, setNonce] = useState(0);
  const refresh = () => {
    overview.reload();
    setNonce(n => n + 1);
  };

  if (overview.isLoading) return <PageLoading />;
  if (overview.error || !overview.data) {
    return (
      <Page title="Accounts & tax">
        <PageError error={overview.error ?? new Error('Not found')} onRetry={overview.reload} />
      </Page>
    );
  }
  const data = overview.data;
  const gst = data.team.taxMode === 'gst';
  // Sheets authority makes the app read-only even where the role could act.
  const readOnly = session().sheetsAuthority || Boolean(unit?.isArchived);
  const can = Object.fromEntries(Object.entries(data.can).map(([action, allowed]) => [action, allowed && !readOnly])) as Overview['can'];

  const actions: ReactNode = (
    <>
      {can['books-invoice-run'] && <Button label={gst ? 'Issue tax invoices' : 'Issue invoices'} variant="secondary" onClick={() => setCreating('invoice-run')} />}
      {can['books-journal'] && <Button label="Manual journal" variant="primary" icon={<Icon icon={PlusIcon} size="sm" />} onClick={() => setCreating('journal')} />}
      {can['books-account'] && <MoreMenu label="More accounts actions" variant="secondary" alignment="end" items={[{label: 'Add an account', onClick: () => setCreating('account')}]} />}
    </>
  );

  const dateFilters = (
    <HStack gap={2} wrap="wrap" vAlign="end">
      <DateInput label="From" value={(from || data.from) as ISODate} onChange={value => setParam({from: value ?? ''})} />
      <DateInput label="To" value={(to || data.to) as ISODate} onChange={value => setParam({to: value ?? ''})} />
    </HStack>
  );

  return (
    <Page
      title="Accounts & tax"
      description={
        gst
          ? `Double-entry books kept automatically from Money, plus your own journals. GST ${data.team.taxId ?? ''} · ${data.team.state ?? ''} · rates include ${data.team.gstRatePercent}% GST.`
          : 'Double-entry books kept automatically from Money, plus your own journals. No tax is charged in this team.'
      }
      actions={actions}>
      {data.pendingPosts > 0 && (
        <Banner
          status="warning"
          title={`${number(data.pendingPosts)} more transaction(s) are still being added to the books, so these figures are not yet complete.`}
          description="Reload to continue; running invoices posts them all first."
          endContent={<Button label="Reload" size="sm" variant="secondary" onClick={overview.reload} />}
        />
      )}
      <MetricRow>
        <Metric label="Income" value={money(data.pnl.incomePaise, {whole: true})} hint={`${date(data.from)} – ${date(data.to)}`} />
        <Metric label="Expenses" value={money(data.pnl.expensePaise, {whole: true})} />
        <Metric label={data.pnl.netPaise >= 0 ? 'Surplus' : 'Deficit'} value={money(data.pnl.netPaise, {whole: true})} tone={data.pnl.netPaise < 0 ? 'error' : undefined} />
        <Metric label={`Trial balance at ${date(data.to)}`} value={data.trial.isBalanced ? 'Balanced' : 'Out of balance'} tone={data.trial.isBalanced ? 'success' : 'error'} onClick={() => setParam({view: 'overview'})} />
      </MetricRow>
      <TabList value={view} onChange={value => setParams(value === 'overview' ? {} : {view: value}, {replace: false})}>
        <Tab value="overview" label="Trial balance" />
        <Tab value="journals" label="Journals" />
        <Tab value="ledger" label="General ledger" />
        <Tab value="reports" label="P&L and balance sheet" />
        <Tab value="invoices" label="Invoices" />
        <Tab value="tax" label={gst ? 'GST summary' : 'Tax summary'} />
        <Tab value="chart" label="Chart of accounts" />
      </TabList>
      {view === 'overview' && (
        <Card padding={4}>
          <VStack gap={3}>
            {dateFilters}
            <Heading level={2}>{`Trial balance at ${date(data.to)}`}</Heading>
            <SimpleTable
              rows={data.trial.rows as unknown as Row[]}
              idKey={row => Number(row.id)}
              empty="No entries yet"
              columns={[
                {key: 'code', header: 'Code', renderCell: r => <Text color="secondary">{String(r.code)}</Text>},
                {key: 'name', header: 'Account', renderCell: r => <Link onClick={() => setParams({view: 'ledger', account_id: String(r.id), to: data.to})}>{String(r.name)}</Link>},
                {key: 'type', header: 'Type', renderCell: r => <Text>{titleCase(String(r.type))}</Text>},
                {key: 'debitPaise', header: 'Debit', align: 'end', renderCell: r => <Amount paise={Number(r.debitPaise)} />},
                {key: 'creditPaise', header: 'Credit', align: 'end', renderCell: r => <Amount paise={Number(r.creditPaise)} />},
              ]}
            />
            <HStack gap={4} hAlign="end" wrap="wrap">
              <Toned strong>{`Total debits ${money(data.trial.debitPaise)}`}</Toned>
              <Toned strong>{`Total credits ${money(data.trial.creditPaise)}`}</Toned>
              <Status value={data.trial.isBalanced ? 'done' : 'failed'} label={data.trial.isBalanced ? 'Debits equal credits' : 'Debits and credits differ'} />
            </HStack>
          </VStack>
        </Card>
      )}
      {view === 'journals' && <JournalsTab key={nonce} unitId={unitId} canReverse={can['books-journal-reverse']} onChanged={refresh} />}
      {view === 'ledger' && <LedgerTab key={nonce} unitId={unitId} from={from} to={to} filters={dateFilters} />}
      {view === 'reports' && <ReportsTab key={nonce} unitId={unitId} from={from} to={to} filters={dateFilters} />}
      {view === 'invoices' && <InvoicesTab key={nonce} unitId={unitId} gst={gst} canCancel={can['books-invoice-cancel']} onChanged={refresh} />}
      {view === 'tax' && <TaxTab unitId={unitId} gst={gst} timezone={unit?.timezone ?? 'Asia/Kolkata'} />}
      {view === 'chart' && <ChartTab key={nonce} unitId={unitId} />}

      {unit && (
        <>
          <JournalDrawer unitId={unitId} timezone={unit.timezone} isOpen={creating === 'journal'} onOpenChange={open => !open && setCreating(null)} onSaved={refresh} />
          <FormDrawer
            isOpen={creating === 'invoice-run'}
            onOpenChange={open => !open && setCreating(null)}
            title={gst ? 'Issue tax invoices for a month' : 'Issue invoices for a month'}
            description={`One invoice per student for that month's class charges not yet invoiced, dated today. Running it again only picks up new charges.${gst ? ` Tax invoices use SAC ${data.team.sac} with CGST+SGST, or IGST when the student's billing state differs from ${data.team.state ?? 'the team state'}.` : ''}`}
            fields={[{kind: 'select', name: 'month', label: 'Month (class charges posted in)', required: true, options: monthOptions(unit.timezone)}]}
            initial={{month: today(unit.timezone).slice(0, 7)}}
            action={actionUrl(unitId, 'books-invoice-run')}
            submitLabel={gst ? 'Issue tax invoices' : 'Issue invoices'}
            successMessage="Invoice run finished. New invoices are listed under Invoices."
            onSaved={() => {
              setParams({view: 'invoices'});
              refresh();
            }}
          />
          <FormDrawer
            isOpen={creating === 'account'}
            onOpenChange={open => !open && setCreating(null)}
            title="Add an account"
            description="For manual journals such as rent, bank charges or GST paid. Operational money posts to the built-in accounts automatically."
            fields={[
              {kind: 'text', name: 'code', label: 'Code', required: true, placeholder: '6100', description: 'Letters, digits and hyphens; unique in this team.'},
              {kind: 'text', name: 'name', label: 'Name', required: true, placeholder: 'Rent'},
              {kind: 'select', name: 'type', label: 'Type', required: true, options: data.types.map(type => ({value: type, label: titleCase(type)}))},
            ]}
            initial={{type: 'expense'}}
            action={actionUrl(unitId, 'books-account')}
            submitLabel="Add account"
            successMessage="Account saved."
            onSaved={() => {
              setParams({view: 'chart'});
              refresh();
            }}
          />
        </>
      )}
    </Page>
  );
}

function JournalsTab({unitId, canReverse, onChanged}: {unitId: number; canReverse: boolean; onChanged: () => void}) {
  const [page, setPage] = useState(1);
  const [source, setSource] = useState('');
  const list = useResource<{data: Journal[]; meta: Meta}>(`/app-api/units/${unitId}/accounts/journals`, {page, source});
  const [reversing, setReversing] = useState<Journal | null>(null);
  if (list.error && !list.data) return <PageError error={list.error} onRetry={list.reload} />;
  return (
    <Card padding={4}>
      <VStack gap={3}>
        <HStack gap={2} vAlign="center" wrap="wrap">
          <StackItem size="fill">
            <Text color="secondary" size="sm">
              Every journal balances. Posted journals are never edited; a manual journal is corrected by reversing it. Operational entries are corrected in Payments &amp; dues.
            </Text>
          </StackItem>
          <Selector
            label="Source"
            isLabelHidden
            placeholder="All journals"
            hasClear
            value={source || null}
            onChange={value => {
              setSource(value ?? '');
              setPage(1);
            }}
            options={[
              {value: 'manual', label: 'Manual journals'},
              {value: 'ledger', label: 'From Money'},
            ]}
          />
        </HStack>
        {!list.data ? (
          <PageLoading />
        ) : (
          <SimpleTable
            rows={list.data.data as unknown as Row[]}
            idKey={row => Number(row.id)}
            empty="No journals yet"
            columns={[
              {key: 'entryDate', header: 'Date', renderCell: r => <Text hasTabularNumbers>{date(String(r.entryDate))}</Text>},
              {key: 'reference', header: 'Reference', renderCell: r => <Text>{String(r.reference)}</Text>},
              {
                key: 'memo',
                header: 'Description',
                renderCell: r => (
                  <VStack gap={0.5}>
                    <Text>{String(r.memo ?? '')}</Text>
                    <Text size="sm" color="secondary">
                      {`${r.source === 'manual' ? 'Manual journal' : 'From Money'}${r.isReversed ? ' · reversed' : ''}${r.reversesId ? ' · reversal' : ''}`}
                    </Text>
                  </VStack>
                ),
              },
              {
                key: 'lines',
                header: 'Lines',
                renderCell: r => (
                  <VStack gap={0.5}>
                    {(r.lines as Journal['lines']).map((line, i) => (
                      <Text key={i} size="sm" hasTabularNumbers>
                        {`${line.accountCode} ${line.accountName}: ${line.debitPaise ? `Dr ${money(line.debitPaise)}` : `Cr ${money(line.creditPaise)}`}`}
                      </Text>
                    ))}
                  </VStack>
                ),
              },
              {key: 'debitPaise', header: 'Debits = credits', align: 'end', renderCell: r => <Toned tone={r.debitPaise === r.creditPaise ? undefined : 'error'}>{money(Number(r.debitPaise))}</Toned>},
              {
                key: 'id',
                header: '',
                align: 'end',
                renderCell: r =>
                  r.source === 'manual' && !r.reversesId && !r.isReversed && canReverse ? (
                    <Button label="Reverse" size="sm" variant="secondary" onClick={() => setReversing(r as unknown as Journal)} />
                  ) : (
                    <Text> </Text>
                  ),
              },
            ]}
          />
        )}
        <Pager meta={list.data?.meta} onPage={setPage} label="Journal pages" />
      </VStack>
      {reversing && (
        <ConfirmActionDialog
          isOpen
          onOpenChange={open => !open && setReversing(null)}
          title={`Reverse journal ${reversing.reference}`}
          description={`${reversing.memo ?? ''} · ${money(reversing.debitPaise)}`}
          fields={[{kind: 'text', name: 'reason', label: 'Reason', required: true, description: 'Up to 500 characters; kept in the audit history.'}]}
          fixed={{entry_id: reversing.id}}
          url={actionUrl(unitId, 'books-journal-reverse')}
          confirmTitle="Reverse this journal?"
          confirmDescription={`A new journal ${reversing.reference}-REV dated today will post the exact opposite lines. The original stays on record.`}
          confirmLabel="Reverse journal"
          successMessage="Reversal posted."
          onDone={() => {
            list.reload();
            onChanged();
          }}
        />
      )}
    </Card>
  );
}

interface GeneralLedger {
  from: string;
  to: string;
  account: AccountRef | null;
  openingPaise: number;
  lines: {entryId: number; entryDate: string; reference: string; memo: string; studentName: string | null; teacherName: string | null; debitPaise: number; creditPaise: number; runningPaise: number}[];
}

function LedgerTab({unitId, from, to, filters}: {unitId: number; from: string; to: string; filters: ReactNode}) {
  const [params, setParams] = useSearchParams();
  const accountId = params.get('account_id') ?? '';
  const chart = useResource<{data: (AccountRef & {isActive: boolean})[]}>(`/app-api/units/${unitId}/accounts/chart`);
  const ledger = useResource<GeneralLedger>(`/app-api/units/${unitId}/accounts/ledger`, {account_id: accountId, from, to});
  if (ledger.error && !ledger.data) return <PageError error={ledger.error} onRetry={ledger.reload} />;
  const data = ledger.data;
  return (
    <Card padding={4}>
      <VStack gap={3}>
        <HStack gap={2} wrap="wrap" vAlign="end">
          <Selector
            label="Account"
            hasSearch
            value={accountId || (data?.account ? String(data.account.id) : undefined)}
            onChange={value =>
              setParams(current => {
                const next = new URLSearchParams(current);
                if (value) next.set('account_id', value);
                return next;
              })
            }
            options={(chart.data?.data ?? []).map(a => ({value: String(a.id), label: `${a.code} · ${a.name}`}))}
          />
          {filters}
        </HStack>
        {!data ? (
          <PageLoading />
        ) : !data.account ? (
          <EmptyState title="Choose an account" isCompact />
        ) : (
          <>
            <Heading level={2}>{`${data.account.code} · ${data.account.name}`}</Heading>
            <HStack gap={4} wrap="wrap">
              <Text color="secondary" hasTabularNumbers>{`Opening balance at ${date(data.from)}: ${money(data.openingPaise)}`}</Text>
              <Text color="secondary" hasTabularNumbers>{`Closing at ${date(data.to)}: ${money(data.lines.length ? data.lines[data.lines.length - 1].runningPaise : data.openingPaise)}`}</Text>
            </HStack>
            <SimpleTable
              rows={data.lines.map((line, i) => ({...line, key: i})) as unknown as Row[]}
              idKey={row => Number(row.key)}
              empty="No entries in this period"
              columns={[
                {key: 'entryDate', header: 'Date', renderCell: r => <Text hasTabularNumbers>{date(String(r.entryDate))}</Text>},
                {key: 'reference', header: 'Reference', renderCell: r => <Text color="secondary">{String(r.reference)}</Text>},
                {key: 'memo', header: 'Description', renderCell: r => <Text>{[r.memo, r.studentName, r.teacherName].filter(Boolean).join(' · ')}</Text>},
                {key: 'debitPaise', header: 'Debit', align: 'end', renderCell: r => <Amount paise={Number(r.debitPaise)} />},
                {key: 'creditPaise', header: 'Credit', align: 'end', renderCell: r => <Amount paise={Number(r.creditPaise)} />},
                {key: 'runningPaise', header: 'Balance', align: 'end', renderCell: r => <Toned>{money(Number(r.runningPaise))}</Toned>},
              ]}
            />
            <Text size="sm" color="secondary">
              Balance shown debit-positive.
            </Text>
          </>
        )}
      </VStack>
    </Card>
  );
}

interface Reports {
  from: string;
  to: string;
  pnl: {income: (AccountRef & {amountPaise: number})[]; expense: (AccountRef & {amountPaise: number})[]; incomePaise: number; expensePaise: number; netPaise: number};
  sheet: {assets: (AccountRef & {amountPaise: number})[]; liabilities: (AccountRef & {amountPaise: number})[]; equity: (AccountRef & {amountPaise: number})[]; earningsPaise: number; assetsPaise: number; liabilitiesEquityPaise: number};
}

function StatementTable({sections}: {sections: {title: string; rows: {label: string; paise: number; strong?: boolean}[]}[]}) {
  const rows: Row[] = [];
  sections.forEach((section, s) => {
    rows.push({key: `h${s}`, label: section.title, paise: null, heading: true});
    section.rows.forEach((row, i) => rows.push({key: `r${s}-${i}`, label: row.label, paise: row.paise, strong: row.strong}));
  });
  return (
    <Table<Row>
      data={rows}
      idKey={row => String(row.key)}
      density="balanced"
      columns={[
        {key: 'label', header: '', renderCell: r => <Text weight={r.heading || r.strong ? 'semibold' : undefined}>{String(r.label)}</Text>},
        {key: 'paise', header: 'Amount', align: 'end', renderCell: r => (r.paise === null ? <Text> </Text> : <Toned strong={Boolean(r.strong)}>{money(Number(r.paise))}</Toned>)},
      ]}
    />
  );
}

function ReportsTab({unitId, from, to, filters}: {unitId: number; from: string; to: string; filters: ReactNode}) {
  const reports = useResource<Reports>(`/app-api/units/${unitId}/accounts/reports`, {from, to});
  if (reports.error && !reports.data) return <PageError error={reports.error} onRetry={reports.reload} />;
  const data = reports.data;
  const line = (r: AccountRef & {amountPaise: number}) => ({label: `${r.code} ${r.name}`, paise: r.amountPaise});
  return (
    <VStack gap={4}>
      {filters}
      {!data ? (
        <PageLoading />
      ) : (
        <>
          <Card padding={4}>
            <VStack gap={3}>
              <Heading level={2}>{`Profit and loss, ${date(data.from)} to ${date(data.to)}`}</Heading>
              <StatementTable
                sections={[
                  {title: 'Income', rows: [...data.pnl.income.map(line), {label: 'Total income', paise: data.pnl.incomePaise, strong: true}]},
                  {title: 'Expenses', rows: [...data.pnl.expense.map(line), {label: 'Total expenses', paise: data.pnl.expensePaise, strong: true}]},
                  {title: data.pnl.netPaise >= 0 ? 'Surplus' : 'Deficit', rows: [{label: 'Income less expenses', paise: data.pnl.netPaise, strong: true}]},
                ]}
              />
              <Text size="sm" color="secondary">
                Tuition income is shown net of GST.
              </Text>
            </VStack>
          </Card>
          <Card padding={4}>
            <VStack gap={3}>
              <Heading level={2}>{`Balance sheet at ${date(data.to)}`}</Heading>
              <StatementTable
                sections={[
                  {title: 'Assets', rows: data.sheet.assets.map(line)},
                  {title: 'Liabilities', rows: data.sheet.liabilities.map(line)},
                  {title: 'Equity', rows: [...data.sheet.equity.map(line), {label: 'Accumulated surplus (income less expenses to date)', paise: data.sheet.earningsPaise}]},
                  {
                    title: 'Totals',
                    rows: [
                      {label: 'Total assets', paise: data.sheet.assetsPaise, strong: true},
                      {label: 'Total liabilities and equity', paise: data.sheet.liabilitiesEquityPaise, strong: true},
                    ],
                  },
                ]}
              />
              <Text size="sm" color="secondary">
                Student accounts show the net of amounts owed and advances paid; a negative figure means families have paid in advance.
              </Text>
            </VStack>
          </Card>
        </>
      )}
    </VStack>
  );
}

interface Invoice {
  id: number;
  number: string;
  documentType: string;
  issueDate: string;
  billingPeriod: string | null;
  studentName: string;
  studentCode: string;
  taxablePaise: number;
  taxPaise: number;
  totalPaise: number;
  status: string;
}

const DOC_LABELS: Record<string, string> = {tax_invoice: 'Tax invoice', invoice: 'Invoice', credit_note: 'Credit note'};

function InvoicesTab({unitId, gst, canCancel, onChanged}: {unitId: number; gst: boolean; canCancel: boolean; onChanged: () => void}) {
  const [page, setPage] = useState(1);
  const [q, setQ] = useState('');
  const [status, setStatus] = useState('');
  const list = useResource<{data: Invoice[]; meta: Meta; uninvoiced: {charges: number; totalPaise: number}}>(`/app-api/units/${unitId}/accounts/invoices`, {page, q, status});
  const [cancelling, setCancelling] = useState<Invoice | null>(null);
  if (list.error && !list.data) return <PageError error={list.error} onRetry={list.reload} />;
  return (
    <Card padding={4}>
      <VStack gap={3}>
        {list.data && (
          <Banner
            status="info"
            title={`${number(list.data.uninvoiced.charges)} posted class charge(s) not yet invoiced, totalling ${money(list.data.uninvoiced.totalPaise)}.`}
            description={gst ? 'Use “Issue tax invoices” to bill them.' : 'Use “Issue invoices” to bill them.'}
          />
        )}
        <HStack gap={2} wrap="wrap">
          <StackItem size="fill">
            <TextInput
              label="Search invoices"
              isLabelHidden
              value={q}
              onChange={value => {
                setQ(value);
                setPage(1);
              }}
              placeholder="Invoice number, student name or code"
              width="100%"
            />
          </StackItem>
          <Selector
            label="Status"
            isLabelHidden
            placeholder="Any status"
            hasClear
            value={status || null}
            onChange={value => {
              setStatus(value ?? '');
              setPage(1);
            }}
            options={[
              {value: 'issued', label: 'Issued'},
              {value: 'cancelled', label: 'Cancelled'},
            ]}
          />
        </HStack>
        {!list.data ? (
          <PageLoading />
        ) : (
          <SimpleTable
            rows={list.data.data as unknown as Row[]}
            idKey={row => Number(row.id)}
            empty="No invoices yet"
            columns={[
              {
                key: 'number',
                header: 'Number',
                renderCell: r => (
                  <VStack gap={0.5}>
                    <Link href={`/print/u/${unitId}/invoice/${r.id}`} target="_blank">
                      {String(r.number)}
                    </Link>
                    <Text size="sm" color="secondary">
                      {DOC_LABELS[String(r.documentType)] ?? String(r.documentType)}
                    </Text>
                  </VStack>
                ),
              },
              {key: 'issueDate', header: 'Date', renderCell: r => <Text hasTabularNumbers>{date(String(r.issueDate))}</Text>},
              {key: 'studentName', header: 'Student', renderCell: r => <Text>{`${r.studentName} · ${r.studentCode}`}</Text>},
              {key: 'billingPeriod', header: 'Period', renderCell: r => <Text>{String(r.billingPeriod ?? '—')}</Text>},
              {key: 'taxablePaise', header: 'Taxable', align: 'end', renderCell: r => <Toned>{money(Number(r.taxablePaise))}</Toned>},
              {key: 'taxPaise', header: 'Tax', align: 'end', renderCell: r => <Toned>{money(Number(r.taxPaise))}</Toned>},
              {key: 'totalPaise', header: 'Total', align: 'end', renderCell: r => <Toned strong>{money(Number(r.totalPaise))}</Toned>},
              {key: 'status', header: 'Status', renderCell: r => <Status value={String(r.status)} />},
              {
                key: 'id',
                header: '',
                align: 'end',
                renderCell: r =>
                  r.documentType !== 'credit_note' && r.status === 'issued' && canCancel ? (
                    <Button label="Cancel" size="sm" variant="secondary" onClick={() => setCancelling(r as unknown as Invoice)} />
                  ) : (
                    <Text> </Text>
                  ),
              },
            ]}
          />
        )}
        <Pager meta={list.data?.meta} onPage={setPage} label="Invoice pages" />
      </VStack>
      {cancelling && (
        <ConfirmActionDialog
          isOpen
          onOpenChange={open => !open && setCancelling(null)}
          title={`Cancel invoice ${cancelling.number}`}
          description={`${cancelling.studentName} · ${money(cancelling.totalPaise)}`}
          fields={[{kind: 'text', name: 'reason', label: 'Reason', required: true, description: 'Up to 500 characters; printed on the credit note record.'}]}
          fixed={{invoice_id: cancelling.id}}
          url={actionUrl(unitId, 'books-invoice-cancel')}
          confirmTitle="Issue a credit note?"
          confirmDescription={`Invoice ${cancelling.number} is cancelled by a credit note for ${money(cancelling.totalPaise)}. The invoice stays on record and its charges can be invoiced again.`}
          confirmLabel="Issue credit note"
          successMessage="Invoice cancelled with a credit note."
          onDone={() => {
            list.reload();
            onChanged();
          }}
        />
      )}
    </Card>
  );
}

interface TaxSummary {
  month: string;
  booked: {taxablePaise: number; cgstPaise: number; sgstPaise: number; igstPaise: number};
  issued: {taxablePaise: number; cgstPaise: number; sgstPaise: number; igstPaise: number};
  documents: number;
  creditNotes: number;
  byState: {state: string; documents: number; taxablePaise: number; taxPaise: number}[];
}

function TaxTab({unitId, gst, timezone}: {unitId: number; gst: boolean; timezone: string}) {
  const [month, setMonth] = useState(today(timezone).slice(0, 7));
  const tax = useResource<TaxSummary>(`/app-api/units/${unitId}/accounts/tax`, {month});
  if (tax.error && !tax.data) return <PageError error={tax.error} onRetry={tax.reload} />;
  const data = tax.data;
  return (
    <Card padding={4}>
      <VStack gap={3}>
        <Selector label="Month" value={month} onChange={value => value && setMonth(value)} options={monthOptions(timezone)} />
        {!data ? (
          <PageLoading />
        ) : (
          <>
            <Heading level={2}>{`${gst ? 'GST' : 'Tax'} summary for ${data.month}`}</Heading>
            <SimpleTable
              rows={[
                {key: 'booked', label: 'Booked from class charges', ...data.booked},
                {key: 'issued', label: `On invoices issued (${data.documents}, incl. ${data.creditNotes} credit notes)`, ...data.issued},
              ]}
              idKey={row => String(row.key)}
              empty="Nothing this month"
              columns={[
                {key: 'label', header: '', renderCell: r => <Text>{String(r.label)}</Text>},
                {key: 'taxablePaise', header: 'Taxable value', align: 'end', renderCell: r => <Toned>{money(Number(r.taxablePaise))}</Toned>},
                {key: 'cgstPaise', header: 'CGST', align: 'end', renderCell: r => <Toned>{money(Number(r.cgstPaise))}</Toned>},
                {key: 'sgstPaise', header: 'SGST', align: 'end', renderCell: r => <Toned>{money(Number(r.sgstPaise))}</Toned>},
                {key: 'igstPaise', header: 'IGST', align: 'end', renderCell: r => <Toned>{money(Number(r.igstPaise))}</Toned>},
              ]}
            />
            <Text size="sm" color="secondary">
              Booked tax follows the date charges were posted; invoice tax follows the issue date, so they agree once each month&apos;s charges are invoiced in the same month. Use the invoice figures for returns (B2C supplies) and confirm with your accountant.
            </Text>
            <Heading level={3}>Invoices by place of supply</Heading>
            <SimpleTable
              rows={data.byState as unknown as Row[]}
              idKey={row => String(row.state)}
              empty="No invoices issued this month"
              columns={[
                {key: 'state', header: 'State', renderCell: r => <Text>{String(r.state)}</Text>},
                {key: 'documents', header: 'Documents', align: 'end', renderCell: r => <Text hasTabularNumbers>{number(Number(r.documents))}</Text>},
                {key: 'taxablePaise', header: 'Taxable value', align: 'end', renderCell: r => <Toned>{money(Number(r.taxablePaise))}</Toned>},
                {key: 'taxPaise', header: 'Tax', align: 'end', renderCell: r => <Toned>{money(Number(r.taxPaise))}</Toned>},
              ]}
            />
          </>
        )}
      </VStack>
    </Card>
  );
}

function ChartTab({unitId}: {unitId: number}) {
  const chart = useResource<{data: (AccountRef & {isSystem: boolean; isActive: boolean})[]}>(`/app-api/units/${unitId}/accounts/chart`);
  if (chart.error && !chart.data) return <PageError error={chart.error} onRetry={chart.reload} />;
  return (
    <Card padding={4}>
      {!chart.data ? (
        <PageLoading />
      ) : (
        <SimpleTable
          rows={chart.data.data as unknown as Row[]}
          idKey={row => Number(row.id)}
          empty="No accounts yet"
          columns={[
            {key: 'code', header: 'Code', renderCell: r => <Text hasTabularNumbers>{String(r.code)}</Text>},
            {key: 'name', header: 'Name', renderCell: r => <Text>{String(r.name)}</Text>},
            {key: 'type', header: 'Type', renderCell: r => <Text>{titleCase(String(r.type))}</Text>},
            {key: 'isSystem', header: 'Used for', renderCell: r => <Text color="secondary">{r.isSystem ? 'Posted automatically' : 'Manual journals'}</Text>},
            {key: 'isActive', header: 'Status', renderCell: r => <Status value={r.isActive ? 'active' : 'inactive'} />},
          ]}
        />
      )}
    </Card>
  );
}

/** Rupee string to paise for the running "debits = credits" hint only; the server re-validates and converts. */
function hintPaise(value: string): number {
  const match = /^(0|[1-9][0-9]*)(?:\.([0-9]{1,2}))?$/.exec(value.trim());
  if (!match) return 0;
  return Number(match[1]) * 100 + Number((match[2] ?? '').padEnd(2, '0'));
}

interface JournalLine {
  account_id: string;
  debit_inr: string;
  credit_inr: string;
}

const EMPTY_LINES: JournalLine[] = Array.from({length: 4}, () => ({account_id: '', debit_inr: '', credit_inr: ''}));

/** Multi-line manual journal. Posts lines[i][account_id|debit_inr|credit_inr]. */
function JournalDrawer({unitId, timezone, isOpen, onOpenChange, onSaved}: {unitId: number; timezone: string; isOpen: boolean; onOpenChange: (open: boolean) => void; onSaved: () => void}) {
  const showToast = useToast();
  const chart = useResource<{data: (AccountRef & {isActive: boolean})[]}>(isOpen ? `/app-api/units/${unitId}/accounts/chart` : null);
  const [entryDate, setEntryDate] = useState(today(timezone));
  const [memo, setMemo] = useState('');
  const [lines, setLines] = useState<JournalLine[]>(EMPTY_LINES);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [formError, setFormError] = useState<string>();
  const [saving, setSaving] = useState(false);

  const reset = () => {
    setEntryDate(today(timezone));
    setMemo('');
    setLines(EMPTY_LINES);
    setErrors({});
    setFormError(undefined);
  };
  const setLine = (index: number, patch: Partial<JournalLine>) => setLines(current => current.map((line, i) => (i === index ? {...line, ...patch} : line)));
  const debits = lines.reduce((sum, line) => sum + hintPaise(line.debit_inr), 0);
  const credits = lines.reduce((sum, line) => sum + hintPaise(line.credit_inr), 0);
  const options = (chart.data?.data ?? []).filter(a => a.isActive).map(a => ({value: String(a.id), label: `${a.code} · ${a.name}`}));
  const statusFor = (name: string) => (errors[name]?.length ? {type: 'error' as const, message: errors[name][0]} : undefined);

  async function submit() {
    setSaving(true);
    setErrors({});
    setFormError(undefined);
    const payloadLines = lines
      .filter(line => line.account_id || line.debit_inr.trim() || line.credit_inr.trim())
      .map(line => ({account_id: line.account_id || null, ...(line.debit_inr.trim() ? {debit_inr: line.debit_inr.trim()} : {}), ...(line.credit_inr.trim() ? {credit_inr: line.credit_inr.trim()} : {})}));
    try {
      await postJson(actionUrl(unitId, 'books-journal'), {entry_date: entryDate, memo: memo.trim(), lines: payloadLines});
      showToast({body: 'Journal posted.', uniqueID: 'journal-posted'});
      reset();
      onOpenChange(false);
      onSaved();
    } catch (reason) {
      if (reason instanceof ApiError) {
        setErrors(reason.fieldErrors);
        setFormError(reason.fieldErrors.lines?.[0] ?? (Object.keys(reason.fieldErrors).length ? 'Fix the highlighted fields. Nothing was posted.' : reason.message));
      } else {
        setFormError('Could not reach the server. Nothing was posted.');
      }
    } finally {
      setSaving(false);
    }
  }

  return (
    <Drawer isOpen={isOpen} onOpenChange={onOpenChange} label="Manual journal" width={640} purpose="form" padding={5} experimental={{isFullWidthOnMobile: true}}>
      <VStack gap={5}>
        <DrawerHeader title="Record a manual journal" subtitle="Rent, bank charges, GST paid, capital… Receipts, refunds, charges and teacher pay are recorded in Payments & dues and posted here automatically." onOpenChange={onOpenChange} />
        {formError && <Banner status="error" title={formError} />}
        <DateInput label="Date" isRequired value={entryDate as ISODate} max={today(timezone) as ISODate} onChange={value => setEntryDate(value ?? '')} status={statusFor('entry_date')} />
        <TextInput label="Description" isRequired value={memo} onChange={setMemo} placeholder="For example: October rent paid by bank transfer" status={statusFor('memo')} />
        <VStack gap={3}>
          {lines.map((line, i) => (
            <HStack key={i} gap={2} wrap="wrap" vAlign="end">
              <StackItem size="fill">
                <Selector label={`Account, line ${i + 1}`} hasSearch hasClear value={line.account_id || null} onChange={value => setLine(i, {account_id: value ?? ''})} options={options} placeholder="Choose an account" />
              </StackItem>
              <TextInput label="Debit" value={line.debit_inr} onChange={value => setLine(i, {debit_inr: value})} inputMode="decimal" placeholder="0.00" width={120} />
              <TextInput label="Credit" value={line.credit_inr} onChange={value => setLine(i, {credit_inr: value})} inputMode="decimal" placeholder="0.00" width={120} />
            </HStack>
          ))}
          {lines.length < 10 && <Button label="Add a line" variant="ghost" size="sm" onClick={() => setLines(current => [...current, {account_id: '', debit_inr: '', credit_inr: ''}])} />}
        </VStack>
        <HStack gap={4} wrap="wrap" vAlign="center">
          <Toned>{`Debits ${money(debits)}`}</Toned>
          <Toned>{`Credits ${money(credits)}`}</Toned>
          <Status value={debits === credits && debits > 0 ? 'done' : 'pending'} label={debits === credits && debits > 0 ? 'Balanced' : 'Debits must equal credits'} />
        </HStack>
        <HStack gap={2} hAlign="end">
          <Button label="Cancel" variant="ghost" onClick={() => onOpenChange(false)} />
          <Button label="Post journal" variant="primary" isLoading={saving} onClick={submit} />
        </HStack>
      </VStack>
    </Drawer>
  );
}
