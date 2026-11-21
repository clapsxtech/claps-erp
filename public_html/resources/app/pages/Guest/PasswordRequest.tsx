import {useState, type FormEvent} from 'react';
import {useSearchParams} from 'react-router';
import {VStack} from '@astryxdesign/core/Layout';
import {TextInput} from '@astryxdesign/core/TextInput';
import {Button} from '@astryxdesign/core/Button';
import {Banner} from '@astryxdesign/core/Banner';
import {Link} from '@astryxdesign/core/Link';
import {GuestLayout} from './GuestLayout';
import {bannerMessage, fieldStatus, guestPost} from './guestApi';

export default function PasswordRequest() {
  const [params] = useSearchParams();
  const [email, setEmail] = useState(params.get('email') ?? '');
  const [errors, setErrors] = useState<Record<string, string[]>>();
  const [banner, setBanner] = useState<string>();
  const [sent, setSent] = useState<string>();
  const [busy, setBusy] = useState(false);

  async function submit(event: FormEvent) {
    event.preventDefault();
    setBusy(true);
    setErrors(undefined);
    setBanner(undefined);
    const result = await guestPost('/password/email', {email});
    setBusy(false);
    if (result.ok) {
      setSent(result.body.message ?? 'We have emailed your password reset link.');
      return;
    }
    setErrors(result.body.errors);
    setBanner(bannerMessage(result, ['email']));
  }

  return (
    <GuestLayout
      title="Reset password"
      description="Enter the email address of your account and we will send you a link to choose a new password."
      footer={<Link href="/login">Back to sign in</Link>}
    >
      {sent ? (
        <VStack gap={4}>
          <Banner status="success" title={sent} description="The link works for a limited time. Check your spam folder if it does not arrive." />
          <Button label="Back to sign in" href="/login" variant="secondary" width="100%" />
        </VStack>
      ) : (
        <form onSubmit={submit} noValidate>
          <VStack gap={4}>
            {banner && <Banner status="error" title={banner} />}
            <TextInput
              label="Email address"
              type="email"
              value={email}
              onChange={setEmail}
              autoComplete="email"
              inputMode="email"
              autoCapitalize="none"
              hasAutoCorrect={false}
              hasAutoFocus
              isRequired
              htmlName="email"
              status={fieldStatus(errors, 'email')}
            />
            <Button label="Send password reset link" type="submit" variant="primary" size="lg" width="100%" isLoading={busy} />
          </VStack>
        </form>
      )}
    </GuestLayout>
  );
}
