import {useEffect, useMemo, useState} from 'react';
import {useParams, useSearchParams} from 'react-router';
import {HStack} from '@astryxdesign/core/Layout';
import {Text} from '@astryxdesign/core/Text';
import {Link} from '@astryxdesign/core/Link';
import {Button} from '@astryxdesign/core/Button';
import {Icon} from '@astryxdesign/core/Icon';
import {Selector} from '@astryxdesign/core/Selector';
import {ToggleButton} from '@astryxdesign/core/ToggleButton';
import {PlusIcon} from '@heroicons/react/24/outline';
import {Page, PageError} from '../components/PageState';
import {DataTable, type DataColumn, type ListMeta} from '../components/DataTable';
import {FormDrawer, type Field} from '../components/FormDrawer';
import {Status} from '../components/Status';
import {Metric, MetricRow} from '../components/Metric';
import {Balance} from '../components/Amount';
import {useDebounced, useListQuery, useResource} from '../lib/hooks';
import {money, date, number, titleCase} from '../lib/format';
import {unitById} from '../lib/session';

interface StudentRow extends Record<string, unknown> {
  id: number;
  code: string;
  name: string;
  grade: string;
  syllabus: string;
  status: string;
  registeredOn: string;
  priority: string;
  activeClasses: number;
  balancePaise: number | null;
}

interface StudentList {
  data: StudentRow[];
  meta: ListMeta;
  can: {seeBalance: boolean; openProfile: boolean};
  totals: {dueCount: number; duePaise: number} | null;
}

const DEFAULTS = {q: '', status: '', priority: '', due: '', sort: 'name', dir: 'asc', page: '1', per_page: '25'};

function today(timezone: string): string {
  return new Intl.DateTimeFormat('en-CA', {timeZone: timezone}).format(new Date());
}

