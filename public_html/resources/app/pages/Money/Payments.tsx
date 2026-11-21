// Payments & dues: the classic Money page rebuilt. Four tabs share one URL so
// links and the back button reopen the same view. Reads come from
// /app-api/units/{unit}/money/*; every write goes to the classic action routes.
import {useEffect, useMemo, useState} from 'react';
import {useParams, useSearchParams} from 'react-router';
import {VStack, HStack} from '@astryxdesign/core/Layout';
import {Card} from '@astryxdesign/core/Card';
import {Text} from '@astryxdesign/core/Text';
import {Link} from '@astryxdesign/core/Link';
import {Button} from '@astryxdesign/core/Button';
import {Icon} from '@astryxdesign/core/Icon';
import {Selector} from '@astryxdesign/core/Selector';
import {DateInput} from '@astryxdesign/core/DateInput';
import {TabList, Tab} from '@astryxdesign/core/TabList';
import {MoreMenu} from '@astryxdesign/core/MoreMenu';
import {Banner} from '@astryxdesign/core/Banner';
import type {DropdownMenuOption} from '@astryxdesign/core/DropdownMenu';
import {PlusIcon, XMarkIcon} from '@heroicons/react/24/outline';
import {Page, PageError} from '../../components/PageState';
import {DataTable, type DataColumn, type ListMeta} from '../../components/DataTable';
import {FormDrawer, type Field} from '../../components/FormDrawer';
import {Status} from '../../components/Status';
import {Metric, MetricRow} from '../../components/Metric';
import {Balance, Toned} from '../../components/Amount';
import {useDebounced, useListQuery, useResource} from '../../lib/hooks';
import {getJson} from '../../lib/api';
import {date, money, number, titleCase} from '../../lib/format';
import {unitById, type UnitAccess} from '../../lib/session';
import {actionUrl, canAct, ConfirmActionDialog, paiseToInput, StudentPicker, today, type StudentOption} from './shared';

type TabKey = 'dues' | 'ledger' | 'requests' | 'teachers';

interface Summary {
  totals: {dueCount: number; duePaise: number; openRequestCount: number; outstandingRequestPaise: number; teachersOwedCount: number; teacherPayablePaise: number};
  can: {openProfile: boolean; openTuition: boolean};
}

interface DueRow extends Record<string, unknown> {
  id: number;
  code: string;
  name: string;
  grade: string;
  status: string;
  registeredOn: string;
  priority: string;
  balancePaise: number;
}

interface LedgerRow extends Record<string, unknown> {
  id: number;
  occurredAt: string;
  kind: string;
  reference: string;
  amountPaise: number;
  currency: string;
  studentId: number | null;
  studentName: string | null;
  studentCode: string | null;
  teacherId: number | null;
  teacherName: string | null;
  reversesId: number | null;
  reversedById: number | null;
  runningPaise: number | null;
}

interface RequestRow extends Record<string, unknown> {
  id: number;
  reference: string;
  studentId: number;
  studentName: string;
  studentCode: string;
  requestedOn: string;
  dueOn: string | null;
  requestedPaise: number;
  coveredPaise: number;
  outstandingPaise: number;
  status: string;
}

interface TeacherRow extends Record<string, unknown> {
  id: number;
  code: string;
  name: string;
  status: string;
  earnedPaise: number;
  paidPaise: number;
  otherAdjustmentsPaise: number;
  netPayablePaise: number;
}

interface Paged<T> {
  data: T[];
  meta: ListMeta;
}

const KIND_LABELS: Record<string, string> = {
  payment: 'Payment received',
  refund: 'Refund paid',
  lesson_charge: 'Class charge',
  teacher_accrual: 'Teacher fees earned',
  teacher_payment: 'Teacher fees paid',
  opening_balance: 'Opening balance',
  reversal: 'Reversal',
};

const NOTICE =
  'Bookkeeping only: these actions record money already received or paid; they do not collect, refund or transfer money. Enter amounts in rupees, such as 100.50, with up to two decimals and no commas.';

/** What a form drawer is currently doing. Ids that identify the record are single-option selects preset in `initial`. */
type Drawer =
  | {kind: 'payment' | 'request' | 'refund'; student: StudentOption; amount?: number}
  | {kind: 'salary'; teacher: TeacherRow}
  | {kind: 'allocate'; request: RequestRow; payments: {value: string; label: string}[]};

type Confirm =
  | {kind: 'receipt-reverse' | 'receipt-correct' | 'refund-reverse' | 'salary-reverse'; row: LedgerRow}
  | {kind: 'request-cancel'; row: RequestRow};

function useTab(): [TabKey, (tab: TabKey, extra?: Record<string, string>) => void] {
  const [params, setParams] = useSearchParams();
  const raw = params.get('tab');
  const tab: TabKey = raw === 'ledger' || raw === 'requests' || raw === 'teachers' ? raw : 'dues';
  const set = (next: TabKey, extra: Record<string, string> = {}) =>
    setParams(
      current => {
        const out = new URLSearchParams();
        if (next !== 'dues') out.set('tab', next);
        if (current.get('new')) for (const key of ['new', 'student']) if (current.get(key)) out.set(key, current.get(key) as string);
        for (const [key, value] of Object.entries(extra)) out.set(key, value);
        return out;
      },
      {replace: false},
    );
  return [tab, set];
}

function useSearchBox(value: string, apply: (q: string) => void) {
  const [text, setText] = useState(value);
  const debounced = useDebounced(text);
  useEffect(() => {
    if (debounced !== value) apply(debounced);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debounced]);
  return [text, setText] as const;
}

