// Sales teacher directory (classic Admissions "Teachers" view, erp/crm/teachers):
// find a teacher by level, category or subject, call or WhatsApp them, and send
// their profile to a parent by WhatsApp. Opened from an enquiry (?for_lead=),
// "Send profile" goes straight to that parent's WhatsApp.
import {useEffect, useState} from 'react';
import {useParams} from 'react-router';
import {VStack, HStack, StackItem} from '@astryxdesign/core/Layout';
import {Grid} from '@astryxdesign/core/Grid';
import {Card} from '@astryxdesign/core/Card';
import {Heading, Text} from '@astryxdesign/core/Text';
import {Button} from '@astryxdesign/core/Button';
import {Icon} from '@astryxdesign/core/Icon';
import {TextInput} from '@astryxdesign/core/TextInput';
import {Selector} from '@astryxdesign/core/Selector';
import {TabList, Tab} from '@astryxdesign/core/TabList';
import {Token} from '@astryxdesign/core/Token';
import {Banner} from '@astryxdesign/core/Banner';
import {EmptyState} from '@astryxdesign/core/EmptyState';
import {Skeleton} from '@astryxdesign/core/Skeleton';
import {Pagination} from '@astryxdesign/core/Pagination';
import {MetadataList, MetadataListItem} from '@astryxdesign/core/MetadataList';
import {ChatBubbleLeftRightIcon, PhoneIcon, ShareIcon} from '@heroicons/react/24/outline';
import {Page, PageError} from '../../components/PageState';
import {useDebounced, useListQuery, useResource} from '../../lib/hooks';
import {date, number} from '../../lib/format';
import {unitById} from '../../lib/session';
import {canAct} from './access';
import {TeacherForms} from './TeacherForms';

interface DirectoryTeacher {
  id: number;
  code: string;
  name: string;
  ttHours: string;
  registeredOn: string | null;
  category: string | null;
  levels: string[];
  subjects: string | null;
  classes: string | null;
  mediums: string | null;
  qualification: string | null;
  dial: string | null;
  whatsapp: string | null;
  profileUrl: string;
}

type Option = {value: string; label: string};

interface DirectoryData {
  data: DirectoryTeacher[];
  meta: {page: number; perPage: number; total: number};
  filters: {levels: Option[]; categories: string[]; subjects: {id: number; name: string}[]; sorts: Option[]};
  profileLead: {id: number; name: string; hasWhatsapp: boolean} | null;
  can: {classify: boolean};
}

const DEFAULTS = {teacher_q: '', level: '', category: '', subject_id: '', sort: 'code', page: '1', for_lead: ''};
const external = {target: '_blank', rel: 'noopener noreferrer'};

