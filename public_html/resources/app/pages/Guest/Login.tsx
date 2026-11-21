import {useState, type FormEvent} from 'react';
import {useLocation} from 'react-router';
import {VStack, HStack} from '@astryxdesign/core/Layout';
import {TextInput} from '@astryxdesign/core/TextInput';
import {CheckboxInput} from '@astryxdesign/core/CheckboxInput';
import {Button} from '@astryxdesign/core/Button';
import {Banner} from '@astryxdesign/core/Banner';
import {Link} from '@astryxdesign/core/Link';
import {Text} from '@astryxdesign/core/Text';
import {GuestLayout} from './GuestLayout';
import {bannerMessage, fieldStatus, guestPost} from './guestApi';
import {guestSession} from './session';

type Portal = 'staff' | 'superadmin' | null;

function portalFor(path: string): Portal {
  if (path.startsWith('/superadmin/')) return 'superadmin';
  if (path.startsWith('/staff/')) return 'staff';
  return null;
}

export default function Login() {
  const portal = portalFor(useLocation().pathname);
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [remember, setRemember] = useState(false);
  const [errors, setErrors] = useState<Record<string, string[]>>();
  const [banner, setBanner] = useState<string>();
  const [busy, setBusy] = useState(false);
  const [notice] = useState(() => guestSession().status);

  async function submit(event: FormEvent) {
    event.preventDefault();
    setBusy(true);
    setErrors(undefined);
    setBanner(undefined);
    const result = await guestPost<{redirect?: string}>('/login', {email, password, remember, ...(portal ? {portal} : {})});
    if (result.body.redirect && (result.ok || result.status === 302)) {
      // Full load: the session and CSRF token rotated on sign-in.
      window.location.assign(result.body.redirect);
      return;
    }
    setBusy(false);
    setPassword('');
    setErrors(result.body.errors);
    setBanner(bannerMessage(result, ['email', 'password']));
  }

  const title = portal === 'superadmin' ? 'Superadmin login' : portal === 'staff' ? 'Staff login' : 'Sign in';
  const description = portal
    ? 'Superadmins manage every team. Staff see only the teams they are assigned to.'
    : 'Teachers and staff sign in with the account from their administrator.';

  return (
    <GuestLayout
      title={title}
      description={description}
      footer={
        <>
          <Link href="/superadmin/login">Superadmin login</Link>
          <Text color="secondary">·</Text>
          <Link href="/staff/login">Staff login</Link>
        </>
      }
    >
      <form onSubmit={submit} noValidate>
        <VStack gap={4}>
          {notice && <Banner status="success" title={notice} />}
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
            hasAutoFocus
            isRequired
            htmlName="email"
            status={fieldStatus(errors, 'email')}
          />
          <TextInput
            label="Password"
            type="password"
            value={password}
            onChange={setPassword}
            autoComplete="current-password"
            isRequired
            htmlName="password"
            status={fieldStatus(errors, 'password')}
          />
          <HStack gap={3} vAlign="center" hAlign="between" wrap="wrap">
            <CheckboxInput label="Keep me signed in" value={remember} onChange={setRemember} htmlName="remember" />
            <Link href="/password/reset">Forgot your password?</Link>
          </HStack>
          <Button label="Sign in" type="submit" variant="primary" size="lg" width="100%" isLoading={busy} />
        </VStack>
      </form>
    </GuestLayout>
  );
}
