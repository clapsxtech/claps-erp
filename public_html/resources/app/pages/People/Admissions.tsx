import {useEffect, useMemo, useState} from 'react';
import {useParams, useSearchParams} from 'react-router';
import {HStack, VStack} from '@astryxdesign/core/Layout';
import {Text} from '@astryxdesign/core/Text';
import {Link} from '@astryxdesign/core/Link';
import {Button} from '@astryxdesign/core/Button';
import {Icon} from '@astryxdesign/core/Icon';
import {Selector} from '@astryxdesign/core/Selector';
import {ToggleButton} from '@astryxdesign/core/ToggleButton';
import {SegmentedControl, SegmentedControlItem} from '@astryxdesign/core/SegmentedControl';
import {MoreMenu} from '@astryxdesign/core/MoreMenu';
import {DropdownMenu} from '@astryxdesign/core/DropdownMenu';
import {pixel} from '@astryxdesign/core/Table';
import {Token} from '@astryxdesign/core/Token';
import {useToast} from '@astryxdesign/core/Toast';
import {PlusIcon} from '@heroicons/react/24/outline';
import {Page, PageError} from '../../components/PageState';
import {DataTable, type DataColumn, type ListMeta} from '../../components/DataTable';
import {Status} from '../../components/Status';
import {Metric, MetricRow} from '../../components/Metric';
import {Toned} from '../../components/Amount';
import {useDebounced, useIsPhone, useListQuery, useResource} from '../../lib/hooks';
import {ApiError, postJson} from '../../lib/api';
import {date, dateTime, number, titleCase} from '../../lib/format';
import {unitById} from '../../lib/session';
import {LeadForms, type DemoTarget, type LeadFormKind, type LeadFormTarget} from './LeadForms';
import {businessAction, canAct, withParam} from './access';
import {ViewTabs} from '../../components/ViewTabs';

export interface LeadRow extends Record<string, unknown> {
  id: number;
  name: string;
  grade: string | null;
  subject: string | null;
  syllabus: string | null;
  source: string | null;
  status: string;
  stage: string;
  stageLabel: string;
  isHot: boolean;
  enquiryDate: string;
  nextFollowUpOn: string | null;
  followUpState: 'closed' | 'unscheduled' | 'overdue' | 'today' | 'upcoming';
  interestStatus: string | null;
  assignedName: string | null;
  phone: string;
  dial: string;
  whatsapp: string | null;
}

export interface AdmissionsCan {
  createLead: boolean;
  updateLead: boolean;
  followup: boolean;
  reminder: boolean;
  completeReminder: boolean;
  convert: boolean;
  scheduleDemo: boolean;
  updateDemo: boolean;
  transfer: boolean;
  resolveTransfer: boolean;
  manage: boolean;
  openStudent: boolean;
  matching: boolean;
}

interface LeadList {
  data: LeadRow[];
  meta: ListMeta;
  stages: {value: string; label: string; count: number}[];
  totals: {all: number; active: number; dueToday: number; overdue: number; unscheduled: number; joinedThisMonth: number; pendingTransfers: number; remindersDue: number};
  can: AdmissionsCan;
  today: string;
}

interface DemoRow extends Record<string, unknown> {
  id: number;
  leadId: number;
  leadName: string;
  scheduledOn: string;
  scheduledTime: string;
  status: string;
  meetLink: string | null;
  isMatched: boolean;
  teacherName: string | null;
  subjectName: string | null;
  /** Contact links built on the server (SalesPipeline). */
  parentDial: string | null;
  parentWhatsapp: string;
  hasTeacher: boolean;
  teacherDial: string | null;
  teacherWhatsapp: string | null;
  isUpcoming: boolean;
}

