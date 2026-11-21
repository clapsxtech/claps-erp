import {useEffect, useMemo, useState} from 'react';
import {useNavigate, useParams, useSearchParams} from 'react-router';
import {HStack, VStack} from '@astryxdesign/core/Layout';
import {Text} from '@astryxdesign/core/Text';
import {Link} from '@astryxdesign/core/Link';
import {Button} from '@astryxdesign/core/Button';
import {Icon} from '@astryxdesign/core/Icon';
import {Selector} from '@astryxdesign/core/Selector';
import {TextInput} from '@astryxdesign/core/TextInput';
import {PlusIcon} from '@heroicons/react/24/outline';
import {Page, PageError} from '../../components/PageState';
import {DataTable, type DataColumn, type ListMeta} from '../../components/DataTable';
import {Status} from '../../components/Status';
import {useDebounced, useListQuery, useResource} from '../../lib/hooks';
import {number, titleCase} from '../../lib/format';
import {unitById} from '../../lib/session';
import {FormDrawer} from '../../components/FormDrawer';
import {businessAction, canAct, withParam} from './access';

interface CandidateRow extends Record<string, unknown> {
  id: number;
  name: string;
  teacherCode: string | null;
  phone: string | null;
  email: string | null;
  qualification: string | null;
  subjects: string | null;
  grades: string | null;
  syllabuses: string | null;
  medium: string | null;
  availability: string | null;
  stage: string;
  sourceStatus: string | null;
  operationalStatus: string | null;
}

interface CandidateList {
  data: CandidateRow[];
  meta: ListMeta;
  stages: {value: string; count: number}[];
  can: {manage: boolean};
}

const DEFAULTS = {q: '', stage: '', subject: '', syllabus: '', medium: '', availability: '', sort: 'name', dir: 'asc', page: '1', per_page: '25'};

/** Free-text filter that only updates the URL after typing pauses. */
function TextFilter({label, value, onChange}: {label: string; value: string; onChange: (value: string) => void}) {
  const [text, setText] = useState(value);
  const debounced = useDebounced(text);
  useEffect(() => {
    if (debounced !== value) onChange(debounced);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debounced]);
  useEffect(() => setText(value), [value]);
  return <TextInput label={label} isLabelHidden placeholder={label} value={text} onChange={setText} width={150} />;
}

