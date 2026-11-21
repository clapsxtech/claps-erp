// Admin-area form drawer and POST helper. The shared FormDrawer covers routes that
// answer JSON; the /erp/administration routes answer with a redirect instead,
// and their forms need password fields, checkbox groups and fields that can be cleared.
// This posts their fields to those routes as JSON, so validation, audit and
// every safeguard stay on the server. A redirect (not followed) means it saved.
import {useEffect, useState, type ReactNode} from 'react';
import {Drawer, DrawerHeader} from '@astryxdesign/core/Drawer';
import {VStack, HStack} from '@astryxdesign/core/Layout';
import {TextInput} from '@astryxdesign/core/TextInput';
import {TextArea} from '@astryxdesign/core/TextArea';
import {Selector} from '@astryxdesign/core/Selector';
import {CheckboxList, CheckboxListItem} from '@astryxdesign/core/CheckboxList';
import {Button} from '@astryxdesign/core/Button';
import {Banner} from '@astryxdesign/core/Banner';
import {AlertDialog} from '@astryxdesign/core/AlertDialog';
import {useToast} from '@astryxdesign/core/Toast';
import {ApiError} from '../../lib/api';

function csrfToken(): string {
  return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
}

/**
 * POST JSON to an /erp/administration route. JSON success bodies ({message}) and redirects both
 * count as saved; errors come back as JSON (422 with field errors, 403, 419, 429).
 */
export async function adminPost(url: string, body: Record<string, unknown>): Promise<{message?: string}> {
  let response: Response;
  try {
    response = await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      redirect: 'manual',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-TOKEN': csrfToken(),
      },
      body: JSON.stringify(body),
    });
  } catch {
    throw new ApiError(0, 'Could not reach the server. Check the result before trying again.');
  }
  if (response.type === 'opaqueredirect' || (response.status >= 300 && response.status < 400)) throw new ApiError(0, 'The server did not confirm this change. Reload to check whether it was saved before trying again.');
  let payload: {message?: string; errors?: Record<string, string[]>} = {};
  try {
    payload = await response.json();
  } catch {
    // Non-JSON body.
  }
  if (response.ok) return payload;
  if (response.status === 401) {
    window.location.assign('/login');
    throw new ApiError(401, 'Your session has ended. Sign in again.');
  }
  const fallback: Record<number, string> = {
    403: 'Your role does not allow this.',
    404: 'That record no longer exists.',
    409: 'Sheets are authoritative right now; this change is blocked.',
    419: 'This page expired. Reload and try again; nothing was saved.',
    422: 'Some details need fixing.',
    429: 'Too many attempts. Wait a minute and try again.',
  };
  throw new ApiError(response.status, payload.message || fallback[response.status] || 'Something went wrong. Nothing was changed.', payload.errors ?? {});
}

export type AdminField =
  | {kind: 'text' | 'email' | 'tel'; name: string; label: string; required?: boolean; description?: string; placeholder?: string; autoComplete?: string}
  | {kind: 'password'; name: string; label: string; required?: boolean; description?: string; autoComplete: 'current-password' | 'new-password'}
  | {kind: 'textarea'; name: string; label: string; required?: boolean; description?: string}
  | {kind: 'select'; name: string; label: string; required?: boolean; description?: string; options: {value: string; label: string}[]}
  | {kind: 'checkboxes'; name: string; label: string; required?: boolean; description?: string; options: {value: string; label: string}[]};

export type AdminValues = Record<string, string | string[]>;

