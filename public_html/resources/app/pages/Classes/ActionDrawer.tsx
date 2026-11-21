// The write drawer for Classes actions. It differs from components/FormDrawer in
// the ways these actions need: fixed values that are always posted (a lesson or
// attendance id), time / number / lookup / confirm / multi-choice fields, keys
// that must be posted even when blank, an explicit confirm step for actions that
// reverse money or cancel classes, and service errors keyed by a name that is
// not a field (schedule, allocation, attendance, batch...) shown in the banner.
// It posts JSON to the /erp action routes; all rules stay there.
import {useEffect, useState, type ComponentProps, type ReactNode} from 'react';
import {Drawer, DrawerHeader} from '@astryxdesign/core/Drawer';
import {VStack, HStack} from '@astryxdesign/core/Layout';
import {TextInput} from '@astryxdesign/core/TextInput';
import {TextArea} from '@astryxdesign/core/TextArea';
import {DateInput} from '@astryxdesign/core/DateInput';
import {TimeInput} from '@astryxdesign/core/TimeInput';
import {NumberInput} from '@astryxdesign/core/NumberInput';
import {Selector} from '@astryxdesign/core/Selector';
import {CheckboxInput} from '@astryxdesign/core/CheckboxInput';
import {Button} from '@astryxdesign/core/Button';
import {Banner} from '@astryxdesign/core/Banner';
import {Text} from '@astryxdesign/core/Text';
import {useToast} from '@astryxdesign/core/Toast';
import {ApiError, postJson} from '../../lib/api';
import {LookupField, type LookupType} from './LookupField';

type DateValue = ComponentProps<typeof DateInput>['value'];
type TimeValue = ComponentProps<typeof TimeInput>['value'];

interface Base {
  name: string;
  label: string;
  required?: boolean;
  description?: string;
}

export type ActionField =
  | (Base & {kind: 'text' | 'textarea' | 'url'; placeholder?: string})
  | (Base & {kind: 'money'})
  | (Base & {kind: 'date'; min?: string; max?: string})
  | (Base & {kind: 'time'})
  | (Base & {kind: 'number'; min?: number; max?: number})
  | (Base & {kind: 'select'; options: {value: string; label: string}[]})
  | (Base & {kind: 'lookup'; lookup: LookupType})
  | (Base & {kind: 'confirm'})
  | (Base & {kind: 'multi'; options: string[]});

type Values = Record<string, string | string[]>;

