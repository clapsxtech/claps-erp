// Two-factor authentication management, shared by /app/account/security (staff
// shell) and /account/security (guest frame). The status comes from a GET that
// never carries secrets; the setup key and the recovery codes exist only in the
// responses to the POSTs that create them and in this component's memory, so
// they are shown once and gone on reload.
import {useState, type FormEvent} from 'react';
import {VStack, HStack} from '@astryxdesign/core/Layout';
import {Card} from '@astryxdesign/core/Card';
import {Heading, Text} from '@astryxdesign/core/Text';
import {Button} from '@astryxdesign/core/Button';
import {Banner} from '@astryxdesign/core/Banner';
import {TextInput} from '@astryxdesign/core/TextInput';
import {MetadataList, MetadataListItem} from '@astryxdesign/core/MetadataList';
import {List, ListItem} from '@astryxdesign/core/List';
import {useClipboard} from '@astryxdesign/core/hooks';
import {PageError, PageLoading} from '../../components/PageState';
import {Status} from '../../components/Status';
import {useResource} from '../../lib/hooks';
import {bannerMessage, fieldStatus, guestPost} from './guestApi';
import {QrCode} from './QrCode';

interface TwoFactorStatus {
  name: string;
  email: string;
  twoFactorEnabled: boolean;
  recoveryCodesLeft: number | null;
}

interface SetupResult {
  secret: string;
  otpauthUri: string;
  issuer: string;
  account: string;
}

type Errors = Record<string, string[]> | undefined;

function groupSecret(secret: string): string {
  return secret.replace(/(.{4})/g, '$1 ').trim();
}

/** `isBare` drops the card when the surrounding frame is already one (the guest layout). */
export function SecurityPanel({isBare = false}: {isBare?: boolean}) {
  const {data, error, isLoading, reload} = useResource<TwoFactorStatus>('/app-api/account/two-factor');
  const [setup, setSetup] = useState<SetupResult>();
  const [codes, setCodes] = useState<string[]>();
  const [notice, setNotice] = useState<string>();

  if (isLoading) return <PageLoading />;
  if (error || !data) return <PageError error={error ?? new Error('Not found')} onRetry={reload} />;

  const body = (
    <VStack gap={4}>
      <Heading level={2}>Two-factor authentication</Heading>
      <MetadataList label={{position: 'start'}}>
        <MetadataListItem label="Account">{`${data.name} · ${data.email}`}</MetadataListItem>
        <MetadataListItem label="Status">
          <Status value={data.twoFactorEnabled ? 'active' : 'inactive'} label={data.twoFactorEnabled ? 'On' : 'Off'} />
        </MetadataListItem>
        {data.twoFactorEnabled && data.recoveryCodesLeft !== null && (
          <MetadataListItem label="Recovery codes left">{String(data.recoveryCodesLeft)}</MetadataListItem>
        )}
      </MetadataList>
      {codes ? (
        <RecoveryCodes
          codes={codes}
          onDone={() => {
            setCodes(undefined);
            reload();
          }}
        />
      ) : data.twoFactorEnabled ? (
        <DisableForm
          onDisabled={message => {
            setNotice(message);
            reload();
          }}
        />
      ) : setup ? (
        <EnableForm
          setup={setup}
          onCancel={() => setSetup(undefined)}
          onEnabled={(recovery, message) => {
            setSetup(undefined);
            setCodes(recovery);
            setNotice(message);
            reload(); // Status only; the codes stay in this component's memory.
          }}
        />
      ) : (
        <StartForm onStarted={setSetup} />
      )}
    </VStack>
  );
  return (
    <VStack gap={5}>
      {notice && <Banner status="success" title={notice} isDismissable onDismiss={() => setNotice(undefined)} />}
      {isBare ? body : <Card padding={5}>{body}</Card>}
    </VStack>
  );
}

