import {useState, type FormEvent} from 'react';
import {VStack} from '@astryxdesign/core/Layout';
import {TextInput} from '@astryxdesign/core/TextInput';
import {Button} from '@astryxdesign/core/Button';
import {Banner} from '@astryxdesign/core/Banner';
import {Link} from '@astryxdesign/core/Link';
import {GuestLayout} from './GuestLayout';
import {bannerMessage, fieldStatus, guestPost} from './guestApi';
import {guestSession} from './session';

export default function PasswordConfirm() {
  const [password, setPassword] = useState('');
  const [errors, setErrors] = useState<Record<string, string[]>>();
  const [banner, setBanner] = useState<string>();
  const [busy, setBusy] = useState(false);

  async function submit(event: FormEvent) {
    event.preventDefault();
    setBusy(true);
    setErrors(undefined);
    setBanner(undefined);
    const result = await guestPost('/password/confirm', {password});
    if (result.ok) {
      window.location.assign(guestSession().intended || '/');
      return;
    }
    setBusy(false);
    setPassword('');
    setErrors(result.body.errors);
    setBanner(bannerMessage(result, ['password']));
  }

  return (
    <GuestLayout
      title="Confirm your password"
      description="Please confirm your password before continuing."
      footer={<Link href="/password/reset">Forgot your password?</Link>}
    >
      <form onSubmit={submit} noValidate>
        <VStack gap={4}>
          {banner && <Banner status="error" title={banner} />}
          <TextInput
            label="Password"
            type="password"
            value={password}
            onChange={setPassword}
            autoComplete="current-password"
            hasAutoFocus
            isRequired
            htmlName="password"
            status={fieldStatus(errors, 'password')}
          />
          <Button label="Confirm password" type="submit" variant="primary" size="lg" width="100%" isLoading={busy} />
        </VStack>
      </form>
    </GuestLayout>
  );
}
