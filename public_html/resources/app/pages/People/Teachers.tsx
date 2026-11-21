import {useEffect, useMemo, useState} from 'react';
import {useParams, useSearchParams} from 'react-router';
import {HStack} from '@astryxdesign/core/Layout';
import {Text} from '@astryxdesign/core/Text';
import {Link} from '@astryxdesign/core/Link';
import {Button} from '@astryxdesign/core/Button';
import {Icon} from '@astryxdesign/core/Icon';
import {Selector} from '@astryxdesign/core/Selector';
import {MoreMenu} from '@astryxdesign/core/MoreMenu';
import {PlusIcon} from '@heroicons/react/24/outline';
import {Page, PageError} from '../../components/PageState';
import {DataTable, type DataColumn, type ListMeta} from '../../components/DataTable';
import {Status} from '../../components/Status';
import {Balance} from '../../components/Amount';
import {useDebounced, useListQuery, useResource} from '../../lib/hooks';
import {number} from '../../lib/format';
import {unitById} from '../../lib/session';
import {canAct, withParam} from './access';
import {TeacherForms, type TeacherFormKind} from './TeacherForms';

interface TeacherRow extends Record<string, unknown> {
  id: number;
  code: string;
  name: string;
  status: string;
  levels: string[];
  category: string | null;
  subjects: string[];
  ttHours: string;
  activeClasses: number;
  earnedPaise: number | null;
  paidPaise: number | null;
  netPayablePaise: number | null;
}

type Option = {value: string; label: string};

interface TeacherList {
  data: TeacherRow[];
  meta: ListMeta;
  can: {seeMoney: boolean};
  options: {levels: Option[]; categories: string[]; subjects: Option[]};
}

const DEFAULTS = {q: '', status: '', level: '', category: '', subject_id: '', sort: 'code', dir: 'asc', page: '1', per_page: '25'};