export default function Payments() {
  const unitId = Number(useParams().unit);
  const unit = unitById(unitId);
  const [params, setParams] = useSearchParams();
  const [tab, setTab] = useTab();
  const summary = useResource<Summary>(`/app-api/units/${unitId}/money/summary`);
  const [drawer, setDrawer] = useState<Drawer | null>(null);
  const [picking, setPicking] = useState<'payment' | 'request' | 'refund' | null>(null);
  const [confirm, setConfirm] = useState<Confirm | null>(null);
  const [nonce, setNonce] = useState(0);
  const [deepLinkError, setDeepLinkError] = useState<string>();

  const refresh = () => {
    summary.reload();
    setNonce(n => n + 1);
  };

  // Deep links from the student profile and the global New menu:
  // ?new=payment&student=<id> and ?new=request&student=<id>.
  const deepNew = params.get('new');
  const deepStudent = params.get('student');
  useEffect(() => {
    if (deepNew !== 'payment' && deepNew !== 'request') return;
    const kind = deepNew;
    if (!canAct(unit, kind === 'payment' ? 'payment' : 'request-create')) return;
    if (!deepStudent) {
      setPicking(kind);
      return;
    }
    const controller = new AbortController();
    getJson<{data: StudentOption[]}>(`/app-api/units/${unitId}/money/students`, {id: deepStudent}, controller.signal)
      .then(result => {
        const student = result.data[0];
        if (student) setDrawer({kind, student, amount: kind === 'payment' && student.balancePaise < 0 ? -student.balancePaise : undefined});
        else setDeepLinkError('That student is not in this team.');
      })
      .catch((reason: Error) => {
        if (reason.name !== 'AbortError') setDeepLinkError(reason.message);
      });
    return () => controller.abort();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [deepNew, deepStudent, unitId]);

  const clearDeepLink = () =>
    setParams(
      current => {
        const next = new URLSearchParams(current);
        next.delete('new');
        if (!next.get('tab') || next.get('tab') === 'dues' || next.get('tab') === 'requests') next.delete('student');
        return next;
      },
      {replace: true},
    );

  const closeDrawer = (open: boolean) => {
    if (open) return;
    setDrawer(null);
    if (deepNew) clearDeepLink();
  };

  const totals = summary.data?.totals;
  const canPayment = canAct(unit, 'payment');
  const canRequest = canAct(unit, 'request-create');
  const canRefund = canAct(unit, 'refund-paid');

  const headerMenu: DropdownMenuOption[] = [];
  if (canRefund) headerMenu.push({label: 'Record refund paid', onClick: () => setPicking('refund')});
  if (summary.data?.can.openTuition) headerMenu.push({label: 'Class follow-up & parent statements', href: `/app/u/${unitId}/tuition`});
  if (unit?.exports.includes('ledger')) headerMenu.push({label: 'Download ledger CSV', href: `/erp/units/${unitId}/exports/ledger`});
  if (unit?.exports.includes('payment_requests')) headerMenu.push({label: 'Download payment requests CSV', href: `/erp/units/${unitId}/exports/payment_requests`});

  if (summary.error && !summary.data) {
    return (
      <Page title="Payments & dues">
        <PageError error={summary.error} onRetry={summary.reload} />
      </Page>
    );
  }

  return (
    <Page
      title="Payments & dues"
      description="Student balances, receipts, payment requests and teacher pay."
      actions={
        <>
          {canRequest && <Button label="Request payment" variant="secondary" onClick={() => setPicking('request')} />}
          {canPayment && <Button label="Record payment" variant="primary" icon={<Icon icon={PlusIcon} size="sm" />} onClick={() => setPicking('payment')} />}
          {headerMenu.length > 0 && <MoreMenu items={headerMenu} label="More money actions" variant="secondary" alignment="end" />}
        </>
      }>
      {deepLinkError && <Banner status="error" title={deepLinkError} isDismissable onDismiss={() => setDeepLinkError(undefined)} />}
      {totals && (
        <MetricRow>
          <Metric label="Families with dues" value={number(totals.dueCount)} onClick={() => setTab('dues')} isActive={tab === 'dues'} />
          <Metric label="Total outstanding" value={money(-totals.duePaise, {whole: true})} tone={totals.duePaise < 0 ? 'error' : undefined} onClick={() => setTab('dues')} />
          <Metric label="Open payment requests" value={number(totals.openRequestCount)} hint={`${money(totals.outstandingRequestPaise, {whole: true})} not yet covered`} onClick={() => setTab('requests', {status: 'pending'})} isActive={tab === 'requests'} />
          <Metric label="Teacher pay owed" value={money(totals.teacherPayablePaise, {whole: true})} hint={`${number(totals.teachersOwedCount)} teachers`} onClick={() => setTab('teachers')} isActive={tab === 'teachers'} />
        </MetricRow>
      )}
      <Text color="secondary" size="sm">
        {NOTICE}
      </Text>
      <TabList value={tab} onChange={value => setTab(value as TabKey)}>
        <Tab value="dues" label="Dues" />
        <Tab value="ledger" label="Ledger" />
        <Tab value="requests" label="Payment requests" />
        <Tab value="teachers" label="Teacher pay" />
      </TabList>
      {tab === 'dues' && (
        <DuesTab
          key={`dues-${nonce}`}
          unitId={unitId}
          unit={unit}
          canOpenProfile={Boolean(summary.data?.can.openProfile)}
          onPay={row => setDrawer({kind: 'payment', student: {id: row.id, code: row.code, name: row.name, status: row.status, balancePaise: row.balancePaise}, amount: -row.balancePaise})}
          onRequest={row => setDrawer({kind: 'request', student: {id: row.id, code: row.code, name: row.name, status: row.status, balancePaise: row.balancePaise}, amount: -row.balancePaise})}
          onLedger={row => setTab('ledger', {student: String(row.id)})}
        />
      )}
      {tab === 'ledger' && <LedgerTab key={`ledger-${nonce}`} unitId={unitId} unit={unit} onConfirm={setConfirm} />}
      {tab === 'requests' && (
        <RequestsTab
          key={`requests-${nonce}`}
          unitId={unitId}
          unit={unit}
          onConfirm={setConfirm}
          onAllocate={async row => {
            const result = await getJson<Paged<LedgerRow>>(`/app-api/units/${unitId}/money/ledger`, {kind: 'payment', student: row.studentId, per_page: 100});
            const payments = result.data
              .filter(p => p.reversedById === null)
              .map(p => ({value: String(p.id), label: `${p.reference} · ${money(p.amountPaise)} · ${date(p.occurredAt.slice(0, 10))}`}));
            setDrawer({kind: 'allocate', request: row, payments});
          }}
        />
      )}
      {tab === 'teachers' && (
        <TeachersTab
          key={`teachers-${nonce}`}
          unitId={unitId}
          unit={unit}
          onPay={row => setDrawer({kind: 'salary', teacher: row})}
          onHistory={row => setTab('ledger', {teacher: String(row.id)})}
        />
      )}

      <StudentPicker
        unitId={unitId}
        isOpen={picking !== null}
        onOpenChange={open => {
          if (!open) {
            setPicking(null);
            if (deepNew) clearDeepLink();
          }
        }}
        title={picking === 'request' ? 'Request payment from…' : picking === 'refund' ? 'Refund paid to…' : 'Payment received from…'}
        onPick={student => {
          const kind = picking ?? 'payment';
          setPicking(null);
          setDrawer({kind, student, amount: kind !== 'refund' && student.balancePaise < 0 ? -student.balancePaise : kind === 'refund' && student.balancePaise > 0 ? student.balancePaise : undefined});
        }}
      />
      {unit && <MoneyDrawer unitId={unitId} unit={unit} drawer={drawer} onOpenChange={closeDrawer} onSaved={refresh} />}
      {confirm && <MoneyConfirm unitId={unitId} confirm={confirm} onClose={() => setConfirm(null)} onDone={refresh} />}
    </Page>
  );
}

function MoneyDrawer({unitId, unit, drawer, onOpenChange, onSaved}: {unitId: number; unit: UnitAccess; drawer: Drawer | null; onOpenChange: (open: boolean) => void; onSaved: () => void}) {
  const day = today(unit.timezone);
  const alreadyPaid: Field = {kind: 'select', name: 'already_paid', label: 'Already paid', required: true, options: [{value: '1', label: 'Yes, already paid externally'}]};
  let config: {title: string; description: string; fields: Field[]; initial: Record<string, string>; action: string; submit: string; success: string} | null = null;
  if (drawer?.kind === 'payment' || drawer?.kind === 'request' || drawer?.kind === 'refund') {
    const s = drawer.student;
    const studentField: Field = {kind: 'select', name: 'student_id', label: 'Student', required: true, options: [{value: String(s.id), label: `${s.name} · ${s.code}`}]};
    const amount = drawer.amount && drawer.amount > 0 ? paiseToInput(drawer.amount) : '';
    const balance = s.balancePaise < 0 ? `Owes ${money(-s.balancePaise)}.` : s.balancePaise > 0 ? `In credit by ${money(s.balancePaise)}.` : 'Nothing due.';
    if (drawer.kind === 'payment') {
      config = {
        title: 'Record payment received',
        description: `${balance} Enter a payment already received; this does not collect money.`,
        fields: [studentField, {kind: 'money', name: 'amount_inr', label: 'Amount received', required: true}, {kind: 'text', name: 'reference', label: 'Receipt reference', required: true, description: 'UPI, bank or cash reference. Must be unique.'}],
        initial: {student_id: String(s.id), amount_inr: amount},
        action: actionUrl(unitId, 'payment'),
        submit: 'Record payment',
        success: 'Payment recorded. Open the ledger to print the receipt.',
      };
    } else if (drawer.kind === 'request') {
      config = {
        title: 'Create payment request',
        description: `${balance} A request does not change balances and is not a receipt or tax invoice.`,
        fields: [
          studentField,
          {kind: 'money', name: 'amount_inr', label: 'Amount requested', required: true},
          {kind: 'text', name: 'reference', label: 'Request reference', required: true},
          {kind: 'date', name: 'requested_on', label: 'Requested on', required: true},
          {kind: 'date', name: 'due_on', label: 'Due on'},
          {kind: 'text', name: 'notes', label: 'Notes'},
        ],
        initial: {student_id: String(s.id), amount_inr: amount, requested_on: day},
        action: actionUrl(unitId, 'request-create'),
        submit: 'Create request',
        success: 'Payment request created.',
      };
    } else {
      config = {
        title: 'Record refund already paid',
        description: `${balance} Records money already returned from the student's credit. It cannot exceed that credit. No money is sent.`,
        fields: [
          studentField,
          {kind: 'money', name: 'amount_inr', label: 'Amount refunded', required: true},
          {kind: 'text', name: 'reference', label: 'Refund reference', required: true},
          {kind: 'date', name: 'paid_on', label: 'Paid on', required: true, max: day},
          {kind: 'textarea', name: 'reason', label: 'Reason', required: true},
          alreadyPaid,
        ],
        initial: {student_id: String(s.id), amount_inr: amount, paid_on: day},
        action: actionUrl(unitId, 'refund-paid'),
        submit: 'Record refund',
        success: 'Refund recorded.',
      };
    }
  } else if (drawer?.kind === 'salary') {
    const t = drawer.teacher;
    config = {
      title: 'Record teacher payment',
      description: `Net payable ${money(t.netPayablePaise)}. Confirm this amount was already paid externally. Advances and overpayments need separate review. No money is sent.`,
      fields: [
        {kind: 'select', name: 'teacher_id', label: 'Teacher', required: true, options: [{value: String(t.id), label: `${t.name} · ${t.code}`}]},
        {kind: 'money', name: 'amount_inr', label: 'Amount paid', required: true},
        {kind: 'text', name: 'reference', label: 'Payment reference', required: true},
        {kind: 'date', name: 'paid_on', label: 'Paid on', required: true, max: day},
        {kind: 'textarea', name: 'reason', label: 'Reason', required: true, description: 'For example: October settlement by bank transfer.'},
        alreadyPaid,
      ],
      initial: {teacher_id: String(t.id), amount_inr: t.netPayablePaise > 0 ? paiseToInput(t.netPayablePaise) : '', paid_on: day},
      action: actionUrl(unitId, 'salary-paid'),
      submit: 'Record teacher payment',
      success: 'Teacher payment recorded.',
    };
  } else if (drawer?.kind === 'allocate') {
    const r = drawer.request;
    config = {
      title: 'Link payment to request',
      description: `${r.reference} for ${r.studentName}: ${money(r.outstandingPaise)} not yet covered. Allocate only money actually received. Reversed receipts stop covering requests automatically.`,
      fields: [
        {kind: 'select', name: 'request_id', label: 'Payment request', required: true, options: [{value: String(r.id), label: `${r.reference} · ${r.studentName}`}]},
        {kind: 'select', name: 'payment_id', label: 'Receipt', required: true, options: drawer.payments, description: drawer.payments.length ? undefined : 'No active receipts for this student yet. Record the payment first.'},
        {kind: 'money', name: 'amount_inr', label: 'Amount to allocate', required: true},
      ],
      initial: {request_id: String(r.id), payment_id: drawer.payments.length === 1 ? drawer.payments[0].value : '', amount_inr: r.outstandingPaise > 0 ? paiseToInput(r.outstandingPaise) : ''},
      action: actionUrl(unitId, 'request-allocate'),
      submit: 'Link payment',
      success: 'Payment linked to the request.',
    };
  }
  const key = drawer ? `${drawer.kind}-${'student' in drawer ? drawer.student.id : 'teacher' in drawer ? drawer.teacher.id : drawer.request.id}` : 'closed';
  return (
    <FormDrawer
      key={key}
      isOpen={config !== null}
      onOpenChange={onOpenChange}
      title={config?.title ?? ''}
      description={config?.description}
      fields={config?.fields ?? []}
      initial={config?.initial}
      action={config?.action ?? ''}
      submitLabel={config?.submit ?? 'Save'}
      successMessage={config?.success ?? 'Saved.'}
      onSaved={onSaved}
    />
  );
}

function MoneyConfirm({unitId, confirm, onClose, onDone}: {unitId: number; confirm: Confirm; onClose: () => void; onDone: () => void}) {
  const reason = {kind: 'textarea' as const, name: 'reason', label: 'Reason', required: true, description: 'Kept in the audit history.'};
  const common = {isOpen: true, onOpenChange: (open: boolean) => !open && onClose(), onDone, url: actionUrl(unitId, confirm.kind)};
  if (confirm.kind === 'request-cancel') {
    const r = confirm.row;
    return (
      <ConfirmActionDialog
        {...common}
        title={`Cancel request ${r.reference}`}
        description={`Cancels the ${money(r.outstandingPaise)} not yet covered. Linked payments stay recorded.`}
        fields={[reason]}
        fixed={{request_id: r.id}}
        confirmTitle="Cancel this payment request?"
        confirmDescription={`${r.reference} for ${r.studentName} will no longer ask for ${money(r.outstandingPaise)}. This cannot be undone; create a new request if needed.`}
        confirmLabel="Cancel request"
        successMessage="Payment request cancelled."
      />
    );
  }
  const row = confirm.row;
  const who = row.studentName ?? row.teacherName ?? '';
  const what = `${row.reference} · ${money(row.amountPaise)}${who ? ` · ${who}` : ''}`;
  if (confirm.kind === 'receipt-correct') {
    return (
      <ConfirmActionDialog
        {...common}
        title="Correct receipt amount"
        description={`${what}. The original receipt stays on record; it is reversed and a replacement is recorded in one step.`}
        fields={[
          {kind: 'money', name: 'amount_inr', label: 'Correct amount', required: true},
          {kind: 'text', name: 'new_reference', label: 'New receipt reference', required: true, description: 'Must differ from the original reference.'},
          reason,
        ]}
        fixed={{entry_id: row.id}}
        initial={{amount_inr: paiseToInput(row.amountPaise)}}
        confirmTitle="Replace this receipt?"
        confirmDescription={`Receipt ${row.reference} will be reversed with an equal and opposite entry, and a replacement receipt recorded. No money is refunded or collected.`}
        confirmLabel="Correct receipt"
        successMessage="Receipt corrected: original reversed and replacement recorded."
        isDestructive={false}
      />
    );
  }
  const labels = {
    'receipt-reverse': ['Reverse receipt', 'Reverse this receipt?', 'Records an equal and opposite entry. The student owes this amount again. No bank refund or transfer is made.', 'Receipt reversed.'],
    'refund-reverse': ['Reverse refund', 'Reverse this refund record?', 'Records an equal and opposite entry. It corrects bookkeeping only and does not recover or transfer funds.', 'Refund record reversed.'],
    'salary-reverse': ['Reverse teacher payment', 'Reverse this teacher payment record?', 'Records an equal and opposite entry, so the amount shows as owed to the teacher again. No funds are recovered or transferred.', 'Teacher payment reversed.'],
  }[confirm.kind];
  return (
    <ConfirmActionDialog
      {...common}
      title={labels[0]}
      description={what}
      fields={[reason]}
      fixed={{entry_id: row.id}}
      confirmTitle={labels[1]}
      confirmDescription={`${what}. ${labels[2]}`}
      confirmLabel={labels[0]}
      successMessage={labels[3]}
    />
  );
}

const DUE_DEFAULTS = {tab: '', q: '', sort: 'balance', dir: 'asc', page: '1', per_page: '25'};

function DuesTab({
  unitId,
  unit,
  canOpenProfile,
  onPay,
  onRequest,
  onLedger,
}: {
  unitId: number;
  unit: UnitAccess | undefined;
  canOpenProfile: boolean;
  onPay: (row: DueRow) => void;
  onRequest: (row: DueRow) => void;
  onLedger: (row: DueRow) => void;
}) {
  const [query, setQuery] = useListQuery(DUE_DEFAULTS);
  const [search, setSearch] = useSearchBox(query.q, q => setQuery({q}));
  const {tab: _tab, ...request} = query;
  const list = useResource<Paged<DueRow>>(`/app-api/units/${unitId}/money/dues`, request);
  const canPay = canAct(unit, 'payment');
  const canRequest = canAct(unit, 'request-create');
  const columns = useMemo<DataColumn<DueRow>[]>(
    () => [
      {
        key: 'name',
        header: 'Student',
        sortKey: 'name',
        renderCell: row => (canOpenProfile ? <Link href={`/app/u/${unitId}/students/${row.id}`}>{row.name}</Link> : <Text>{row.name}</Text>),
      },
      {key: 'code', header: 'Code', sortKey: 'code', renderCell: row => <Text color="secondary">{row.code}</Text>},
      {key: 'registeredOn', header: 'Joined', sortKey: 'registered_on', renderCell: row => <Text hasTabularNumbers>{date(row.registeredOn)}</Text>},
      {key: 'priority', header: 'Priority', renderCell: row => <Text>{titleCase(row.priority)}</Text>},
      {key: 'status', header: 'Status', renderCell: row => <Status value={row.status} />},
      {key: 'balancePaise', header: 'Owes', sortKey: 'balance', align: 'end', renderCell: row => <Toned tone="error" strong>{money(-row.balancePaise)}</Toned>},
      {
        key: 'id',
        header: '',
        align: 'end',
        renderCell: row => (
          <HStack gap={1} hAlign="end">
            {canPay && <Button label="Record payment" size="sm" variant="secondary" onClick={() => onPay(row)} />}
            <MoreMenu
              size="sm"
              label={`More for ${row.name}`}
              alignment="end"
              items={[...(canRequest ? [{label: 'Request payment', onClick: () => onRequest(row)}] : []), {label: 'Show ledger', onClick: () => onLedger(row)}]}
            />
          </HStack>
        ),
      },
    ],
    [canOpenProfile, canPay, canRequest, unitId, onPay, onRequest, onLedger],
  );
  if (list.error && !list.data) return <PageError error={list.error} onRetry={list.reload} />;
  return (
    <DataTable<DueRow>
      label="Collection queue"
      rows={list.data?.data}
      meta={list.data?.meta}
      columns={columns}
      idKey="id"
      isLoading={list.isLoading}
      isRefreshing={list.isRefreshing}
      search={search}
      onSearch={setSearch}
      searchPlaceholder="Search by student name or code"
      onSort={(sort, dir) => setQuery({sort, dir})}
      onPage={page => setQuery({page: String(page)}, {resetPage: false})}
      onPageSize={size => setQuery({per_page: String(size)})}
      hasActiveFilters={Boolean(query.q)}
      onClearFilters={() => {
        setSearch('');
        setQuery({q: ''});
      }}
      emptyTitle="Nobody owes anything"
      emptyDescription="Students with a negative balance appear here, largest dues first."
      phoneCard={{
        title: row => row.name,
        subtitle: row => `${row.code} · owes ${money(-row.balancePaise, {whole: true})}`,
        trailing: row => (canPay ? <Button label="Pay" size="sm" variant="secondary" onClick={() => onPay(row)} /> : <Balance paise={row.balancePaise} whole />),
      }}
    />
  );
}

interface LedgerList extends Paged<LedgerRow> {
  totals: {entries: number; creditsPaise: number; debitsPaise: number; netPaise: number};
  kinds: string[];
}

const LEDGER_DEFAULTS = {tab: '', q: '', kind: '', from: '', to: '', student: '', teacher: '', sort: 'occurred_at', dir: 'desc', page: '1', per_page: '25'};

type ISODate = `${number}${number}${number}${number}-${number}${number}-${number}${number}`;

function LedgerTab({unitId, unit, onConfirm}: {unitId: number; unit: UnitAccess | undefined; onConfirm: (c: Confirm) => void}) {
  const [query, setQuery] = useListQuery(LEDGER_DEFAULTS);
  const [search, setSearch] = useSearchBox(query.q, q => setQuery({q}));
  const {tab: _tab, ...request} = query;
  const list = useResource<LedgerList>(`/app-api/units/${unitId}/money/ledger`, request);
  // Filter chips name the person from the filtered rows, so names never go into the URL.
  const firstRow = list.data?.data[0];
  const studentLabel = query.student && firstRow?.studentId === Number(query.student) ? firstRow.studentName : null;
  const teacherLabel = query.teacher && firstRow?.teacherId === Number(query.teacher) ? firstRow.teacherName : null;
  const can = {
    reverse: canAct(unit, 'receipt-reverse'),
    correct: canAct(unit, 'receipt-correct'),
    refundReverse: canAct(unit, 'refund-reverse'),
    salaryReverse: canAct(unit, 'salary-reverse'),
  };

  const rowActions = (row: LedgerRow): DropdownMenuOption[] => {
    const items: DropdownMenuOption[] = [];
    if (row.kind === 'payment' || row.kind === 'refund') items.push({label: row.kind === 'refund' ? 'Open refund note' : 'Open receipt', href: `/erp/units/${unitId}/receipts/${row.id}`, target: '_blank', rel: 'noopener'});
    const active = row.reversedById === null;
    if (active && row.kind === 'payment' && can.correct) items.push({label: 'Correct amount…', onClick: () => onConfirm({kind: 'receipt-correct', row})});
    if (active && row.kind === 'payment' && can.reverse) items.push({label: 'Reverse receipt…', variant: 'destructive', onClick: () => onConfirm({kind: 'receipt-reverse', row})});
    if (active && row.kind === 'refund' && can.refundReverse) items.push({label: 'Reverse refund…', variant: 'destructive', onClick: () => onConfirm({kind: 'refund-reverse', row})});
    if (active && row.kind === 'teacher_payment' && can.salaryReverse) items.push({label: 'Reverse teacher payment…', variant: 'destructive', onClick: () => onConfirm({kind: 'salary-reverse', row})});
    return items;
  };

  const columns: DataColumn<LedgerRow>[] = [
    {key: 'occurredAt', header: 'Date', sortKey: 'occurred_at', renderCell: row => <Text hasTabularNumbers>{date(row.occurredAt.slice(0, 10))}</Text>},
    {
      key: 'kind',
      header: 'Type',
      renderCell: row => (
        <Text>
          {KIND_LABELS[row.kind] ?? titleCase(row.kind)}
          {row.reversedById !== null ? ' · reversed' : ''}
        </Text>
      ),
    },
    {key: 'reference', header: 'Reference', renderCell: row => <Text color="secondary">{row.reference}</Text>},
    {
      key: 'studentName',
      header: 'Student or teacher',
      renderCell: row =>
        row.studentId !== null ? (
          <Link onClick={() => setQuery({student: String(row.studentId), teacher: ''})}>{row.studentName ?? `#${row.studentId}`}</Link>
        ) : row.teacherId !== null ? (
          <Link onClick={() => setQuery({teacher: String(row.teacherId), student: ''})}>{row.teacherName ?? `#${row.teacherId}`}</Link>
        ) : (
          <Text color="secondary">—</Text>
        ),
    },
    {key: 'amountPaise', header: 'Amount', sortKey: 'amount', align: 'end', renderCell: row => <Toned tone={row.amountPaise < 0 ? 'error' : undefined}>{money(row.amountPaise)}</Toned>},
    {key: 'runningPaise', header: 'Running net', align: 'end', hideOnPhone: true, renderCell: row => <Text hasTabularNumbers color="secondary">{row.runningPaise === null ? '—' : money(row.runningPaise)}</Text>},
    {
      key: 'id',
      header: '',
      align: 'end',
      renderCell: row => {
        const items = rowActions(row);
        return items.length ? <MoreMenu size="sm" label={`Actions for ${row.reference}`} alignment="end" items={items} /> : <Text> </Text>;
      },
    },
  ];

  const hasFilters = Boolean(query.q || query.kind || query.from || query.to || query.student || query.teacher);
  const totals = list.data?.totals;
  if (list.error && !list.data) return <PageError error={list.error} onRetry={list.reload} />;
  return (
    <DataTable<LedgerRow>
      label="Ledger"
      rows={list.data?.data}
      meta={list.data?.meta}
      columns={columns}
      idKey="id"
      isLoading={list.isLoading}
      isRefreshing={list.isRefreshing}
      search={search}
      onSearch={setSearch}
      searchPlaceholder="Search reference, student or teacher"
      filters={
        <HStack gap={2} wrap="wrap" vAlign="center">
          <Selector
            label="Type"
            isLabelHidden
            placeholder="Any type"
            hasClear
            value={query.kind || null}
            onChange={value => setQuery({kind: value ?? ''})}
            options={(list.data?.kinds ?? Object.keys(KIND_LABELS)).map(kind => ({value: kind, label: KIND_LABELS[kind] ?? titleCase(kind)}))}
          />
          <DateInput label="From" isLabelHidden placeholder="From" value={(query.from || undefined) as ISODate | undefined} onChange={value => setQuery({from: value ?? ''})} />
          <DateInput label="To" isLabelHidden placeholder="To" value={(query.to || undefined) as ISODate | undefined} onChange={value => setQuery({to: value ?? ''})} />
          {query.student && (
            <Button label={`Student: ${studentLabel || `#${query.student}`}`} size="sm" variant="secondary" icon={<Icon icon={XMarkIcon} size="sm" />} onClick={() => setQuery({student: ''})} />
          )}
          {query.teacher && (
            <Button label={`Teacher: ${teacherLabel || `#${query.teacher}`}`} size="sm" variant="secondary" icon={<Icon icon={XMarkIcon} size="sm" />} onClick={() => setQuery({teacher: ''})} />
          )}
        </HStack>
      }
      summary={
        totals && (
          <HStack gap={4} wrap="wrap">
            <Text color="secondary" hasTabularNumbers>{`${number(totals.entries)} entries`}</Text>
            <Text color="secondary" hasTabularNumbers>{`Credits ${money(totals.creditsPaise)}`}</Text>
            <Toned tone={totals.debitsPaise < 0 ? 'error' : undefined}>{`Debits ${money(totals.debitsPaise)}`}</Toned>
            <Toned strong tone={totals.netPaise < 0 ? 'error' : undefined}>{`Net ${money(totals.netPaise)}`}</Toned>
          </HStack>
        )
      }
      onSort={(sort, dir) => setQuery({sort, dir})}
      onPage={page => setQuery({page: String(page)}, {resetPage: false})}
      onPageSize={size => setQuery({per_page: String(size)})}
      hasActiveFilters={hasFilters}
      onClearFilters={() => {
        setSearch('');
        setQuery({q: '', kind: '', from: '', to: '', student: '', teacher: ''});
      }}
      emptyTitle="No postings yet"
      emptyDescription="Charges, payments, refunds and teacher pay appear here as they are recorded. Postings are never edited; corrections are reversals."
      phoneCard={{
        title: row => `${KIND_LABELS[row.kind] ?? titleCase(row.kind)} · ${row.reference}`,
        subtitle: row => `${date(row.occurredAt.slice(0, 10))}${row.studentName ? ` · ${row.studentName}` : row.teacherName ? ` · ${row.teacherName}` : ''}${row.reversedById !== null ? ' · reversed' : ''}`,
        trailing: row => <Toned tone={row.amountPaise < 0 ? 'error' : undefined}>{money(row.amountPaise)}</Toned>,
        href: row => (row.kind === 'payment' || row.kind === 'refund' ? `/erp/units/${unitId}/receipts/${row.id}` : undefined),
      }}
    />
  );
}

const REQUEST_DEFAULTS = {tab: '', q: '', status: '', student: '', sort: 'requested_on', dir: 'desc', page: '1', per_page: '25'};

function RequestsTab({unitId, unit, onConfirm, onAllocate}: {unitId: number; unit: UnitAccess | undefined; onConfirm: (c: Confirm) => void; onAllocate: (row: RequestRow) => void}) {
  const [query, setQuery] = useListQuery(REQUEST_DEFAULTS);
  const [search, setSearch] = useSearchBox(query.q, q => setQuery({q}));
  const {tab: _tab, ...request} = query;
  const list = useResource<Paged<RequestRow>>(`/app-api/units/${unitId}/money/requests`, request);
  const canAllocate = canAct(unit, 'request-allocate');
  const canCancel = canAct(unit, 'request-cancel');
  const columns: DataColumn<RequestRow>[] = [
    {key: 'reference', header: 'Reference', sortKey: 'reference', renderCell: row => <Link href={`/erp/units/${unitId}/payment-requests/${row.id}`} target="_blank">{row.reference}</Link>},
    {key: 'studentName', header: 'Student', sortKey: 'student', renderCell: row => <Text>{`${row.studentName} · ${row.studentCode}`}</Text>},
    {key: 'requestedOn', header: 'Requested', sortKey: 'requested_on', renderCell: row => <Text hasTabularNumbers>{date(row.requestedOn)}</Text>},
    {key: 'dueOn', header: 'Due', sortKey: 'due_on', renderCell: row => <Text hasTabularNumbers>{date(row.dueOn)}</Text>},
    {key: 'requestedPaise', header: 'Requested', sortKey: 'amount', align: 'end', renderCell: row => <Toned>{money(row.requestedPaise)}</Toned>},
    {key: 'coveredPaise', header: 'Covered', align: 'end', renderCell: row => <Toned>{money(row.coveredPaise)}</Toned>},
    {key: 'outstandingPaise', header: 'Outstanding', align: 'end', renderCell: row => <Toned tone={row.outstandingPaise > 0 && row.status === 'pending' ? 'warning' : undefined}>{money(row.outstandingPaise)}</Toned>},
    {key: 'status', header: 'Status', renderCell: row => <Status value={row.status} />},
    {
      key: 'id',
      header: '',
      align: 'end',
      renderCell: row => {
        const items: DropdownMenuOption[] = [{label: 'Print request', href: `/erp/units/${unitId}/payment-requests/${row.id}`, target: '_blank', rel: 'noopener'}];
        if (row.status === 'pending' && canAllocate) items.push({label: 'Link a received payment…', onClick: () => onAllocate(row)});
        if (row.status === 'pending' && canCancel) items.push({label: 'Cancel remaining…', variant: 'destructive', onClick: () => onConfirm({kind: 'request-cancel', row})});
        return <MoreMenu size="sm" label={`Actions for ${row.reference}`} alignment="end" items={items} />;
      },
    },
  ];
  if (list.error && !list.data) return <PageError error={list.error} onRetry={list.reload} />;
  return (
    <DataTable<RequestRow>
      label="Payment requests"
      rows={list.data?.data}
      meta={list.data?.meta}
      columns={columns}
      idKey="id"
      isLoading={list.isLoading}
      isRefreshing={list.isRefreshing}
      search={search}
      onSearch={setSearch}
      searchPlaceholder="Search reference or student"
      filters={
        <Selector
          label="Status"
          isLabelHidden
          placeholder="Any status"
          hasClear
          value={query.status || null}
          onChange={value => setQuery({status: value ?? ''})}
          options={[
            {value: 'pending', label: 'Open (pending or fulfilled)'},
            {value: 'cancelled', label: 'Cancelled'},
          ]}
        />
      }
      onSort={(sort, dir) => setQuery({sort, dir})}
      onPage={page => setQuery({page: String(page)}, {resetPage: false})}
      onPageSize={size => setQuery({per_page: String(size)})}
      exportHref={unit?.exports.includes('payment_requests') ? `/erp/units/${unitId}/exports/payment_requests` : undefined}
      hasActiveFilters={Boolean(query.q || query.status || query.student)}
      onClearFilters={() => {
        setSearch('');
        setQuery({q: '', status: '', student: ''});
      }}
      emptyTitle="No payment requests"
      emptyDescription="Requests ask a family to pay. They do not change balances and are not receipts or tax invoices."
      phoneCard={{
        title: row => `${row.reference} · ${row.studentName}`,
        subtitle: row => `${titleCase(row.status)} · due ${date(row.dueOn)}`,
        trailing: row => <Toned>{money(row.outstandingPaise, {whole: true})}</Toned>,
        href: row => `/erp/units/${unitId}/payment-requests/${row.id}`,
      }}
    />
  );
}

const TEACHER_DEFAULTS = {tab: '', q: '', sort: 'name', dir: 'asc', page: '1', per_page: '25'};

function TeachersTab({unitId, unit, onPay, onHistory}: {unitId: number; unit: UnitAccess | undefined; onPay: (row: TeacherRow) => void; onHistory: (row: TeacherRow) => void}) {
  const [query, setQuery] = useListQuery(TEACHER_DEFAULTS);
  const [search, setSearch] = useSearchBox(query.q, q => setQuery({q}));
  const {tab: _tab, ...request} = query;
  const list = useResource<Paged<TeacherRow>>(`/app-api/units/${unitId}/money/teachers`, request);
  const canPay = canAct(unit, 'salary-paid');
  const columns: DataColumn<TeacherRow>[] = [
    {key: 'name', header: 'Teacher', sortKey: 'name', renderCell: row => <Text>{row.name}</Text>},
    {key: 'code', header: 'Code', sortKey: 'code', renderCell: row => <Text color="secondary">{row.code}</Text>},
    {key: 'status', header: 'Status', renderCell: row => <Status value={row.status} />},
    {key: 'earnedPaise', header: 'Earned', align: 'end', renderCell: row => <Toned>{money(row.earnedPaise)}</Toned>},
    {key: 'paidPaise', header: 'Paid', align: 'end', renderCell: row => <Toned>{money(row.paidPaise)}</Toned>},
    {key: 'otherAdjustmentsPaise', header: 'Other adjustments', align: 'end', hideOnPhone: true, renderCell: row => <Toned>{money(row.otherAdjustmentsPaise)}</Toned>},
    {key: 'netPayablePaise', header: 'Owed', align: 'end', renderCell: row => <Toned strong={row.netPayablePaise > 0} tone={row.netPayablePaise < 0 ? 'error' : undefined}>{money(row.netPayablePaise)}</Toned>},
    {
      key: 'id',
      header: '',
      align: 'end',
      renderCell: row => (
        <HStack gap={1} hAlign="end">
          {canPay && row.netPayablePaise > 0 && <Button label="Record pay" size="sm" variant="secondary" onClick={() => onPay(row)} />}
          <Button label="History" size="sm" variant="ghost" onClick={() => onHistory(row)} />
        </HStack>
      ),
    },
  ];
  if (list.error && !list.data) return <PageError error={list.error} onRetry={list.reload} />;
  return (
    <VStack gap={3}>
      <Card padding={3}>
        <Text color="secondary" size="sm">
          Owed = earned − recorded payments + other adjustments. Earnings accrue when attendance is approved.
        </Text>
      </Card>
      <DataTable<TeacherRow>
        label="Teacher pay"
        rows={list.data?.data}
        meta={list.data?.meta}
        columns={columns}
        idKey="id"
        isLoading={list.isLoading}
        isRefreshing={list.isRefreshing}
        search={search}
        onSearch={setSearch}
        searchPlaceholder="Search teacher name or code"
        onSort={(sort, dir) => setQuery({sort, dir})}
        onPage={page => setQuery({page: String(page)}, {resetPage: false})}
        onPageSize={size => setQuery({per_page: String(size)})}
        hasActiveFilters={Boolean(query.q)}
        onClearFilters={() => {
          setSearch('');
          setQuery({q: ''});
        }}
        emptyTitle="No teachers in this team"
        phoneCard={{
          title: row => row.name,
          subtitle: row => `Earned ${money(row.earnedPaise, {whole: true})} · paid ${money(row.paidPaise, {whole: true})}`,
          trailing: row => (canPay && row.netPayablePaise > 0 ? <Button label={`Pay ${money(row.netPayablePaise, {whole: true})}`} size="sm" variant="secondary" onClick={() => onPay(row)} /> : <Toned>{money(row.netPayablePaise, {whole: true})}</Toned>),
        }}
      />
    </VStack>
  );
}