export default function Directory() {
  const unitId = Number(useParams().unit);
  const unit = unitById(unitId);
  const [query, setQuery] = useListQuery(DEFAULTS);
  const [search, setSearch] = useState(query.teacher_q);
  const debounced = useDebounced(search);
  useEffect(() => {
    if (debounced !== query.teacher_q) setQuery({teacher_q: debounced});
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debounced]);
  const list = useResource<DirectoryData>(`/app-api/units/${unitId}/teacher-directory`, query);
  const [classifying, setClassifying] = useState<DirectoryTeacher | null>(null);
  const canClassify = canAct(unit, 'teacher-classify') && Boolean(list.data?.can.classify);
  const data = list.data;
  const hasFilters = Boolean(query.teacher_q || query.level || query.category || query.subject_id || query.sort !== 'code');

  if (list.error && !data) {
    return (
      <Page title="Teacher directory">
        <PageError error={list.error} onRetry={list.reload} />
      </Page>
    );
  }

  return (
    <Page
      title="Teacher directory"
      description="Find a teacher, call or message them, and send their profile to a parent. TT = weekly timetable hours."
      crumbs={[{label: 'Admissions', href: `/app/u/${unitId}/admissions`}, {label: 'Teacher directory'}]}>
      {data?.profileLead && (
        <Banner
          status="info"
          title={`Sending profiles to the parent of ${data.profileLead.name}`}
          description={data.profileLead.hasWhatsapp ? 'Send profile opens WhatsApp to this parent with the teacher profile ready to send.' : 'This enquiry has no international WhatsApp number, so Send profile lets you pick the chat.'}
          collapsible={false}
          endContent={<Button label="Back to enquiry" variant="secondary" size="sm" href={`/app/u/${unitId}/admissions/${data.profileLead.id}`} />}
        />
      )}
      <TabList value={query.level || 'all'} onChange={value => setQuery({level: value === 'all' ? '' : value})}>
        <Tab value="all" label="All levels" />
        {(data?.filters.levels ?? []).map(level => (
          <Tab key={level.value} value={level.value} label={level.value} />
        ))}
      </TabList>
      <HStack gap={2} wrap="wrap" vAlign="end">
        <StackItem size="fill">
          <TextInput label="Teacher name or CT code" isLabelHidden placeholder="Search name or CT code" value={search} onChange={setSearch} width="100%" />
        </StackItem>
        <Selector
          label="Category"
          isLabelHidden
          placeholder="All categories"
          hasClear
          value={query.category || null}
          onChange={value => setQuery({category: value ?? ''})}
          options={(data?.filters.categories ?? []).map(c => ({value: c, label: c}))}
        />
        <Selector
          label="Subject"
          isLabelHidden
          placeholder="All subjects"
          hasClear
          hasSearch
          value={query.subject_id || null}
          onChange={value => setQuery({subject_id: value ?? ''})}
          options={(data?.filters.subjects ?? []).map(s => ({value: String(s.id), label: s.name}))}
        />
        <Selector label="Order" isLabelHidden value={query.sort} onChange={value => setQuery({sort: value ?? 'code'})} options={data?.filters.sorts ?? [{value: 'code', label: 'CT code'}]} />
        {hasFilters && (
          <Button
            label="Clear filters"
            variant="ghost"
            onClick={() => {
              setSearch('');
              setQuery({teacher_q: '', level: '', category: '', subject_id: '', sort: 'code'});
            }}
          />
        )}
      </HStack>
      {!data ? (
        <Grid columns={{minWidth: 300, repeat: 'fill'}} gap={3}>
          {Array.from({length: 6}, (_, i) => <Skeleton key={i} height={220} width="100%" />)}
        </Grid>
      ) : data.data.length === 0 ? (
        <EmptyState
          title="No teachers match"
          description="Try another level, category or subject. Levels and categories are set by the academics team."
          actions={hasFilters ? <Button label="Show all teachers" variant="secondary" onClick={() => { setSearch(''); setQuery({teacher_q: '', level: '', category: '', subject_id: '', sort: 'code'}); }} /> : undefined}
        />
      ) : (
        <VStack gap={3}>
          <Text color="secondary" size="sm">{`${number(data.meta.total)} teachers`}</Text>
          <Grid columns={{minWidth: 300, repeat: 'fill'}} gap={3}>
            {data.data.map(teacher => (
              <Card key={teacher.id} padding={4}>
                <VStack gap={3}>
                  <HStack gap={2} vAlign="start">
                    <StackItem size="fill">
                      <VStack gap={0.5}>
                        <Heading level={3}>{teacher.name}</Heading>
                        <Text size="sm" color="secondary">{`${teacher.code} · Registered ${teacher.registeredOn ? date(teacher.registeredOn) : 'date not recorded'}`}</Text>
                      </VStack>
                    </StackItem>
                    {teacher.category && <Token label={teacher.category} size="sm" color={teacher.category === 'Advanced' ? 'purple' : 'default'} />}
                  </HStack>
                  <HStack gap={1} wrap="wrap">
                    {teacher.levels.length ? teacher.levels.map(level => <Token key={level} label={level} size="sm" color="blue" />) : <Text size="sm" color="secondary">Level not set</Text>}
                  </HStack>
                  <MetadataList orientation="horizontal">
                    <MetadataListItem label="TT hours">{`${teacher.ttHours} h / week`}</MetadataListItem>
                    <MetadataListItem label="Subjects">{teacher.subjects || 'Not recorded'}</MetadataListItem>
                    <MetadataListItem label="Classes">{teacher.classes || 'Not confirmed'}</MetadataListItem>
                    <MetadataListItem label="Medium">{teacher.mediums || 'Not confirmed'}</MetadataListItem>
                    {teacher.qualification && <MetadataListItem label="Qualification">{teacher.qualification}</MetadataListItem>}
                  </MetadataList>
                  <HStack gap={2} wrap="wrap">
                    {teacher.dial && <Button label="Call" variant="secondary" size="sm" icon={<Icon icon={PhoneIcon} size="sm" />} href={`tel:${teacher.dial}`} />}
                    {teacher.whatsapp && <Button label="WhatsApp" variant="secondary" size="sm" icon={<Icon icon={ChatBubbleLeftRightIcon} size="sm" />} href={teacher.whatsapp} {...external} />}
                    <Button label="Send profile" variant="primary" size="sm" icon={<Icon icon={ShareIcon} size="sm" />} href={teacher.profileUrl} {...external} />
                    {canClassify && <Button label="Set level & category" variant="ghost" size="sm" onClick={() => setClassifying(teacher)} />}
                  </HStack>
                </VStack>
              </Card>
            ))}
          </Grid>
          {data.meta.total > data.meta.perPage && (
            <Pagination page={data.meta.page} onChange={page => setQuery({page: String(page)}, {resetPage: false})} totalItems={data.meta.total} pageSize={data.meta.perPage} size="sm" label="Teacher pages" />
          )}
        </VStack>
      )}
      <TeacherForms
        unitId={unitId}
        timezone={unit?.timezone ?? 'Asia/Kolkata'}
        kind={classifying ? 'classify' : null}
        teacher={classifying ?? undefined}
        levels={data?.filters.levels.map(l => ({value: l.value, label: l.value})) ?? []}
        categories={data?.filters.categories ?? []}
        onClose={() => setClassifying(null)}
        onSaved={list.reload}
      />
    </Page>
  );
}