export default function Teachers() {
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

  const list = useResource<TeacherList>(`/app-api/units/${unitId}/teachers`, query);
  const can = list.data?.can;
  const canAdd = canAct(unit, 'teacher');
  const canClassify = canAct(unit, 'teacher-classify');
  const canEligibility = canAct(unit, 'eligibility');
  const [form, setForm] = useState<{kind: TeacherFormKind; teacher?: TeacherRow} | null>(null);
  const adding = params.get('new') === 'teacher';
  const setAdding = (open: boolean) => setParams(current => withParam(current, {new: open ? 'teacher' : null}));

  const columns = useMemo<DataColumn<TeacherRow>[]>(() => {
    const cols: DataColumn<TeacherRow>[] = [
      {key: 'name', header: 'Teacher', sortKey: 'name', renderCell: row => <Link href={`/app/u/${unitId}/teachers/${row.id}`}>{row.name}</Link>},
      {key: 'code', header: 'Code', sortKey: 'code', renderCell: row => <Text color="secondary">{row.code}</Text>},
      {key: 'levels', header: 'Level · category', renderCell: row => <Text>{[row.levels.join(', '), row.category].filter(Boolean).join(' · ') || '—'}</Text>},
      {key: 'subjects', header: 'Subjects', renderCell: row => <Text>{row.subjects.join(', ') || '—'}</Text>},
      {key: 'ttHours', header: 'TT hrs / wk', sortKey: 'tt', align: 'end', renderCell: row => <Text hasTabularNumbers>{row.ttHours}</Text>},
      {key: 'activeClasses', header: 'Classes', sortKey: 'classes', align: 'end', renderCell: row => <Text hasTabularNumbers>{number(row.activeClasses)}</Text>},
      {key: 'status', header: 'Status', sortKey: 'status', renderCell: row => <Status value={row.status} />},
    ];
    if (can?.seeMoney) {
      cols.push(
        {key: 'earnedPaise', header: 'Earned', align: 'end', renderCell: row => <Balance paise={row.earnedPaise} />},
        {key: 'paidPaise', header: 'Paid', align: 'end', renderCell: row => <Balance paise={row.paidPaise} />},
        {key: 'netPayablePaise', header: 'Payable', align: 'end', renderCell: row => <Balance paise={row.netPayablePaise} />},
      );
    }
    if (canClassify || canEligibility) {
      cols.push({
        key: 'id',
        header: '',
        align: 'end',
        renderCell: row => (
          <MoreMenu
            label={`Actions for ${row.name}`}
            size="sm"
            alignment="end"
            items={[
              ...(canClassify ? [{label: 'Set level & category', onClick: () => setForm({kind: 'classify', teacher: row})}] : []),
              ...(canEligibility ? [{label: 'Add subject eligibility', onClick: () => setForm({kind: 'eligibility', teacher: row})}] : []),
            ]}
          />
        ),
      });
    }
    return cols;
  }, [can, unitId, canClassify, canEligibility]);

  const hasFilters = Boolean(query.q || query.status || query.level || query.category || query.subject_id);
  const clear = () => {
    setSearchText('');
    setQuery({q: '', status: '', level: '', category: '', subject_id: ''});
  };

  if (list.error && !list.data) {
    return (
      <Page title="Teachers">
        <PageError error={list.error} onRetry={list.reload} />
      </Page>
    );
  }
  const options = list.data?.options;

  return (
    <Page
      title="Teachers"
      description={list.data ? `${number(list.data.meta.total)} ${hasFilters ? 'matching' : 'in this team'}` : undefined}
      actions={canAdd && <Button label="Add teacher" variant="primary" icon={<Icon icon={PlusIcon} size="sm" />} onClick={() => setAdding(true)} />}>
      <DataTable<TeacherRow>
        label="Teachers"
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
            <Selector label="Status" isLabelHidden placeholder="Any status" hasClear value={query.status || null} onChange={value => setQuery({status: value ?? ''})}
              options={[{value: 'active', label: 'Active'}, {value: 'inactive', label: 'Inactive'}, {value: 'stopped', label: 'Stopped'}]} />
            <Selector label="Level" isLabelHidden placeholder="Any level" hasClear value={query.level || null} onChange={value => setQuery({level: value ?? ''})} options={options?.levels ?? []} />
            <Selector label="Category" isLabelHidden placeholder="Any category" hasClear value={query.category || null} onChange={value => setQuery({category: value ?? ''})}
              options={(options?.categories ?? []).map(c => ({value: c, label: c}))} />
            <Selector label="Subject" isLabelHidden placeholder="Any subject" hasClear hasSearch value={query.subject_id || null} onChange={value => setQuery({subject_id: value ?? ''})} options={options?.subjects ?? []} />
          </HStack>
        }
        onSort={(sort, dir) => setQuery({sort, dir}, {resetPage: true})}
        onPage={page => setQuery({page: String(page)}, {resetPage: false})}
        onPageSize={size => setQuery({per_page: String(size)})}
        hasActiveFilters={hasFilters}
        onClearFilters={clear}
        emptyTitle="No teachers yet"
        emptyDescription="Teachers appear here once they are added or approved from Teacher hiring."
        emptyAction={canAdd ? <Button label="Add teacher" variant="secondary" onClick={() => setAdding(true)} /> : undefined}
        phoneCard={{
          avatar: row => row.name,
          title: row => row.name,
          subtitle: row => `${row.code} · ${row.ttHours} TT hrs · ${row.status}`,
          trailing: row => (row.netPayablePaise !== null ? <Balance paise={row.netPayablePaise} whole /> : undefined),
          href: row => `/app/u/${unitId}/teachers/${row.id}`,
        }}
      />
      {unit && (
        <TeacherForms
          unitId={unitId}
          timezone={unit.timezone}
          kind={adding ? 'add' : form?.kind ?? null}
          teacher={form?.teacher}
          levels={options?.levels ?? []}
          categories={options?.categories ?? []}
          onClose={() => (adding ? setAdding(false) : setForm(null))}
          onSaved={list.reload}
        />
      )}
    </Page>
  );
}
