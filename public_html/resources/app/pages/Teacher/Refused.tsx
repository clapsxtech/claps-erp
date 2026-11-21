// A teacher login that is not fully connected yet (unlinked, inactive, wrong
// team pool, archived team or missing Teacher role). The server answered 403;
// this says why and what to ask for.
import {VStack, HStack} from '@astryxdesign/core/Layout';
import {EmptyState} from '@astryxdesign/core/EmptyState';
import {Button} from '@astryxdesign/core/Button';
import {OwnTasks} from '../Overview/OwnTasks';
import {SubHeading, leave, signOut, teacherSession} from './shared';

export default function Refused() {
  const sess = teacherSession();
  return (
    <VStack padding={5} gap={5} hAlign="center">
      <EmptyState
        headingLevel={1}
        title="Your teacher account isn't ready"
        description={`${sess.message ?? 'Teacher access is not set up for this account.'} Ask an administrator to connect your login to your teacher record.`}
        actions={
          <HStack gap={2} wrap="wrap" hAlign="center">
            <Button label="Account security" variant="secondary" onClick={() => leave('/account/security')} />
            <Button label="Sign out" variant="ghost" onClick={signOut} />
          </HStack>
        }
      />
      {/* Tasks do not need the teacher link: any current team member can see and complete their own. */}
      <VStack gap={3} width="100%" maxWidth={720}>
        <SubHeading title="My tasks" />
        <OwnTasks status="open" emptyDescription="Tasks your team assigns to you appear here." />
      </VStack>
    </VStack>
  );
}
