// Account security (global, every signed-in user). Only the 2FA status is read here.
// Enrolment, recovery codes and disabling stay on the classic server-rendered screen:
// the secret and the one-time codes never pass through JSON or this app.
import {VStack} from '@astryxdesign/core/Layout';
import {Card} from '@astryxdesign/core/Card';
import {Heading, Text} from '@astryxdesign/core/Text';
import {Button} from '@astryxdesign/core/Button';
import {MetadataList, MetadataListItem} from '@astryxdesign/core/MetadataList';
import {Page, PageError, PageLoading} from '../../components/PageState';
import {Status} from '../../components/Status';
import {useResource} from '../../lib/hooks';

interface SecurityData {
  name: string;
  email: string;
  twoFactorEnabled: boolean;
  classicUrl: string;
}

export default function Security() {
  const {data, error, isLoading, reload} = useResource<SecurityData>('/app-api/account/security');
  if (isLoading) return <PageLoading />;
  if (error || !data) {
    return (
      <Page title="Account security">
        <PageError error={error ?? new Error('Not found')} onRetry={reload} />
      </Page>
    );
  }
  return (
    <Page title="Account security" description="Protect your sign-in with an authenticator app." width={760}>
      <Card padding={5}>
        <VStack gap={4}>
          <Heading level={2}>Two-factor authentication</Heading>
          <MetadataList label={{position: 'start'}}>
            <MetadataListItem label="Account">{`${data.name} · ${data.email}`}</MetadataListItem>
            <MetadataListItem label="Status">
              <Status value={data.twoFactorEnabled ? 'active' : 'inactive'} label={data.twoFactorEnabled ? 'On' : 'Off'} />
            </MetadataListItem>
          </MetadataList>
          <Text color="secondary">
            {data.twoFactorEnabled
              ? 'Sign-in asks for a code from your authenticator app. To turn it off you need your password and a fresh code or a recovery code.'
              : 'Turn it on so a stolen password alone cannot open your account. You will confirm your password, add the key to your authenticator app and save one-time recovery codes.'}
          </Text>
          <Text color="secondary" size="sm">
            Setup and recovery codes open on the secure account page, so the key and codes are shown only there, once.
          </Text>
          <VStack hAlign="start">
            <Button
              label={data.twoFactorEnabled ? 'Manage two-factor authentication' : 'Turn on two-factor authentication'}
              variant="primary"
              href={data.classicUrl}
            />
          </VStack>
        </VStack>
      </Card>
    </Page>
  );
}
