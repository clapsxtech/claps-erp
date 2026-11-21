import {useState, type FormEvent} from 'react';
import {useParams, useSearchParams} from 'react-router';
import {VStack} from '@astryxdesign/core/Layout';
import {TextInput} from '@astryxdesign/core/TextInput';
import {Button} from '@astryxdesign/core/Button';
import {Banner} from '@astryxdesign/core/Banner';
import {Link} from '@astryxdesign/core/Link';
import {GuestLayout} from './GuestLayout';
import {bannerMessage, fieldStatus, guestPost} from './guestApi';

export default function PasswordReset() {
  const {token = ''} = useParams();
  const [params] = useSearchParams();
  const [email, setEmail] = useState(params.get('email') ?? '');
  const [password, setPassword] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const [errors, setErrors] = useState<Record<string, string[]>>();
  const [banner, setBanner] = useState<string>();
  const [done, setDone] = useState<string>();
  const [busy, setBusy] = useState(false);

  async function submit(event: FormEvent) {
    event.preventDefault();
    setBusy(true);
    setErrors(undefined);
    setBanner(undefined);
    const result = await guestPost('/password/reset', {token, email, password, password_confirmation: confirmation});
    setBusy(false);
    if (result.ok) {
      setDone(result.body.message ?? 'Your password has been reset.');
      // The reset signs the account in; a full load picks up the new session.
      window.setTimeout(() => window.location.assign('/login'), 1200);
      return;
    }
    setPassword('');
    setConfirmation('');
    setErrors(result.body.errors);
    setBanner(bannerMessage(result, ['email', 'password']));
  }

  return (
    <GuestLayout
      title="Choose a new password"
      description="Use at least 8 characters. Do not reuse a password from another site."
      footer={<Link href="/password/reset">Request a new link</Link>}
    >
      {done ? (
        <Banner status="success" title={done} description="Taking you to your workspace…" />
      ) : (
        <form onSubmit={submit} noValidate>
          <VStack gap={4}>
            {banner && <Banner status="error" title={banner} />}
            <TextInput
              label="Email address"
              type="email"
              value={email}
              onChange={setEmail}
              autoComplete="username"
              inputMode="email"
              autoCapitalize="none"
              hasAutoCorrect={false}
              hasAutoFocus={!email}
              isRequired
              htmlName="email"
              status={fieldStatus(errors, 'email')}
            />
            <TextInput
              label="New password"
              type="password"
              value={password}
              onChange={setPassword}
              autoComplete="new-password"
              hasAutoFocus={Boolean(email)}
              isRequired
              htmlName="password"
              status={fieldStatus(errors, 'password')}
            />
            <TextInput
              label="Confirm new password"
              type="password"
              value={confirmation}
              onChange={setConfirmation}
              autoComplete="new-password"
              isRequired
              htmlName="password_confirmation"
            />
            <Button label="Reset password" type="submit" variant="primary" size="lg" width="100%" isLoading={busy} />
          </VStack>
        </form>
      )}
    </GuestLayout>
  );
}