/** Call and WhatsApp the parent and the teacher; WhatsApp opens with the demo details filled in. */
function demoContactItems(row: DemoRow) {
  const details = row.isUpcoming ? ' · send demo details' : '';
  return [
    ...(row.parentDial ? [{label: 'Call parent', href: `tel:${row.parentDial}`}] : []),
    {label: `WhatsApp parent${details}`, href: row.parentWhatsapp, target: '_blank' as const, rel: 'noopener noreferrer'},
    ...(row.hasTeacher && row.teacherDial ? [{type: 'divider' as const}, {label: 'Call teacher', href: `tel:${row.teacherDial}`}] : []),
    ...(row.hasTeacher && row.teacherWhatsapp ? [{label: `WhatsApp teacher${details}`, href: row.teacherWhatsapp, target: '_blank' as const, rel: 'noopener noreferrer'}] : []),
    ...(row.hasTeacher && !row.teacherDial ? [{type: 'divider' as const}, {label: "No phone in the teacher's recruitment record", isDisabled: true}] : []),
  ];
}

function DemoContacts({row}: {row: DemoRow}) {
  return <DropdownMenu button={{label: 'Contact', size: 'sm', variant: 'secondary', 'aria-label': `Contact about ${row.leadName}'s demo`}} alignment="end" menuWidth={260} items={demoContactItems(row)} />;
}

interface ReminderRow extends Record<string, unknown> {
  id: number;
  leadId: number;
  leadName: string;
  dueOn: string;
  dueTime: string | null;
  text: string;
  isOverdue: boolean;
}

export interface TransferRow extends Record<string, unknown> {
  id: number;
  leadId: number;
  leadName: string;
  fromName: string | null;
  toName: string | null;
  createdAt: string;
  canAccept: boolean;
  canDecline: boolean;
  canCancel: boolean;
}

interface SubList<T> {
  data: T[];
  meta: ListMeta;
  can: AdmissionsCan;
  today: string;
}

const DEFAULTS = {tab: 'pipeline', q: '', stage: '', status: '', followup: '', hot: '', scope: '', sort: '', dir: '', page: '1', per_page: '25'};
type Query = typeof DEFAULTS;
type SetQuery = (patch: Partial<Query>, opts?: {resetPage?: boolean}) => void;

const FOLLOWUP_LABEL: Record<LeadRow['followUpState'], string> = {closed: 'Closed', unscheduled: 'Not set', overdue: 'Overdue', today: 'Today', upcoming: 'Upcoming'};

export function FollowUpCell({row}: {row: Pick<LeadRow, 'followUpState' | 'nextFollowUpOn' | 'status'>}) {
  if (row.followUpState === 'closed') return <Text color="secondary">{row.status === 'JOINED' ? 'Registered' : 'Closed'}</Text>;
  if (!row.nextFollowUpOn) return <Text color="secondary">Not set</Text>;
  const tone = row.followUpState === 'overdue' ? 'error' : row.followUpState === 'today' ? 'warning' : undefined;
  return <Toned tone={tone} strong={tone !== undefined}>{row.followUpState === 'today' ? 'Today' : date(row.nextFollowUpOn)}</Toned>;
}

/** Shared accept / decline / cancel handler for lead transfers. */
export function useResolveTransfer(unitId: number, onDone: () => void) {
  const showToast = useToast();
  return async (transferId: number, status: 'accepted' | 'rejected' | 'cancelled') => {
    try {
      await postJson(businessAction(unitId, 'admission-resolve-transfer'), {transfer_id: transferId, status});
      showToast({body: status === 'accepted' ? 'Transfer accepted. The lead is now yours.' : status === 'rejected' ? 'Transfer declined.' : 'Transfer cancelled.', uniqueID: `transfer-${transferId}`});
      onDone();
    } catch (reason) {
      showToast({body: reason instanceof ApiError ? reason.message : 'Could not connect. Check your internet and try again; nothing was changed.', uniqueID: `transfer-error-${transferId}`});
    }
  };
}