export default function Students() {
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

  const list = useResource<StudentList>(`/app-api/units/${unitId}/students`, query);
  const can = list.data?.can;
  const canCreate = Boolean(unit && !unit.isArchived && (unit.role === 'superadmin' || unit.actions.includes('student')));
  const creating = params.get('new') === 'student';
  const setCreating = (open: boolean) =>
    setParams(current => {
      const next = new URLSearchParams(current);
      if (open) next.set('new', 'student');
      else next.delete('new');
      return next;
    });

  const columns = useMemo<DataColumn<StudentRow>[]>(() => {
    const cols: DataColumn<StudentRow>[] = [
      {
        key: 'name',
        header: 'Student',
        sortKey: 'name',
        renderCell: row =>
          can?.openProfile ? <Link href={`/app/u/${unitId}/students/${row.id}`}>{row.name}</Link> : <Text>{row.name}</Text>,
      },
      {key: 'code', header: 'Code', sortKey: 'code', renderCell: row => <Text color="secondary">{row.code}</Text>},
      {key: 'grade', header: 'Grade · syllabus', sortKey: 'grade', renderCell: row => <Text>{`${row.grade} · ${row.syllabus}`}</Text>},
      {key: 'activeClasses', header: 'Classes', align: 'end', renderCell: row => <Text hasTabularNumbers>{number(row.activeClasses)}</Text>},
      {key: 'registeredOn', header: 'Joined', sortKey: 'registered_on', renderCell: row => <Text hasTabularNumbers>{date(row.registeredOn)}</Text>},
      {key: 'status', header: 'Status', sortKey: 'status', renderCell: row => <Status value={row.status} />},
    ];
    if (can?.seeBalance) {
      cols.push({
        key: 'balancePaise',
        header: 'Balance',
        sortKey: 'balance',
        align: 'end',
        renderCell: row => <Balance paise={row.balancePaise} />,
      });
    }
    return cols;
  }, [can, unitId]);

  const hasFilters = Boolean(query.q || query.status || query.priority || query.due);
  const clear = () => {
    setSearchText('');
    setQuery({q: '', status: '', priority: '', due: ''});
  };

  const fields: Field[] = unit
    ? [
        {kind: 'text', name: 'name', label: 'Full name', required: true},
        {kind: 'text', name: 'code', label: 'Student code', required: true, description: 'Unique across all teams, e.g. CL-1042'},
        {kind: 'text', name: 'grade', label: 'Grade', required: true, placeholder: 'e.g. 7'},
        {kind: 'select', name: 'syllabus', label: 'Syllabus', required: true, options: ['CBSE', 'ICSE', 'IGCSE', 'IB', 'State board', 'Other'].map(v => ({value: v, label: v}))},
        {kind: 'date', name: 'registered_on', label: 'Joined on', required: true, max: today(unit.timezone)},
        {kind: 'tel', name: 'guardian_contact', label: 'Parent phone or email'},
        {kind: 'url', name: 'meeting_url', label: 'Class meeting link'},
      ]
    : [];

  if (list.error && !list.data) {
    return (
      <Page title="Students">
        <PageError error={list.error} onRetry={list.reload} />
      </Page>
    );
  }

  const exportHref = unit?.exports.includes('students') ? `/erp/units/${unitId}/exports/students` : undefined;

  return (
    <Page
      title="Students"
      description={list.data ? `${number(list.data.meta.total)} ${hasFilters ? 'matching' : 'in this team'}` : undefined}
      actions={canCreate && <Button label="Add student" variant="primary" icon={<Icon icon={PlusIcon} size="sm" />} onClick={() => setCreating(true)} />}>
      {list.data?.totals && (
        <MetricRow>
          <Metric label="Families with dues" value={number(list.data.totals.dueCount)} onClick={() => setQuery({due: query.due ? '' : '1'})} isActive={Boolean(query.due)} />
          <Metric label="Total outstanding" value={money(-list.data.totals.duePaise, {whole: true})} tone="error" />
        </MetricRow>
      )}
      <DataTable<StudentRow>
        label="Students"
        rows={list.data?.data}
        meta={list.data?.meta}
        columns={columns}
        idKey="id"
        isLoading={list.isLoading}
        isRefreshing={list.isRefreshing}
        search={searchText}
        onSearch={setSearchText}
        searchPlaceholder="Search by name or code"
        filters={
          <HStack gap={2} wrap="wrap">
            <Selector
              label="Status"
              isLabelHidden
              size="md"
              placeholder="Any status"
              hasClear
              value={query.status || null}
              onChange={value => setQuery({status: value ?? ''})}
              options={[
                {value: 'active', label: 'Active'},
                {value: 'paused', label: 'Paused'},
                {value: 'stopped', label: 'Stopped'},
              ]}
            />
            <Selector
              label="Joined"
              isLabelHidden
              placeholder="Any join date"
              hasClear
              value={query.priority || null}
              onChange={value => setQuery({priority: value ?? ''})}
              options={[
                {value: 'super', label: 'Joined this month'},
                {value: 'high', label: 'Joined last month'},
                {value: 'medium', label: 'Joined earlier'},
              ]}
            />
            {can?.seeBalance && (
              <ToggleButton label="Has dues" isPressed={Boolean(query.due)} onPressedChange={pressed => setQuery({due: pressed ? '1' : ''})} />
            )}
          </HStack>
        }
        onSort={(sort, dir) => setQuery({sort, dir}, {resetPage: true})}
        onPage={page => setQuery({page: String(page)}, {resetPage: false})}
        onPageSize={size => setQuery({per_page: String(size)})}
        exportHref={exportHref}
        hasActiveFilters={hasFilters}
        onClearFilters={clear}
        emptyTitle="No students yet"
        emptyDescription="Students appear here once they are registered or converted from admissions."
        emptyAction={canCreate ? <Button label="Add student" variant="secondary" onClick={() => setCreating(true)} /> : undefined}
        phoneCard={{
          title: row => row.name,
          subtitle: row => `${row.code} · Grade ${row.grade} · ${titleCase(row.status)}`,
          trailing: row => (row.balancePaise !== null ? <Balance paise={row.balancePaise} whole /> : undefined),
          href: row => (can?.openProfile ? `/app/u/${unitId}/students/${row.id}` : undefined),
        }}
      />
      {canCreate && unit && (
        <FormDrawer
          isOpen={creating}
          onOpenChange={setCreating}
          title="Add a student"
          description="Use this for direct registrations. Leads from enquiries are converted from Admissions."
          fields={fields}
          initial={{registered_on: today(unit.timezone), syllabus: 'CBSE'}}
          action={`/erp/units/${unitId}/student`}
          submitLabel="Add student"
          successMessage="Student added."
          onSaved={() => {
            setQuery({sort: 'registered_on', dir: 'desc'});
            list.reload();
          }}
        />
      )}
    </Page>
  );
}
