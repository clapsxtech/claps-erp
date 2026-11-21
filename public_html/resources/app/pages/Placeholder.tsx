// Screens not yet rebuilt open their classic version, so nothing is unreachable
// while modules move over one by one.
import {useParams} from 'react-router';
import {EmptyState} from '@astryxdesign/core/EmptyState';
import {Button} from '@astryxdesign/core/Button';
import {GROUPS} from '../nav';
import {Page} from '../components/PageState';

const CLASSIC: Record<string, string> = {
  teachers: 'people',
  admissions: 'admissions',
  followups: 'followups',
  recruitment: 'teacher-recruitment',
  timetable: 'timetable',
  attendance: 'attendance',
  matching: 'allocation',
  academics: 'academics',
  payments: 'money',
  accounts: 'accounts',
  insights: 'insights',
  exports: 'migration',
  tasks: '',
};

export default function Placeholder() {
  const {unit, section = ''} = useParams();
  const item = GROUPS.flatMap(group => group.items).find(entry => entry.path === section);
  const classicPage = CLASSIC[section];
  const classicHref = section === 'tasks' ? '/erp/my-tasks' : `/erp/units/${unit}/business/${classicPage ?? 'dashboard'}`;
  return (
    <Page title={item?.label ?? 'Coming soon'}>
      <EmptyState
        title="This screen is being rebuilt"
        description="Use the classic screen for now. Everything you can do there still works."
        actions={<Button label="Open the classic screen" href={classicHref} variant="primary" />}
      />
    </Page>
  );
}