export default function Admissions() {
  const unitId = Number(useParams().unit);
  const unit = unitById(unitId);
  const [query, setQuery] = useListQuery(DEFAULTS);
  const [params, setParams] = useSearchParams();
  const [searchText, setSearchText] = useState(query.q);
  const debounced = useDebounced(searchText);
  useEffect(() => {
    if (debounced !== query.q) setQuery({q: debounced});
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debounced]);
  const [form, setForm] = useState<{kind: LeadFormKind; lead?: LeadFormTarget; demo?: DemoTarget} | null>(null);
  const [nonce, setNonce] = useState(0);
  // Links from other screens: ?view=today opens today's follow-up queue, ?view=demos|reminders|transfers a tab.
  useEffect(() => {
    const view = params.get('view');
    if (!view) return;
    // One URL update: the legacy `view` key is dropped as the matching filter is set.
    const clearView = {view: ''} as Partial<Query>;
    if (view === 'today') setQuery({...clearView, tab: 'pipeline', followup: 'today', stage: '', status: ''});
    else setQuery({...clearView, tab: ['demos', 'reminders', 'transfers'].includes(view) ? view : 'pipeline'});
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [params.get('view')]);

  // Counts and permissions always come from the pipeline read, whichever tab is open.
  const pipelineQuery = query.tab === 'pipeline' ? query : {tab: 'pipeline', per_page: '25'};
  const list = useResource<LeadList>(`/app-api/units/${unitId}/admissions`, {...pipelineQuery, tab: undefined, scope: undefined, _: nonce || undefined});
  const reload = () => {
    list.reload();
    setNonce(n => n + 1);
  };
  const can = list.data?.can;
  const canCreate = canAct(unit, 'admission-create-lead');
  const creating = params.get('new') === 'lead';
  const setCreating = (open: boolean) => setParams(current => withParam(current, {new: open ? 'lead' : null}));
  const switchTab = (tab: string) => {
    setSearchText('');
    setQuery({tab, q: '', stage: '', status: '', followup: '', hot: '', scope: '', sort: '', dir: ''});
  };

  if (list.error && !list.data) {
    return (
      <Page title="Admissions">
        <PageError error={list.error} onRetry={list.reload} />
      </Page>
    );
  }
  const totals = list.data?.totals;
  const shared = {unitId, query, setQuery: setQuery as SetQuery, searchText, setSearchText, can, nonce, reload, openForm: setForm};

  return (
    <Page
      title="Admissions"
      description="Enquiries, demos and follow-ups for this team."
      actions={
        <>
          <Button label="Teacher directory" variant="secondary" href={`/app/u/${unitId}/admissions/teachers`} />
          {canCreate && <Button label="New lead" variant="primary" icon={<Icon icon={PlusIcon} size="sm" />} onClick={() => setCreating(true)} />}
        </>
      }>
      {totals && (
        <MetricRow>
          <Metric label="Active leads" value={number(totals.active)} onClick={() => setQuery({tab: 'pipeline', status: 'LEAD', followup: '', stage: ''})} isActive={query.tab === 'pipeline' && query.status === 'LEAD'} />
          <Metric label="Follow up today" value={number(totals.dueToday)} tone={totals.dueToday > 0 ? 'warning' : undefined} onClick={() => setQuery({tab: 'pipeline', followup: 'today', status: '', stage: ''})} isActive={query.followup === 'today'} />
          <Metric label="Overdue" value={number(totals.overdue)} tone={totals.overdue > 0 ? 'error' : undefined} onClick={() => setQuery({tab: 'pipeline', followup: 'overdue', status: '', stage: ''})} isActive={query.followup === 'overdue'} />
          <Metric label="Joined this month" value={number(totals.joinedThisMonth)} tone="success" onClick={() => setQuery({tab: 'pipeline', stage: 'joined', followup: '', status: ''})} />
        </MetricRow>
      )}
      <HStack gap={2} vAlign="center">
        <ViewTabs
          value={query.tab}
          onChange={switchTab}
          tabs={[
            {value: 'pipeline', label: 'Pipeline'},
            {value: 'demos', label: 'Demos'},
            {value: 'reminders', label: 'Reminders', count: totals?.remindersDue},
            {value: 'transfers', label: 'Transfers', count: totals?.pendingTransfers},
          ]}
        />
      </HStack>
      {query.tab === 'pipeline' && <Pipeline {...shared} list={list} />}
      {query.tab === 'demos' && <Demos {...shared} />}
      {query.tab === 'reminders' && <Reminders {...shared} />}
      {query.tab === 'transfers' && <Transfers {...shared} />}
      {unit && (
        <LeadForms
          unitId={unitId}
          timezone={unit.timezone}
          kind={creating ? 'create' : form?.kind ?? null}
          lead={form?.lead}
          demo={form?.demo}
          onClose={() => (creating ? setCreating(false) : setForm(null))}
          onSaved={reload}
        />
      )}
    </Page>
  );
}

interface TabProps {
  unitId: number;
  query: Query;
  setQuery: SetQuery;
  searchText: string;
  setSearchText: (value: string) => void;
  can: AdmissionsCan | undefined;
  nonce: number;
  reload: () => void;
  openForm: (form: {kind: LeadFormKind; lead?: LeadFormTarget; demo?: DemoTarget}) => void;
}

function tableHandlers(setQuery: SetQuery) {
  return {
    onSort: (sort: string, dir: 'asc' | 'desc') => setQuery({sort, dir}, {resetPage: true}),
    onPage: (page: number) => setQuery({page: String(page)}, {resetPage: false}),
    onPageSize: (size: number) => setQuery({per_page: String(size)}),
  };
}

function Pipeline({unitId, query, setQuery, searchText, setSearchText, can, openForm, list}: TabProps & {list: ReturnType<typeof useResource<LeadList>>}) {
  const isPhone = useIsPhone();
  const columns = useMemo<DataColumn<LeadRow>[]>(() => {
    const cols: DataColumn<LeadRow>[] = [
      {
        key: 'name',
        header: 'Student',
        sortKey: 'name',
        renderCell: row => (
          <VStack gap={0.5}>
            <HStack gap={1.5} vAlign="center">
              <Link href={`/app/u/${unitId}/admissions/${row.id}`}>{row.name}</Link>
              {row.isHot && <Token label="Hot" size="sm" color="orange" />}
            </HStack>
            <Text size="sm" color="secondary">{[row.grade, row.subject].filter(Boolean).join(' · ') || 'Grade not set'}</Text>
          </VStack>
        ),
      },
      {key: 'stage', header: 'Stage', renderCell: row => <Status value={row.stage} label={row.stageLabel} />},
      {key: 'nextFollowUpOn', header: 'Next follow-up', sortKey: 'next_follow_up', renderCell: row => <FollowUpCell row={row} />},
      {key: 'interestStatus', header: 'Interest', renderCell: row => <Text color="secondary">{row.interestStatus ?? '—'}</Text>},
      {key: 'assignedName', header: 'Owner', renderCell: row => <Text>{row.assignedName ?? 'Unassigned'}</Text>},
      {key: 'enquiryDate', header: 'Enquired', sortKey: 'enquiry_date', renderCell: row => <Text hasTabularNumbers>{date(row.enquiryDate)}</Text>},
      {
        key: 'id',
        header: '',
        align: 'end',
        renderCell: row => {
          const target = {id: row.id, name: row.name, nextFollowUpOn: row.nextFollowUpOn};
          const open = row.status === 'LEAD';
          return (
            <HStack gap={1} hAlign="end">
              {can?.followup && <Button label="Follow-up" size="sm" variant="secondary" onClick={() => openForm({kind: 'followup', lead: target})} />}
              <MoreMenu
                label={`More for ${row.name}`}
                size="sm"
                alignment="end"
                items={[
                  {label: 'Call', href: `tel:${row.dial}`},
                  ...(row.whatsapp ? [{label: 'WhatsApp', href: row.whatsapp, target: '_blank' as const, rel: 'noopener noreferrer'}] : []),
                  ...(open && can?.scheduleDemo ? [{label: 'Schedule demo', onClick: () => openForm({kind: 'demo', lead: target})}] : []),
                  ...(can?.reminder ? [{label: 'Add reminder', onClick: () => openForm({kind: 'reminder', lead: target})}] : []),
                  {label: 'Open lead', href: `/app/u/${unitId}/admissions/${row.id}`},
                ]}
              />
            </HStack>
          );
        },
      },
    ];
    return cols;
  }, [can, unitId, openForm]);

  const hasFilters = Boolean(query.q || query.status || query.followup || query.hot || query.stage);
  const clear = () => {
    setSearchText('');
    setQuery({q: '', status: '', followup: '', hot: '', stage: ''});
  };
  const stages = list.data?.stages ?? [];
  const all = list.data?.totals.all ?? 0;

  return (
    <VStack gap={3}>
      {isPhone ? (
        <Selector
          label="Stage"
          isLabelHidden
          value={query.stage || 'all'}
          onChange={value => setQuery({stage: value === 'all' || !value ? '' : value})}
          options={[{value: 'all', label: `All stages (${number(all)})`}, ...stages.map(s => ({value: s.value, label: `${s.label} (${number(s.count)})`}))]}
        />
      ) : (
        <SegmentedControl label="Pipeline stage" value={query.stage || 'all'} onChange={value => setQuery({stage: value === 'all' ? '' : value})}>
          <SegmentedControlItem value="all" label={`All ${number(all)}`} />
          {stages.map(s => (
            <SegmentedControlItem key={s.value} value={s.value} label={`${s.label} ${number(s.count)}`} />
          ))}
        </SegmentedControl>
      )}
      <DataTable<LeadRow>
        label="Leads"
        rows={list.data?.data}
        meta={list.data?.meta}
        columns={columns}
        idKey="id"
        isLoading={list.isLoading}
        isRefreshing={list.isRefreshing}
        search={searchText}
        onSearch={setSearchText}
        searchPlaceholder="Search name, phone or admission code"
        filters={
          <HStack gap={2} wrap="wrap">
            <Selector label="Follow-up" isLabelHidden placeholder="Any follow-up date" hasClear value={query.followup || null} onChange={value => setQuery({followup: value ?? ''})}
              options={[{value: 'today', label: 'Due today'}, {value: 'overdue', label: 'Overdue'}, {value: 'unscheduled', label: 'No next date'}]} />
            <Selector label="Status" isLabelHidden placeholder="Any status" hasClear value={query.status || null} onChange={value => setQuery({status: value ?? ''})}
              options={[{value: 'LEAD', label: 'Active'}, {value: 'JOINED', label: 'Registered'}, {value: 'LOST', label: 'Lost'}]} />
            <ToggleButton label="Hot only" isPressed={Boolean(query.hot)} onPressedChange={pressed => setQuery({hot: pressed ? '1' : ''})} />
          </HStack>
        }
        {...tableHandlers(setQuery)}
        hasActiveFilters={hasFilters}
        onClearFilters={clear}
        emptyTitle="No leads yet"
        emptyDescription="New enquiries appear here. Add one with New lead."
        phoneCard={{
          avatar: row => row.name,
          title: row => (row.isHot ? `Hot · ${row.name}` : row.name),
          subtitle: row => `${row.stageLabel} · ${row.followUpState === 'closed' ? row.stageLabel : row.nextFollowUpOn ? `Next ${date(row.nextFollowUpOn)}` : 'No next date'}`,
          trailing: row => (can?.followup && row.status === 'LEAD' ? <Button label="Log" size="sm" variant="secondary" onClick={() => openForm({kind: 'followup', lead: {id: row.id, name: row.name, nextFollowUpOn: row.nextFollowUpOn}})} /> : undefined),
          href: row => `/app/u/${unitId}/admissions/${row.id}`,
        }}
      />
    </VStack>
  );
}

function Demos({unitId, query, setQuery, searchText, setSearchText, nonce, openForm}: TabProps) {
  const list = useResource<SubList<DemoRow>>(`/app-api/units/${unitId}/admissions/demos`, {q: query.q, scope: query.scope, page: query.page, per_page: query.per_page, _: nonce || undefined});
  const can = list.data?.can;
  const columns: DataColumn<DemoRow>[] = [
    {key: 'scheduledOn', header: 'When', renderCell: row => <Text hasTabularNumbers>{`${date(row.scheduledOn)} · ${row.scheduledTime}`}</Text>},
    {key: 'leadName', header: 'Student', renderCell: row => <Link href={`/app/u/${unitId}/admissions/${row.leadId}`}>{row.leadName}</Link>},
    {key: 'subjectName', header: 'Subject', renderCell: row => <Text>{row.subjectName ?? '—'}</Text>},
    {key: 'teacherName', header: 'Teacher', renderCell: row => <Text>{row.teacherName ?? 'Not assigned'}</Text>},
    {key: 'status', header: 'Status', renderCell: row => <Status value={row.status.toLowerCase()} />},
    {
      key: 'id',
      header: '',
      align: 'end',
      // Join, Contact and Update/Feedback stay on one line.
      width: pixel(270),
      renderCell: row => (
        <HStack gap={1} hAlign="end">
          {row.meetLink && <Button label="Join" size="sm" variant="ghost" href={row.meetLink} target="_blank" />}
          <DemoContacts row={row} />
          {!row.isMatched && can?.updateDemo && (
            <Button label="Update" size="sm" variant="secondary" onClick={() => openForm({kind: 'demo-update', demo: {id: row.id, scheduledOn: row.scheduledOn, scheduledTime: row.scheduledTime}})} />
          )}
          {row.isMatched && can?.matching && <Button label="Feedback" size="sm" variant="secondary" href={`/app/u/${unitId}/matching?lead_id=${row.leadId}&demo_id=${row.id}`} />}
        </HStack>
      ),
    },
  ];
  return (
    <DataTable<DemoRow>
      label="Demos"
      rows={list.data?.data}
      meta={list.data?.meta}
      columns={columns}
      idKey="id"
      isLoading={list.isLoading}
      isRefreshing={list.isRefreshing}
      search={searchText}
      onSearch={setSearchText}
      searchPlaceholder="Search student name or phone"
      filters={
        <SegmentedControl label="Demo range" value={query.scope || 'upcoming'} onChange={value => setQuery({scope: value === 'upcoming' ? '' : value})}>
          <SegmentedControlItem value="upcoming" label="Upcoming" />
          <SegmentedControlItem value="all" label="All & history" />
        </SegmentedControl>
      }
      {...tableHandlers(setQuery)}
      hasActiveFilters={Boolean(query.q)}
      onClearFilters={() => {
        setSearchText('');
        setQuery({q: ''});
      }}
      emptyTitle="No upcoming demos"
      emptyDescription="Schedule a demo from a lead's menu or page."
      phoneCard={{
        title: row => row.leadName,
        subtitle: row => `${titleCase(row.status)} · ${date(row.scheduledOn)} · ${row.scheduledTime} · ${row.teacherName ?? 'No teacher yet'}`,
        trailing: row => <DemoContacts row={row} />,
        href: row => `/app/u/${unitId}/admissions/${row.leadId}`,
      }}
    />
  );
}

function Reminders({unitId, query, setQuery, searchText, setSearchText, nonce, reload}: TabProps) {
  const showToast = useToast();
  const list = useResource<SubList<ReminderRow>>(`/app-api/units/${unitId}/admissions/reminders`, {q: query.q, scope: query.scope, page: query.page, per_page: query.per_page, _: nonce || undefined});
  const can = list.data?.can;
  const complete = async (row: ReminderRow) => {
    try {
      await postJson(businessAction(unitId, 'admission-complete-reminder'), {reminder_id: row.id});
      showToast({body: 'Reminder done.', uniqueID: `reminder-${row.id}`});
      reload();
    } catch (reason) {
      showToast({body: reason instanceof ApiError ? reason.message : 'Could not connect. Check your internet and try again; nothing was changed.', uniqueID: `reminder-error-${row.id}`});
    }
  };
  const columns: DataColumn<ReminderRow>[] = [
    {key: 'dueOn', header: 'Due', renderCell: row => <Toned tone={row.isOverdue ? 'error' : undefined} strong={row.isOverdue}>{`${date(row.dueOn)}${row.dueTime ? ` · ${row.dueTime}` : ''}`}</Toned>},
    {key: 'leadName', header: 'Lead', renderCell: row => <Link href={`/app/u/${unitId}/admissions/${row.leadId}`}>{row.leadName}</Link>},
    {key: 'text', header: 'Reminder', renderCell: row => <Text>{row.text}</Text>},
    {key: 'id', header: '', align: 'end', renderCell: row => (can?.completeReminder ? <Button label="Done" size="sm" variant="secondary" onClick={() => complete(row)} /> : <Text> </Text>)},
  ];
  return (
    <DataTable<ReminderRow>
      label="Reminders"
      rows={list.data?.data}
      meta={list.data?.meta}
      columns={columns}
      idKey="id"
      isLoading={list.isLoading}
      isRefreshing={list.isRefreshing}
      search={searchText}
      onSearch={setSearchText}
      searchPlaceholder="Search lead or reminder"
      filters={
        <SegmentedControl label="Reminder range" value={query.scope || 'due'} onChange={value => setQuery({scope: value === 'due' ? '' : value})}>
          <SegmentedControlItem value="due" label="Due now" />
          <SegmentedControlItem value="all" label="All open" />
        </SegmentedControl>
      }
      {...tableHandlers(setQuery)}
      hasActiveFilters={Boolean(query.q)}
      onClearFilters={() => {
        setSearchText('');
        setQuery({q: ''});
      }}
      emptyTitle="No reminders due"
      emptyDescription="Add a reminder from any lead to keep track of the next step."
      phoneCard={{
        title: row => row.leadName,
        subtitle: row => `${date(row.dueOn)} · ${row.text}`,
        trailing: row => (can?.completeReminder ? <Button label="Done" size="sm" variant="secondary" onClick={() => complete(row)} /> : undefined),
      }}
    />
  );
}

export function TransferActions({row, onResolve}: {row: TransferRow; onResolve: (id: number, status: 'accepted' | 'rejected' | 'cancelled') => void}) {
  return (
    <HStack gap={1} hAlign="end">
      {row.canAccept && <Button label="Accept" size="sm" variant="primary" onClick={() => onResolve(row.id, 'accepted')} />}
      {row.canDecline && <Button label="Decline" size="sm" variant="secondary" onClick={() => onResolve(row.id, 'rejected')} />}
      {row.canCancel && <Button label="Cancel request" size="sm" variant="ghost" onClick={() => onResolve(row.id, 'cancelled')} />}
    </HStack>
  );
}

function Transfers({unitId, query, setQuery, searchText, setSearchText, nonce, reload}: TabProps) {
  const list = useResource<SubList<TransferRow>>(`/app-api/units/${unitId}/admissions/transfers`, {q: query.q, page: query.page, per_page: query.per_page, _: nonce || undefined});
  const resolve = useResolveTransfer(unitId, reload);
  const columns: DataColumn<TransferRow>[] = [
    {key: 'leadName', header: 'Lead', renderCell: row => <Text>{row.leadName}</Text>},
    {key: 'fromName', header: 'From → to', renderCell: row => <Text>{`${row.fromName ?? 'Previous owner'} → ${row.toName ?? 'Colleague'}`}</Text>},
    {key: 'createdAt', header: 'Requested', renderCell: row => <Text hasTabularNumbers>{dateTime(row.createdAt)}</Text>},
    {key: 'id', header: '', align: 'end', renderCell: row => <TransferActions row={row} onResolve={resolve} />},
  ];
  return (
    <DataTable<TransferRow>
      label="Transfers"
      rows={list.data?.data}
      meta={list.data?.meta}
      columns={columns}
      idKey="id"
      isLoading={list.isLoading}
      isRefreshing={list.isRefreshing}
      search={searchText}
      onSearch={setSearchText}
      searchPlaceholder="Search lead"
      {...tableHandlers(setQuery)}
      hasActiveFilters={Boolean(query.q)}
      onClearFilters={() => {
        setSearchText('');
        setQuery({q: ''});
      }}
      emptyTitle="No pending transfers"
      emptyDescription="Request a transfer from a lead's page. Ownership changes only when your colleague accepts."
      phoneCard={{
        title: row => row.leadName,
        subtitle: row => `${row.fromName ?? 'Previous owner'} → ${row.toName ?? 'Colleague'}`,
        trailing: row => <TransferActions row={row} onResolve={resolve} />,
      }}
    />
  );
}
