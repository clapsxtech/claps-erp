// Administrators and staff without a linked teacher record see how to connect a
// teacher's login. Links leave the portal with a
// full page load so the staff app gets its own session.
import {VStack, HStack} from '@astryxdesign/core/Layout';
import {Card} from '@astryxdesign/core/Card';
import {Grid} from '@astryxdesign/core/Grid';
import {Heading, Text} from '@astryxdesign/core/Text';
import {Button} from '@astryxdesign/core/Button';
import {Banner} from '@astryxdesign/core/Banner';
import {List, ListItem} from '@astryxdesign/core/List';
import {leave, signOut, teacherSession} from './shared';

export default function Setup() {
  const sess = teacherSession();
  const isAdmin = Boolean(sess.isAdmin);
  const portalUrl = `${window.location.origin}/teacher`;
  return (
    <VStack hAlign="center">
      <VStack padding={5} gap={6} width="100%" maxWidth={880}>
        <VStack gap={2}>
          <Text color="secondary" size="sm" weight="semibold">Teacher workspace</Text>
          <Heading level={1}>One account. Their teaching day.</Heading>
          <Text color="secondary">Teachers sign in once to see their own timetable, students, attendance, results and reports.</Text>
        </VStack>
        <VStack gap={3}>
          <Heading level={2}>Connect a teacher account</Heading>
          <List listStyle="decimal" density="spacious">
            <ListItem label={isAdmin ? 'Create an active login in Teams & accounts if the teacher does not already have one.' : 'Create an active login in User accounts if the teacher does not already have one.'} />
            <ListItem label="Open the correct team's Team & access and grant that account the Teacher role." />
            <ListItem label="On the teacher's record, explicitly link the teacher to that login account. Create the teacher in Teachers first if needed." />
            <ListItem label={`Assign students and publish dated lessons. Share ${portalUrl} with the teacher.`} />
          </List>
          {!isAdmin && <Banner status="info" title="Steps 1–3 need a site administrator. Ask them to connect the teacher's account." />}
          <Banner status="info" title="Accounts are linked explicitly. No access is inferred from a matching name or email. Unlinking an account removes portal access immediately." />
          {isAdmin && (
            <HStack>
              <Button label="Open Teams & accounts" variant="secondary" onClick={() => leave('/app/admin/teams')} />
            </HStack>
          )}
        </VStack>
        <Grid columns={{minWidth: 240, repeat: 'fill'}} gap={3}>
          {(sess.units ?? []).map(unit => (
            <Card key={unit.id} padding={4}>
              <VStack gap={3}>
                <VStack gap={1}>
                  <Heading level={3}>{unit.name}</Heading>
                  <Text color="secondary">Manage this team's teacher accounts and assignments.</Text>
                </VStack>
                <HStack gap={2} wrap="wrap">
                  {unit.canOpenTeamAccess && <Button label="Open Team access" variant="primary" onClick={() => leave(`/app/u/${unit.id}/team`)} />}
                  {unit.canOpenPeople && <Button label="Teachers" variant="secondary" onClick={() => leave(`/app/u/${unit.id}/teachers`)} />}
                </HStack>
              </VStack>
            </Card>
          ))}
        </Grid>
        <HStack gap={2} wrap="wrap">
          <Button label="Back to the workspace" variant="ghost" onClick={() => leave('/app')} />
          <Button label="Sign out" variant="ghost" onClick={signOut} />
        </HStack>
      </VStack>
    </VStack>
  );
}
