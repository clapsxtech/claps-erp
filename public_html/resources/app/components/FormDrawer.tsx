// One pattern for every create/edit action: a side drawer (full screen on
// phones), labelled fields with sensible defaults, inline errors from the
// server, and a single primary button. It posts JSON to the existing Laravel
// action routes, so validation, permissions, audit and money rules all stay
// on the server.
import {useEffect, useState, type ReactNode} from 'react';
import {Drawer} from '@astryxdesign/core/Drawer';
import {DrawerHeader} from '@astryxdesign/core/Drawer';
import {VStack, HStack} from '@astryxdesign/core/Layout';
import {TextInput} from '@astryxdesign/core/TextInput';
import {TextArea} from '@astryxdesign/core/TextArea';
import {DateInput} from '@astryxdesign/core/DateInput';
import {Selector} from '@astryxdesign/core/Selector';
import {Button} from '@astryxdesign/core/Button';
import {Banner} from '@astryxdesign/core/Banner';
import {useToast} from '@astryxdesign/core/Toast';
import {ApiError, postJson} from '../lib/api';

type ISODate = `${number}${number}${number}${number}-${number}${number}-${number}${number}`;

export type Field =
  | {kind: 'text' | 'tel' | 'url' | 'email'; name: string; label: string; required?: boolean; description?: string; placeholder?: string}
  | {kind: 'money'; name: string; label: string; required?: boolean; description?: string}
  | {kind: 'textarea'; name: string; label: string; required?: boolean; description?: string}
  | {kind: 'date'; name: string; label: string; required?: boolean; max?: string; min?: string; description?: string}
  | {kind: 'select'; name: string; label: string; required?: boolean; options: {value: string; label: string}[]; description?: string};

export function FormDrawer({
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
  extra,
}: {
  isOpen: boolean;
  onOpenChange: (open: boolean) => void;
  title: string;
  description?: string;
  fields: Field[];
  initial?: Record<string, string>;
  /** Laravel action URL, e.g. /erp/units/3/student */
  action: string;
  submitLabel: string;
  successMessage: string;
  onSaved?: (result: unknown) => void;
  extra?: ReactNode;
}) {
  const showToast = useToast();
  const [values, setValues] = useState<Record<string, string>>(initial ?? {});
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [formError, setFormError] = useState<string>();
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (isOpen) {
      setValues(initial ?? {});
      setErrors({});
      setFormError(undefined);
    }
    // Reset only when the drawer opens.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isOpen]);

  const set = (name: string) => (value: string | undefined) => setValues(current => ({...current, [name]: value ?? ''}));
  const statusFor = (name: string) => (errors[name]?.length ? {type: 'error' as const, message: errors[name][0]} : undefined);

  async function submit() {
    setSaving(true);
    setErrors({});
    setFormError(undefined);
    const payload: Record<string, unknown> = {};
    for (const field of fields) {
      const raw = (values[field.name] ?? '').trim();
      if (raw !== '') payload[field.name] = raw;
    }
    try {
      const result = await postJson<{result: unknown; message?: string}>(action, payload);
      showToast({body: result.message ?? successMessage, uniqueID: `saved-${action}`});
      onOpenChange(false);
      onSaved?.(result.result);
    } catch (reason) {
      if (reason instanceof ApiError) {
        // Errors for a field on the form show beside it; any other error (for
        // example an amount over what is owed, keyed amount_paise) shows in the banner.
        const names = new Set(fields.map(field => field.name));
        const placed: Record<string, string[]> = {};
        const unplaced: string[] = [];
        for (const [key, messages] of Object.entries(reason.fieldErrors)) {
          if (names.has(key)) placed[key] = messages;
          else unplaced.push(...messages);
        }
        setErrors(placed);
        setFormError(
          unplaced.length ? unplaced.join(' ') : Object.keys(placed).length ? 'Fix the highlighted fields. Nothing was saved.' : reason.message,
        );
      } else {
        setFormError('Could not reach the server. Nothing was saved.');
      }
    } finally {
      setSaving(false);
    }
  }

  return (
    <Drawer isOpen={isOpen} onOpenChange={onOpenChange} label={title} width={480} purpose="form" padding={5} experimental={{isFullWidthOnMobile: true}}>
      <VStack gap={5}>
        <DrawerHeader title={title} subtitle={description} onOpenChange={onOpenChange} />
        {formError && <Banner status="error" title={formError} />}
        <VStack gap={4}>
          {fields.map(field => {
            const common = {label: field.label, isRequired: field.required, isOptional: !field.required, description: field.description, status: statusFor(field.name)};
            switch (field.kind) {
              case 'date':
                return <DateInput key={field.name} {...common} value={(values[field.name] || undefined) as ISODate | undefined} onChange={set(field.name)} max={field.max as ISODate | undefined} min={field.min as ISODate | undefined} />;
              case 'select':
                return (
                  <Selector
                    key={field.name}
                    {...common}
                    options={field.options}
                    value={values[field.name] || undefined}
                    onChange={value => set(field.name)(value ?? '')}
                    hasSearch={field.options.length > 8}
                  />
                );
              case 'textarea':
                return <TextArea key={field.name} {...common} value={values[field.name] ?? ''} onChange={set(field.name)} />;
              case 'money':
                return (
                  <TextInput
                    key={field.name}
                    {...common}
                    value={values[field.name] ?? ''}
                    onChange={set(field.name)}
                    inputMode="decimal"
                    placeholder="0.00"
                    startIcon={<span aria-hidden>₹</span>}
                  />
                );
              default:
                return (
                  <TextInput
                    key={field.name}
                    {...common}
                    type={field.kind}
                    value={values[field.name] ?? ''}
                    onChange={set(field.name)}
                    placeholder={field.placeholder}
                    inputMode={field.kind === 'tel' ? 'tel' : undefined}
                  />
                );
            }
          })}
          {extra}
        </VStack>
        <HStack gap={2} hAlign="end">
          <Button label="Cancel" variant="ghost" onClick={() => onOpenChange(false)} />
          <Button label={submitLabel} variant="primary" isLoading={saving} onClick={submit} />
        </HStack>
      </VStack>
    </Drawer>
  );
}