export function AdminFormDrawer({
  isOpen,
  onOpenChange,
  title,
  description,
  fields,
  initial,
  action,
  submitLabel,
  successMessage,
  onSaved,
  nullable = [],
  confirm,
  notice,
  hidden,
}: {
  isOpen: boolean;
  onOpenChange: (open: boolean) => void;
  title: string;
  description?: string;
  fields: AdminField[];
  initial?: AdminValues;
  action: string;
  submitLabel: string;
  successMessage: string;
  onSaved?: () => void;
  /** Fields sent as null when left empty, so a saved value can be cleared. */
  nullable?: string[];
  /** When set, the submit asks for confirmation first. */
  confirm?: {title: string; description: string; actionLabel: string; isDestructive?: boolean};
  notice?: ReactNode;
  /** Fixed values sent with the form, e.g. {archived: 1}. */
  hidden?: Record<string, unknown>;
}) {
  const showToast = useToast();
  const [values, setValues] = useState<AdminValues>(initial ?? {});
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [formError, setFormError] = useState<string>();
  const [saving, setSaving] = useState(false);
  const [confirming, setConfirming] = useState(false);

  useEffect(() => {
    if (isOpen) {
      setValues(initial ?? {});
      setErrors({});
      setFormError(undefined);
      setConfirming(false);
    }
    // Reset only when the drawer opens.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isOpen]);

  const text = (name: string) => {
    const value = values[name];
    return typeof value === 'string' ? value : '';
  };
  const set = (name: string) => (value: string | string[] | undefined) => setValues(current => ({...current, [name]: value ?? ''}));
  const statusFor = (name: string) => {
    const message = errors[name]?.[0] ?? Object.entries(errors).find(([key]) => key.startsWith(`${name}.`))?.[1]?.[0];
    return message ? {type: 'error' as const, message} : undefined;
  };

  async function submit() {
    setConfirming(false);
    setSaving(true);
    setErrors({});
    setFormError(undefined);
    const payload: Record<string, unknown> = {...(hidden ?? {})};
    for (const field of fields) {
      const raw = values[field.name];
      if (Array.isArray(raw)) {
        if (raw.length) payload[field.name] = raw.map(v => (/^\d+$/.test(v) ? Number(v) : v));
        continue;
      }
      // Passwords are sent exactly as typed.
      const value = field.kind === 'password' ? raw ?? '' : (raw ?? '').trim();
      if (value !== '') payload[field.name] = value;
      else if (nullable.includes(field.name)) payload[field.name] = null;
    }
    try {
      const result = await adminPost(action, payload);
      showToast({body: result.message ?? successMessage, uniqueID: `saved-${action}`});
      onOpenChange(false);
      onSaved?.();
    } catch (reason) {
      if (reason instanceof ApiError) {
        setErrors(reason.fieldErrors);
        setFormError(Object.keys(reason.fieldErrors).length ? 'Fix the highlighted fields. Nothing was saved.' : reason.message);
      } else {
        setFormError('Could not reach the server. Check the result before trying again.');
      }
    } finally {
      setSaving(false);
    }
  }

  return (
    <Drawer isOpen={isOpen} onOpenChange={onOpenChange} label={title} width={520} purpose="form" padding={5} experimental={{isFullWidthOnMobile: true}}>
      <VStack gap={5}>
        <DrawerHeader title={title} subtitle={description} onOpenChange={onOpenChange} />
        {formError && <Banner status="error" title={formError} />}
        {notice}
        <VStack gap={4}>
          {fields.map(field => {
            const common = {label: field.label, isRequired: field.required, isOptional: !field.required, description: field.description, status: statusFor(field.name)};
            switch (field.kind) {
              case 'select':
                return field.required ? (
                  <Selector
                    key={field.name}
                    {...common}
                    options={field.options}
                    value={text(field.name) || undefined}
                    onChange={(value: string | undefined) => set(field.name)(value ?? '')}
                    hasSearch={field.options.length > 8}
                  />
                ) : (
                  <Selector
                    key={field.name}
                    {...common}
                    options={field.options}
                    value={text(field.name) || null}
                    onChange={(value: string | null) => set(field.name)(value ?? '')}
                    hasSearch={field.options.length > 8}
                    hasClear
                  />
                );
              case 'checkboxes': {
                const selected = values[field.name];
                return (
                  <CheckboxList
                    key={field.name}
                    label={field.label}
                    description={field.description}
                    isRequired={field.required}
                    status={statusFor(field.name)}
                    value={Array.isArray(selected) ? selected : []}
                    onChange={next => set(field.name)(next)}>
                    {field.options.map(option => (
                      <CheckboxListItem key={option.value} value={option.value} label={option.label} />
                    ))}
                  </CheckboxList>
                );
              }
              case 'textarea':
                return <TextArea key={field.name} {...common} value={text(field.name)} onChange={set(field.name)} />;
              case 'password':
                return <TextInput key={field.name} {...common} type="password" autoComplete={field.autoComplete} value={text(field.name)} onChange={set(field.name)} />;
              default:
                return (
                  <TextInput
                    key={field.name}
                    {...common}
                    type={field.kind}
                    value={text(field.name)}
                    onChange={set(field.name)}
                    placeholder={field.placeholder}
                    autoComplete={field.autoComplete}
                    inputMode={field.kind === 'tel' ? 'numeric' : undefined}
                  />
                );
            }
          })}
        </VStack>
        <HStack gap={2} hAlign="end">
          <Button label="Cancel" variant="ghost" onClick={() => onOpenChange(false)} />
          <Button
            label={submitLabel}
            variant={confirm?.isDestructive ? 'destructive' : 'primary'}
            isLoading={saving}
            onClick={() => (confirm ? setConfirming(true) : submit())}
          />
        </HStack>
      </VStack>
      {confirm && (
        <AlertDialog
          isOpen={confirming}
          onOpenChange={setConfirming}
          title={confirm.title}
          description={confirm.description}
          actionLabel={confirm.actionLabel}
          actionVariant={confirm.isDestructive ? 'destructive' : 'primary'}
          onAction={submit}
        />
      )}
    </Drawer>
  );
}

/** A confirmed one-click write (archive, restore, revoke): AlertDialog, then POST. */
export function ConfirmAction({
  isOpen,
  onOpenChange,
  title,
  description,
  actionLabel,
  action,
  body,
  successMessage,
  onDone,
  isDestructive = true,
}: {
  isOpen: boolean;
  onOpenChange: (open: boolean) => void;
  title: string;
  description: string;
  actionLabel: string;
  action: string;
  body: Record<string, unknown>;
  successMessage: string;
  onDone?: () => void;
  isDestructive?: boolean;
}) {
  const showToast = useToast();
  const [busy, setBusy] = useState(false);
  async function run() {
    setBusy(true);
    try {
      const result = await adminPost(action, body);
      showToast({body: result.message ?? successMessage, uniqueID: `done-${action}`});
      onOpenChange(false);
      onDone?.();
    } catch (reason) {
      const message = reason instanceof ApiError ? Object.values(reason.fieldErrors)[0]?.[0] ?? reason.message : 'Could not reach the server.';
      showToast({body: message, type: 'error', uniqueID: `failed-${action}`});
      onOpenChange(false);
    } finally {
      setBusy(false);
    }
  }
  return (
    <AlertDialog
      isOpen={isOpen}
      onOpenChange={onOpenChange}
      title={title}
      description={description}
      actionLabel={actionLabel}
      actionVariant={isDestructive ? 'destructive' : 'primary'}
      isActionLoading={busy}
      onAction={run}
    />
  );
}