export function ActionDrawer({
  isOpen,
  onOpenChange,
  unitId,
  title,
  description,
  summary,
  fields,
  initial,
  fixed,
  alwaysSend = [],
  action,
  submitLabel,
  successMessage,
  confirmText,
  isDestructive,
  onSaved,
}: {
  isOpen: boolean;
  onOpenChange: (open: boolean) => void;
  unitId: number;
  title: string;
  description?: string;
  /** Read-only context shown above the fields, e.g. which lesson this acts on. */
  summary?: ReactNode;
  fields: ActionField[];
  initial?: Values;
  /** Posted as-is with every submit, e.g. {lesson_id: 12}. */
  fixed?: Record<string, unknown>;
  /** Field names posted even when blank (the server treats presence as meaningful). */
  alwaysSend?: string[];
  action: string;
  submitLabel: string;
  successMessage: string;
  /** When set, the first press asks for confirmation with this text. */
  confirmText?: string;
  isDestructive?: boolean;
  onSaved?: (result: unknown, message: string) => void;
}) {
  const showToast = useToast();
  const [values, setValues] = useState<Values>(initial ?? {});
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [formErrors, setFormErrors] = useState<string[]>([]);
  const [saving, setSaving] = useState(false);
  const [confirming, setConfirming] = useState(false);

  useEffect(() => {
    if (isOpen) {
      setValues(initial ?? {});
      setErrors({});
      setFormErrors([]);
      setConfirming(false);
    }
    // Reset only when the drawer opens.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isOpen]);

  const str = (name: string) => {
    const value = values[name];
    return typeof value === 'string' ? value : '';
  };
  const set = (name: string) => (value: string | string[] | undefined) => {
    setValues(current => ({...current, [name]: value ?? ''}));
    setConfirming(false);
  };
  const statusFor = (name: string) => (errors[name] ? {type: 'error' as const, message: errors[name]} : undefined);

  async function submit() {
    const missing = fields.filter(field => field.required && (field.kind === 'multi' ? !(values[field.name] as string[] | undefined)?.length : str(field.name).trim() === ''));
    if (missing.length) {
      setErrors(Object.fromEntries(missing.map(field => [field.name, field.kind === 'confirm' ? 'Confirm this before saving.' : 'Required.'])));
      setFormErrors(['Fill in the required fields. Nothing was saved.']);
      return;
    }
    if (confirmText && !confirming) {
      setConfirming(true);
      return;
    }
    setSaving(true);
    setErrors({});
    setFormErrors([]);
    const payload: Record<string, unknown> = {};
    for (const field of fields) {
      const raw = values[field.name];
      if (Array.isArray(raw)) {
        if (raw.length) payload[field.name] = raw;
        continue;
      }
      const value = (raw ?? '').trim();
      if (value !== '' || alwaysSend.includes(field.name)) payload[field.name] = value;
    }
    Object.assign(payload, fixed ?? {});
    try {
      const result = await postJson<{result: unknown; message?: string}>(action, payload);
      const message = result?.message ?? successMessage;
      showToast({body: message, uniqueID: `saved-${action}`});
      onOpenChange(false);
      onSaved?.(result?.result, message);
    } catch (reason) {
      setConfirming(false);
      if (reason instanceof ApiError) {
        const names = new Set(fields.map(field => field.name));
        const fieldErrors: Record<string, string> = {};
        const general: string[] = [];
        for (const [key, messages] of Object.entries(reason.fieldErrors)) {
          const base = key.split('.')[0];
          if (names.has(base)) fieldErrors[base] = messages[0];
          else general.push(...messages);
        }
        setErrors(fieldErrors);
        if (general.length) setFormErrors(general);
        else if (Object.keys(fieldErrors).length) setFormErrors(['Please check the highlighted fields. Nothing has been saved yet.']);
        else setFormErrors([reason.message]);
      } else {
        setFormErrors(['Could not reach the server. Nothing was saved.']);
      }
    } finally {
      setSaving(false);
    }
  }

  return (
    <Drawer isOpen={isOpen} onOpenChange={onOpenChange} label={title} width={520} purpose="form" padding={5} experimental={{isFullWidthOnMobile: true}}>
      <VStack gap={5}>
        <DrawerHeader title={title} subtitle={description} onOpenChange={onOpenChange} />
        {summary}
        {formErrors.length > 0 && (
          <Banner status="error" title={formErrors[0]} description={formErrors.length > 1 ? formErrors.slice(1).join(' ') : undefined} collapsible={false} />
        )}
        <VStack gap={4}>
          {fields.map(field => {
            const common = {label: field.label, isRequired: field.required, isOptional: !field.required, description: field.description, status: statusFor(field.name)};
            switch (field.kind) {
              case 'date':
                return <DateInput key={field.name} {...common} value={(str(field.name) || undefined) as DateValue} onChange={set(field.name)} min={field.min as DateValue} max={field.max as DateValue} />;
              case 'time':
                return <TimeInput key={field.name} {...common} value={(str(field.name) || undefined) as TimeValue} onChange={value => set(field.name)(value ?? '')} hourFormat="24h" />;
              case 'number':
                return (
                  <NumberInput
                    key={field.name}
                    {...common}
                    value={str(field.name) === '' ? null : Number(str(field.name))}
                    onChange={value => set(field.name)(Number.isFinite(value) ? String(value) : '')}
                    min={field.min}
                    max={field.max}
                    isIntegerOnly
                  />
                );
              case 'select':
                return <Selector key={field.name} {...common} options={field.options} value={str(field.name) || undefined} onChange={value => set(field.name)(value ?? '')} hasSearch={field.options.length > 8} />;
              case 'lookup':
                return <LookupField key={field.name} unitId={unitId} type={field.lookup} label={field.label} isRequired={field.required} description={field.description} status={statusFor(field.name)} value={str(field.name)} onChange={set(field.name)} />;
              case 'confirm':
                return <CheckboxInput key={field.name} label={field.label} description={field.description} isRequired={field.required} value={str(field.name) === '1'} onChange={checked => set(field.name)(checked ? '1' : '')} status={statusFor(field.name)} />;
              case 'multi': {
                const chosen = (values[field.name] as string[] | undefined) ?? [];
                return (
                  <VStack key={field.name} gap={2}>
                    <Text weight="semibold">{field.label}</Text>
                    {field.description && <Text color="secondary" size="sm">{field.description}</Text>}
                    {field.options.map(option => (
                      <CheckboxInput
                        key={option}
                        label={option}
                        value={chosen.includes(option)}
                        onChange={checked => set(field.name)(checked ? [...chosen, option] : chosen.filter(item => item !== option))}
                      />
                    ))}
                    {errors[field.name] && <Text color="secondary" size="sm">{errors[field.name]}</Text>}
                  </VStack>
                );
              }
              case 'textarea':
                return <TextArea key={field.name} {...common} value={str(field.name)} onChange={set(field.name)} />;
              case 'money':
                return (
                  <TextInput key={field.name} {...common} value={str(field.name)} onChange={set(field.name)} inputMode="decimal" placeholder="0.00" startIcon={<Text color="secondary">₹</Text>} />
                );
              default:
                return <TextInput key={field.name} {...common} type={field.kind === 'url' ? 'url' : 'text'} value={str(field.name)} onChange={set(field.name)} placeholder={field.placeholder} />;
            }
          })}
        </VStack>
        {confirming && confirmText && <Banner status="warning" title="Check before you confirm" description={confirmText} collapsible={false} />}
        <HStack gap={2} hAlign="end">
          <Button label="Cancel" variant="ghost" onClick={() => onOpenChange(false)} />
          <Button
            label={confirming ? `Yes, ${submitLabel.toLowerCase()}` : submitLabel}
            variant={confirming && isDestructive ? 'destructive' : 'primary'}
            isLoading={saving}
            onClick={submit}
          />
        </HStack>
      </VStack>
    </Drawer>
  );
}
