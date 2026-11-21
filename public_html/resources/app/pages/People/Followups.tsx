import {useEffect, useMemo, useState} from 'react';
import {useParams, useSearchParams} from 'react-router';
import {HStack} from '@astryxdesign/core/Layout';
import {Text} from '@astryxdesign/core/Text';
import {Link} from '@astryxdesign/core/Link';
import {Button} from '@astryxdesign/core/Button';
import {Icon} from '@astryxdesign/core/Icon';
import {Selector} from '@astryxdesign/core/Selector';
import {TabList, Tab} from '@astryxdesign/core/TabList';
import {PlusIcon} from '@heroicons/react/24/outline';
import {Page, PageError} from '../../components/PageState';
import {DataTable, type DataColumn, type ListMeta} from '../../components/DataTable';
import {Status} from '../../components/Status';
import {Metric, MetricRow} from '../../components/Metric';
import {Balance, Toned} from '../../components/Amount';
import {useDebounced, useListQuery, useResource} from '../../lib/hooks';
import {date, dateTime, number, titleCase} from '../../lib/format';
import {unitById} from '../../lib/session';
import {ActionDrawer, dateShortcuts} from './ActionDrawer';
import {businessAction, canAct, withParam} from './access';

interface FollowupRow extends Record<string, unknown> {
  id: number;
  studentId: number;
  code: string;
  name: string;
  status: string;
  priority: string;
  lastChannel: string | null;
  lastNote: string | null;
  lastOutcome: string | null;
  lastContactAt: string | null;
  nextDueOn: string | null;
  isOverdue: boolean;
  createdByName: string | null;
  balancePaise: number | null;
}

interface FollowupList {
  data: FollowupRow[];
  meta: ListMeta & {view: string};
  can: {seeBalance: boolean; openProfile: boolean};
  counts: {dueToday: number; overdue: number; loggedToday: number};
  today: string;
  student: {id: number; label: string} | null;
}

const DEFAULTS = {view: 'due', q: '', priority: '', sort: '', dir: '', page: '1', per_page: '25'};
const OUTCOMES = ['Interested', 'No reply', 'Call back later', 'Fees issue', 'Payment promised', 'Happy with classes', 'Wants a change', 'Not interested', 'Other'];