export default function Recruitment() {
  const unitId = Number(useParams().unit);
  const unit = unitById(unitId);
  const navigate = useNavigate();
  const [query, setQuery] = useListQuery(DEFAULTS);
  const [params, setParams] = useSearchParams();
  const [searchText, setSearchText] = useState(query.q);
  const debounced = useDebounced(searchText);
  useEffect(() => {
    if (debounced !== query.q) setQuery({q: debounced});
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debounced]);

  const list = useResource<CandidateList>(`/app-api/units/${unitId}/recruitment`, query);
  const manage = Boolean(list.data?.can.manage) && canAct(unit, 'teacher-candidate-create');
  const creating = params.get('new') === 'candidate';
  const setCreating = (open: boolean) => setParams(current => withParam(current, {new: open ? 'candidate' : null}));

  const columns = useMemo<DataColumn<CandidateRow>[]>(
    () => [
      {
        key: 'name',
        header: 'Teacher',
        sortKey: 'name',
        renderCell: row => (
          <VStack gap={0.5}>
            {list.data?.can.manage ? <Link href={`/app/u/${unitId}/recruitment/${row.id}`}>{row.name}</Link> : <Text>{row.name}</Text>}
            <Text size="sm" color="secondary">{[row.teacherCode, row.qualification].filter(Boolean).join(' · ') || '—'}</Text>
          </VStack>
        ),
      },
      {key: 'phone', header: 'Contact', renderCell: row => (row.phone ? <Link href={`tel:+${row.phone}`}>{row.phone}</Link> : <Text color="secondary">Needs review</Text>)},
      {
        key: 'subjects',
        header: 'Teaches',
        renderCell: row => (
          <VStack gap={0.5}>
            <Text>{row.subjects ?? '—'}</Text>
            <Text size="sm" color="secondary">{[row.grades, row.syllabuses, row.medium].filter(Boolean).join(' · ') || ' '}</Text>
          </VStack>
        ),
      },
      {key: 'availability', header: 'Preferred times', renderCell: row => <Text color="secondary">{row.availability ?? 'Confirm with teacher'}</Text>},
      {key: 'stage', header: 'Stage', sortKey: 'stage', renderCell: row => <Status value={row.stage.toLowerCase()} label={titleCase(row.stage.toLowerCase())} />},
    ],
    [list.data?.can.manage, unitId],
  );

  const hasFilters = Boolean(query.q || query.stage || query.subject || query.syllabus || query.medium || query.availability);
  const clear = () => {
    setSearchText('');
    setQuery({q: '', stage: '', subject: '', syllabus: '', medium: '', availability: ''});
  };

  if (list.error && !list.data) {
    return (
      <Page title="Teacher hiring">
        <PageError error={list.error} onRetry={list.reload} />
      </Page>
    );
  }

  return (
    <Page
      title="Teacher hiring"
      description={list.data?.can.manage ? 'Review applications, record registration and approve teachers into the pool.' : 'Registered teachers you can match to classes and demos.'}
      actions={manage && <Button label="New application" variant="primary" icon={<Icon icon={PlusIcon} size="sm" />} onClick={() => setCreating(true)} />}>
      <DataTable<CandidateRow>
        label="Teacher profiles"
        rows={list.data?.data}
        meta={list.data?.meta}
        columns={columns}
        idKey="id"
        isLoading={list.isLoading}
        isRefreshing={list.isRefreshing}
        search={searchText}
        onSearch={setSearchText}
        searchPlaceholder="Name, phone or teacher code"
        filters={
          <HStack gap={2} wrap="wrap">
            <Selector
              label="Stage"
              isLabelHidden
              placeholder={list.data?.can.manage ? 'Open stages' : 'Registered directory'}
              hasClear
              value={query.stage || null}
              onChange={value => setQuery({stage: value ?? ''})}
              options={(list.data?.stages ?? []).map(s => ({value: s.value, label: `${titleCase(s.value.toLowerCase())} (${number(s.count)})`}))}
            />
            <TextFilter label="Subject" value={query.subject} onChange={value => setQuery({subject: value})} />
            <TextFilter label="Syllabus" value={query.syllabus} onChange={value => setQuery({syllabus: value})} />
            <TextFilter label="Medium" value={query.medium} onChange={value => setQuery({medium: value})} />
            <TextFilter label="Preferred time" value={query.availability} onChange={value => setQuery({availability: value})} />
          </HStack>
        }
        onSort={(sort, dir) => setQuery({sort, dir}, {resetPage: true})}
        onPage={page => setQuery({page: String(page)}, {resetPage: false})}
        onPageSize={size => setQuery({per_page: String(size)})}
        hasActiveFilters={hasFilters}
        onClearFilters={clear}
        emptyTitle="No teacher profiles yet"
        emptyDescription="Applications and registered teachers appear here."
        phoneCard={{
          avatar: row => row.name,
          title: row => row.name,
          subtitle: row => `${titleCase(row.stage.toLowerCase())} · ${row.subjects ?? 'Subjects not recorded'}`,
          href: row => (list.data?.can.manage ? `/app/u/${unitId}/recruitment/${row.id}` : undefined),
        }}
      />
      {manage && (
        <FormDrawer
          isOpen={creating}
          onOpenChange={setCreating}
          title="New application"
          description="A repeat application with the same name and phone opens the existing profile instead."
          fields={[
            {kind: 'text', name: 'name', label: 'Name', required: true},
            {kind: 'tel', name: 'phone', label: 'Phone', required: true},
            {kind: 'email', name: 'email', label: 'Email'},
            {kind: 'text', name: 'qualification', label: 'Qualification'},
            {kind: 'text', name: 'subjects', label: 'Subjects'},
            {kind: 'url', name: 'cv_url', label: 'CV link'},
          ]}
          action={businessAction(unitId, 'teacher-candidate-create')}
          submitLabel="Save application"
          successMessage="Application saved."
          onSaved={result => {
            const id = (result as {id?: number} | null)?.id;
            if (id) navigate(`/app/u/${unitId}/recruitment/${id}`);
            else list.reload();
          }}
        />
      )}
    </Page>
  );
}