function StartForm({onStarted}: {onStarted: (setup: SetupResult) => void}) {
  const [password, setPassword] = useState('');
  const [errors, setErrors] = useState<Errors>();
  const [banner, setBanner] = useState<string>();
  const [busy, setBusy] = useState(false);

  async function submit(event: FormEvent) {
    event.preventDefault();
    setBusy(true);
    setErrors(undefined);
    setBanner(undefined);
    const result = await guestPost<SetupResult>('/account/two-factor/setup', {current_password: password});
    setBusy(false);
    setPassword('');
    if (result.ok && result.body.secret) {
      onStarted(result.body);
      return;
    }
    setErrors(result.body.errors);
    setBanner(bannerMessage(result, ['current_password']));
  }

  return (
    <form onSubmit={submit} noValidate>
      <VStack gap={4}>
        <Text color="secondary">
          Turn it on so a stolen password alone cannot open your account. You will add a key to an authenticator app (Google Authenticator,
          Microsoft Authenticator, 1Password and similar) and save one-time recovery codes.
        </Text>
        {banner && <Banner status="error" title={banner} />}
        <TextInput
          label="Your current password"
          type="password"
          value={password}
          onChange={setPassword}
          autoComplete="current-password"
          isRequired
          htmlName="current_password"
          status={fieldStatus(errors, 'current_password')}
          width="100%"
        />
        <HStack>
          <Button label="Start authenticator setup" type="submit" variant="primary" isLoading={busy} />
        </HStack>
      </VStack>
    </form>
  );
}

function EnableForm({
  setup,
  onCancel,
  onEnabled,
}: {
  setup: SetupResult;
  onCancel: () => void;
  onEnabled: (codes: string[], message: string) => void;
}) {
  const [password, setPassword] = useState('');
  const [code, setCode] = useState('');
  const [errors, setErrors] = useState<Errors>();
  const [banner, setBanner] = useState<string>();
  const [busy, setBusy] = useState(false);
  const {copy, isCopied} = useClipboard({announce: 'Setup key copied'});

  async function submit(event: FormEvent) {
    event.preventDefault();
    setBusy(true);
    setErrors(undefined);
    setBanner(undefined);
    const result = await guestPost<{recoveryCodes?: string[]}>('/account/two-factor/enable', {
      current_password: password,
      code: code.trim(),
    });
    setBusy(false);
    if (result.ok && result.body.recoveryCodes) {
      onEnabled(result.body.recoveryCodes, result.body.message ?? 'Two-factor authentication enabled. Save your recovery codes now.');
      return;
    }
    setPassword('');
    setCode('');
    setErrors(result.body.errors);
    setBanner(bannerMessage(result, ['current_password', 'code']));
  }

  return (
    <form onSubmit={submit} noValidate>
      <VStack gap={4}>
        <Heading level={3}>1. Add ClapsLearn to your authenticator</Heading>
        <Text color="secondary">Scan the code with your authenticator app. On this phone, tap “Open in authenticator app” instead.</Text>
        <HStack gap={5} wrap="wrap" vAlign="center">
          <QrCode text={setup.otpauthUri} size={184} label="QR code for your authenticator app" />
          <VStack gap={2}>
            <Text color="secondary" size="sm">
              Or enter this key manually (time-based, 6 digits, 30 seconds, SHA-1):
            </Text>
            <Text type="code" weight="semibold" wordBreak="break-all">
              {groupSecret(setup.secret)}
            </Text>
            <HStack gap={2} wrap="wrap">
              <Button label={isCopied ? 'Copied' : 'Copy key'} size="sm" onClick={() => void copy(setup.secret)} />
              <Button label="Open in authenticator app" size="sm" variant="ghost" href={setup.otpauthUri} />
            </HStack>
            <Text color="secondary" size="sm">{`Account: ${setup.issuer} (${setup.account})`}</Text>
          </VStack>
        </HStack>
        <Heading level={3}>2. Confirm with a code</Heading>
        <Text color="secondary" size="sm">
          This key is shown only now. Finish within 10 minutes or start again.
        </Text>
        {banner && <Banner status="error" title={banner} />}
        <TextInput
          label="Current six-digit code"
          value={code}
          onChange={setCode}
          autoComplete="one-time-code"
          inputMode="numeric"
          isRequired
          htmlName="code"
          status={fieldStatus(errors, 'code')}
          width="100%"
        />
        <TextInput
          label="Your current password"
          type="password"
          value={password}
          onChange={setPassword}
          autoComplete="current-password"
          isRequired
          htmlName="current_password"
          status={fieldStatus(errors, 'current_password')}
          width="100%"
        />
        <HStack gap={2} wrap="wrap">
          <Button label="Turn on and show recovery codes" type="submit" variant="primary" isLoading={busy} />
          <Button label="Cancel" variant="ghost" onClick={onCancel} />
        </HStack>
      </VStack>
    </form>
  );
}

