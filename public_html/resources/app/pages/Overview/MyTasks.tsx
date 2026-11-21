// /app/my-tasks: your own tasks in every team. It is the whole app for staff whose
// only membership is a legacy role ("sales", "teacher") with no team screens, so
// the session lists no teams for them.
import {useState} from 'react';
import {SegmentedControl, SegmentedControlItem} from '@astryxdesign/core/SegmentedControl';
import {Page} from '../../components/PageState';
import {OwnTasks} from './OwnTasks';

export default function MyTasks() {
  const [status, setStatus] = useState<'open' | 'all'>('open');
  return (
    <Page title="My tasks" description="Tasks assigned to you in all your teams">
      <SegmentedControl label="Which tasks" value={status} onChange={value => setStatus(value === 'all' ? 'all' : 'open')}>
        <SegmentedControlItem value="open" label="Open" />
        <SegmentedControlItem value="all" label="All" />
      </SegmentedControl>
      <OwnTasks status={status} emptyDescription="Tasks your team assigns to you appear here. Ask an administrator if you expected to see a team." />
    </Page>
  );
}