export default function Followups() {
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

  const studentParam = params.get('student') ?? '';
  const list = useResource<FollowupList>(`/app-api/units/${unitId}/followups`, {...query, student: studentParam || undefined});
  const can = list.data?.can;
  const canLog = canAct(unit, 'followup');
  const logging = params.get('new') === 'followup';
  const [picked, setPicked] = useState<{id: number; label: string} | null>(null);
  const preset = picked ?? (list.data?.student && String(list.data.student.id) === studentParam ? list.data.student : null);
  const openLog = (student: {id: number; label: string} | null) => {
    setPicked(student);
    setParams(current => withParam(current, {new: 'followup', student: student ? String(student.id) : null}));
  };
  const closeLog = () => {
    setPicked(null);
    setParams(current => withParam(current, {new: null, student: null}));
  };

  const view = query.view as 'due' | 'students' | 'history';
  const columns = useMemo<DataColumn<FollowupRow>[]>(() => {
    const name: DataColumn<FollowupRow> = {
      key: 'name',
      header: 'Student',
      sortKey: 'name',
      renderCell: row => (can?.openProfile ? <Link href={`/app/u/${unitId}/students/${row.studentId}`}>{row.name}</Link> : <Text>{row.name}</Text>),
    };
    const next: DataColumn<FollowupRow> = {
      key: 'nextDueOn',
      header: 'Next call',
      sortKey: 'next_due_on',
      renderCell: row => (row.nextDueOn ? <Toned tone={row.isOverdue ? 'error' : undefined} strong={row.isOverdue}>{date(row.nextDueOn)}</Toned> : <Text color="secondary">—</Text>),
    };
    const last: DataColumn<FollowupRow> = {key: 'lastOutcome', header: 'Last outcome', renderCell: row => <Text>{row.lastOutcome ? `${titleCase(row.lastChannel)} · ${row.lastOutcome}` : '—'}</Text>};
    const log: DataColumn<FollowupRow> = {
      key: 'studentId',
      header: '',
      align: 'end',
      renderCell: row => <Button label="Log follow-up" size="sm" variant="secondary" onClick={() => openLog({id: row.studentId, label: `${row.name} · ${row.code}`})} />,
    };
    let cols: DataColumn<FollowupRow>[];
    if (view === 'history') {
      cols = [
        {key: 'lastContactAt', header: 'When', sortKey: 'created_at', renderCell: row => <Text hasTabularNumbers>{dateTime(row.lastContactAt)}</Text>},
        name,
        last,
        {key: 'lastNote', header: 'Note', renderCell: row => <Text>{row.lastNote ?? '—'}</Text>},
        next,
        {key: 'createdByName', header: 'By', renderCell: row => <Text color="secondary">{row.createdByName ?? '—'}</Text>},
      ];
    } else {
      cols = [
        name,
        {key: 'priority', header: 'Priority', sortKey: 'registered_on', renderCell: row => <Text>{row.priority}</Text>},
        next,
        last,
        {key: 'lastNote', header: 'Last note', renderCell: row => <Text color="secondary">{row.lastNote ?? 'Never contacted'}</Text>},
      ];
      if (view === 'students') {
        cols.splice(1, 0, {key: 'status', header: 'Status', renderCell: row => <Status value={row.status} />});
        if (can?.seeBalance) cols.push({key: 'balancePaise', header: 'Balance', sortKey: 'balance', align: 'end', renderCell: row => <Balance paise={row.balancePaise} />});
      }
    }
    if (canLog) cols.push(log);
    return cols;
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [can, unitId, view, canLog]);

  const hasFilters = Boolean(query.q || query.priority);
  const clear = () => {
    setSearchText('');
    setQuery({q: '', priority: ''});
  };

  if (list.error && !list.data) {
    return (
      <Page title="Follow-ups">
        <PageError error={list.error} onRetry={list.reload} />
      </Page>
    );
  }
  const counts = list.data?.counts;

  return (
    <Page
      title="Follow-ups"
      description="Who to call today, and what was said last time. Logging a call does not send any message."
      actions={canLog && <Button label="Log follow-up" variant="primary" icon={<Icon icon={PlusIcon} size="sm" />} onClick={() => openLog(null)} />}>
      {counts && (
        <MetricRow>
          <Metric label="Due today" value={number(counts.dueToday)} onClick={() => setQuery({view: 'due', sort: 'next_due_on', dir: 'desc'})} isActive={view === 'due'} />
          <Metric label="Overdue" value={number(counts.overdue)} tone={counts.overdue > 0 ? 'error' : undefined} onClick={() => setQuery({view: 'due', sort: 'next_due_on', dir: 'asc'})} />
          <Metric label="Logged today" value={number(counts.loggedToday)} onClick={() => setQuery({view: 'history', sort: '', dir: ''})} isActive={view === 'history'} />
        </MetricRow>
      )}
      <TabList value={view} onChange={value => setQuery({view: value, sort: '', dir: ''})}>
        <Tab value="due" label="Call today" />
        <Tab value="students" label="All students" />
        <Tab value="history" label="History" />
      </TabList>
      <DataTable<FollowupRow>
        label="Follow-ups"
        rows={list.data?.data}
        meta={list.data?.meta}
        columns={columns}
        idKey="id"
        isLoading={list.isLoading}
        isRefreshing={list.isRefreshing}
        search={searchText}
        onSearch={setSearchText}
        searchPlaceholder="Search student name or code"
        filters={
          <HStack gap={2} wrap="wrap">
            <Selector
              label="Registration priority"
              isLabelHidden
              placeholder="Any priority"
              hasClear
              value={query.priority || null}
              onChange={value => setQuery({priority: value ?? ''})}
              options={[
                {value: 'super', label: 'Super: joined this month'},
                {value: 'high', label: 'High: joined last month'},
                {value: 'medium', label: 'Medium: joined earlier'},
              ]}
            />
          </HStack>
        }
        onSort={(sort, dir) => setQuery({sort, dir}, {resetPage: true})}
        onPage={page => setQuery({page: String(page)}, {resetPage: false})}
        onPageSize={size => setQuery({per_page: String(size)})}
        hasActiveFilters={hasFilters}
        onClearFilters={clear}
        emptyTitle={view === 'due' ? 'Nobody to call right now' : view === 'history' ? 'No follow-ups logged yet' : 'No students yet'}
        emptyDescription={view === 'due' ? 'Students appear here when the next call date of their latest follow-up arrives.' : undefined}
        phoneCard={{
          avatar: row => row.name,
          title: row => row.name,
          subtitle: row => `${row.nextDueOn ? `Call ${date(row.nextDueOn)}` : row.priority}${row.lastOutcome ? ` · ${row.lastOutcome}` : ''}`,
          trailing: row => (canLog ? <Button label="Log" size="sm" variant="secondary" onClick={() => openLog({id: row.studentId, label: `${row.name} · ${row.code}`})} /> : undefined),
        }}
      />
      {canLog && unit && (
        <ActionDrawer
          key={preset ? `s${preset.id}` : 'none'}
          unitId={unitId}
          isOpen={logging}
          onOpenChange={open => (open ? undefined : closeLog())}
          title="Log a follow-up"
          description="Records the conversation and when to call next. Nothing is sent to the family."
          fields={[
            {kind: 'lookup', name: 'student_id', label: 'Student', type: 'students', required: true},
            {kind: 'choice', name: 'contact_channel', label: 'How did you contact them?', required: true, options: [{value: 'call', label: 'Call'}, {value: 'whatsapp', label: 'WhatsApp'}]},
            {kind: 'select', name: 'outcome', label: 'Outcome', required: true, options: OUTCOMES.map(o => ({value: o, label: o}))},
            {kind: 'textarea', name: 'note', label: 'What was discussed', required: true},
            {kind: 'date', name: 'next_due_on', label: 'Next call', min: list.data?.today, description: 'Leave empty when no further call is needed.'},
          ]}
          initial={{contact_channel: 'call'}}
          initialLookups={{student_id: preset ? {id: String(preset.id), label: preset.label} : null}}
          shortcuts={{next_due_on: dateShortcuts(unit.timezone, [[1, 'Tomorrow'], [3, 'In 3 days'], [7, 'In a week'], [30, 'In a month']])}}
          action={businessAction(unitId, 'followup')}
          submitLabel="Save follow-up"
          successMessage="Follow-up saved."
          onSaved={list.reload}
        />
      )}
    </Page>
  );
}