function RecoveryCodes({codes, onDone}: {codes: string[]; onDone: () => void}) {
  const {copy, isCopied} = useClipboard({announce: 'Recovery codes copied'});
  const text = codes.join('\n');
  const download = () => {
    const url = URL.createObjectURL(new Blob([`ClapsLearn recovery codes\nEach code works once.\n\n${text}\n`], {type: 'text/plain'}));
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = 'clapslearn-recovery-codes.txt';
    document.body.appendChild(anchor);
    anchor.click();
    anchor.remove();
    window.setTimeout(() => URL.revokeObjectURL(url), 1000);
  };
  return (
    <VStack gap={4}>
      <Banner
        status="warning"
        title="Save these recovery codes now"
        description="Each code signs you in once if you lose your phone. They are shown only this once; store them somewhere private before leaving this page."
      />
      <List density="compact" hasDividers listStyle="decimal">
        {codes.map(code => (
          <ListItem key={code} label={<Text type="code">{code}</Text>} />
        ))}
      </List>
      <HStack gap={2} wrap="wrap">
        <Button label={isCopied ? 'Copied' : 'Copy codes'} onClick={() => void copy(text)} />
        <Button label="Download .txt" onClick={download} />
        <Button label="I have saved them" variant="primary" onClick={onDone} />
      </HStack>
    </VStack>
  );
}

function DisableForm({onDisabled}: {onDisabled: (message: string) => void}) {
  const [password, setPassword] = useState('');
  const [code, setCode] = useState('');
  const [errors, setErrors] = useState<Errors>();
  const [banner, setBanner] = useState<string>();
  const [busy, setBusy] = useState(false);

  async function submit(event: FormEvent) {
    event.preventDefault();
    if (!window.confirm('Turn off two-factor authentication? Your password alone will open this account.')) return;
    setBusy(true);
    setErrors(undefined);
    setBanner(undefined);
    const result = await guestPost('/account/two-factor/disable', {current_password: password, code: code.trim()});
    setBusy(false);
    setPassword('');
    setCode('');
    if (result.ok) {
      onDisabled(result.body.message ?? 'Two-factor authentication disabled.');
      return;
    }
    setErrors(result.body.errors);
    setBanner(bannerMessage(result, ['current_password', 'code']));
  }

  return (
    <form onSubmit={submit} noValidate>
      <VStack gap={4}>
        <Text color="secondary">
          Sign-in asks for a code from your authenticator app. If you lose your phone, use a recovery code. To turn it off, confirm your
          password and a fresh authenticator or recovery code.
        </Text>
        {banner && <Banner status="error" title={banner} />}
        <TextInput
          label="Your current password"
          type="password"
          value={password}
          onChange={setPassword}
          autoComplete="current-password"
          isRequired
          htmlName="current_password"
          status={fieldStatus(errors, 'current_password')}
          width="100%"
        />
        <TextInput
          label="Fresh authenticator or recovery code"
          value={code}
          onChange={setCode}
          autoComplete="one-time-code"
          autoCapitalize="none"
          hasAutoCorrect={false}
          isRequired
          htmlName="code"
          status={fieldStatus(errors, 'code')}
          width="100%"
        />
        <HStack>
          <Button label="Turn off two-factor authentication" type="submit" variant="destructive" isLoading={busy} />
        </HStack>
      </VStack>
    </form>
  );
}
