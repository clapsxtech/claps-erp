import {useState, type FormEvent} from 'react';
import {VStack} from '@astryxdesign/core/Layout';
import {TextInput} from '@astryxdesign/core/TextInput';
import {Button} from '@astryxdesign/core/Button';
import {Banner} from '@astryxdesign/core/Banner';
import {Link} from '@astryxdesign/core/Link';
import {GuestLayout} from './GuestLayout';
import {bannerMessage, fieldStatus, guestPost} from './guestApi';

export default function TwoFactorChallenge() {
  const [code, setCode] = useState('');
  const [useRecovery, setUseRecovery] = useState(false);
  const [errors, setErrors] = useState<Record<string, string[]>>();
  const [banner, setBanner] = useState<string>();
  const [busy, setBusy] = useState(false);

  async function submit(event: FormEvent) {
    event.preventDefault();
    setBusy(true);
    setErrors(undefined);
    setBanner(undefined);
    const result = await guestPost<{redirect?: string}>('/two-factor', {code: code.trim()});
    if (result.ok && result.body.redirect) {
      window.location.assign(result.body.redirect);
      return;
    }
    setBusy(false);
    setCode('');
    setErrors(result.body.errors);
    setBanner(bannerMessage(result, ['code']));
  }

  return (
    <GuestLayout
      title="Verify sign-in"
      description={
        useRecovery
          ? 'Enter one of the recovery codes you saved when you turned on two-factor authentication. Each code works once.'
          : 'Enter the 6-digit code from your authenticator app.'
      }
      footer={<Link href="/login">Start sign-in again</Link>}
    >
      <form onSubmit={submit} noValidate>
        <VStack gap={4}>
          {banner && <Banner status="error" title={banner} />}
          <TextInput
            key={useRecovery ? 'recovery' : 'totp'}
            label={useRecovery ? 'Recovery code' : 'Authenticator code'}
            value={code}
            onChange={setCode}
            autoComplete="one-time-code"
            inputMode={useRecovery ? 'text' : 'numeric'}
            autoCapitalize="none"
            hasAutoCorrect={false}
            hasSpellCheck={false}
            hasAutoFocus
            isRequired
            htmlName="code"
            status={fieldStatus(errors, 'code')}
          />
          <Button label="Verify and sign in" type="submit" variant="primary" size="lg" width="100%" isLoading={busy} />
          <Button
            label={useRecovery ? 'Use an authenticator code instead' : 'Use a recovery code instead'}
            variant="ghost"
            width="100%"
            onClick={() => {
              setUseRecovery(value => !value);
              setCode('');
              setErrors(undefined);
            }}
          />
        </VStack>
      </form>
    </GuestLayout>
  );
}
