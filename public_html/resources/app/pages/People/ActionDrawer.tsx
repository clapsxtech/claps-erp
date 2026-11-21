// The People screens' form drawer. It follows components/FormDrawer (one primary
// button, inline server errors, JSON post to the classic action route) and adds
// what these actions need: fixed hidden values such as lead_id, a record picker
// backed by the classic lookup endpoint, time, yes/no and multi-choice fields,
// and one-tap date shortcuts. Rules and validation stay on the server.
import {useEffect, useMemo, useState, type ReactNode} from 'react';
import {Drawer, DrawerHeader} from '@astryxdesign/core/Drawer';
import {VStack, HStack} from '@astryxdesign/core/Layout';
import {TextInput} from '@astryxdesign/core/TextInput';
import {TextArea} from '@astryxdesign/core/TextArea';
import {DateInput} from '@astryxdesign/core/DateInput';
import {TimeInput, type ISOTimeString} from '@astryxdesign/core/TimeInput';
import {Selector} from '@astryxdesign/core/Selector';
import {CheckboxInput} from '@astryxdesign/core/CheckboxInput';
import {CheckboxList, CheckboxListItem} from '@astryxdesign/core/CheckboxList';
import {SegmentedControl, SegmentedControlItem} from '@astryxdesign/core/SegmentedControl';
import {Typeahead, type SearchableItem, type SearchSource} from '@astryxdesign/core/Typeahead';
import {Button} from '@astryxdesign/core/Button';
import {Banner} from '@astryxdesign/core/Banner';
import {Text} from '@astryxdesign/core/Text';
import {useToast} from '@astryxdesign/core/Toast';
import {ApiError, getJson, postJson} from '../../lib/api';
import type {Field} from '../../components/FormDrawer';

type ISODate = `${number}${number}${number}${number}-${number}${number}-${number}${number}`;
type Option = {value: string; label: string};

export type ActionField =
  | Field
  | {kind: 'time'; name: string; label: string; required?: boolean; description?: string}
  | {kind: 'check'; name: string; label: string; description?: string}
  | {kind: 'choice'; name: string; label: string; options: Option[]; required?: boolean; description?: string}
  | {kind: 'multi'; name: string; label: string; options: Option[]; description?: string}
  | {kind: 'lookup'; name: string; label: string; type: 'students' | 'teachers' | 'subjects'; required?: boolean; description?: string};

export type FormValue = string | string[];

/** Today and the next few days in the team's time zone, for one-tap date fields. */
export function dateShortcuts(timezone: string, days: [number, string][] = [[0, 'Today'], [1, 'Tomorrow'], [3, 'In 3 days'], [7, 'In a week']]): Option[] {
  return days.map(([offset, label]) => ({
    value: new Intl.DateTimeFormat('en-CA', {timeZone: timezone}).format(new Date(Date.now() + offset * 86400000)),
    label,
  }));
}

export function todayIn(timezone: string): string {
  return new Intl.DateTimeFormat('en-CA', {timeZone: timezone}).format(new Date());
}

/** Async picker over GET /erp/units/{unit}/lookup?type=…&q=… ({id,label} rows). */
export function LookupInput({
  unitId,
  type,
  label,
  value,
  onChange,
  isRequired,
  description,
  status,
}: {
  unitId: number;
  type: 'students' | 'teachers' | 'subjects';
  label: string;
  value: SearchableItem | null;
  onChange: (item: SearchableItem | null) => void;
  isRequired?: boolean;
  description?: string;
  status?: {type: 'error'; message: string};
}) {
  const source = useMemo<SearchSource<SearchableItem>>(() => {
    const load = async (q: string) => {
      const rows = await getJson<{id: number; label: string}[]>(`/erp/units/${unitId}/lookup`, {type, q});
      return rows.map(row => ({id: String(row.id), label: row.label}));
    };
    return {search: load, bootstrap: () => load('')};
  }, [unitId, type]);
  return (
    <Typeahead
      label={label}
      searchSource={source}
      value={value}
      onChange={onChange}
      hasEntriesOnFocus
      minQueryLength={0}
      isRequired={isRequired}
      isOptional={!isRequired}
      description={description}
      status={status}
      placeholder="Type a name or code"
    />
  );
}

export function ActionDrawer({
  isOpen,
  onOpenChange,
  title,
  description,
  fields,
  hidden,
  initial,
  initialLookups,
  shortcuts,
  action,
  submitLabel,
  successMessage,
  onSaved,
  unitId,
  intro,
  submitVariant = 'primary',
  sendEmpty = false,
}: {
  isOpen: boolean;
  onOpenChange: (open: boolean) => void;
  title: string;
  description?: string;
  fields: ActionField[];
  /** Sent as-is with every submit (record ids, fixed statuses). */
  hidden?: Record<string, unknown>;
  initial?: Record<string, FormValue>;
  /** Preselected picker values, by field name. */
  initialLookups?: Record<string, SearchableItem | null>;
  /** One-tap values shown under a field, by field name (e.g. next follow-up dates). */
  shortcuts?: Record<string, Option[]>;
  action: string;
  submitLabel: string;
  successMessage: string;
  onSaved?: (result: unknown) => void;
  unitId: number;
  intro?: ReactNode;
  submitVariant?: 'primary' | 'destructive';
  /** Edit forms: send emptied text fields too, so the server clears them (empty strings arrive as null). */
  sendEmpty?: boolean;
}) {
  const showToast = useToast();
  const [values, setValues] = useState<Record<string, FormValue>>(initial ?? {});
  const [lookups, setLookups] = useState<Record<string, SearchableItem | null>>(initialLookups ?? {});
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [formError, setFormError] = useState<string>();
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (isOpen) {
      setValues(initial ?? {});
      setLookups(initialLookups ?? {});
      setErrors({});
      setFormError(undefined);
    }
    // Reset only when the drawer opens.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isOpen]);

  const set = (name: string) => (value: FormValue | undefined) => setValues(current => ({...current, [name]: value ?? ''}));
  const str = (name: string) => {
    const value = values[name];
    return typeof value === 'string' ? value : '';
  };
  const statusFor = (name: string) => {
    const messages = errors[name] ?? errors[`${name}.0`];
    return messages?.length ? {type: 'error' as const, message: messages[0]} : undefined;
  };

  async function submit() {
    setSaving(true);
    setErrors({});
    setFormError(undefined);
    const payload: Record<string, unknown> = {...(hidden ?? {})};
    for (const field of fields) {
      if (field.kind === 'lookup') {
        const picked = lookups[field.name];
        if (picked) payload[field.name] = Number(picked.id);
      } else if (field.kind === 'multi') {
        payload[field.name] = Array.isArray(values[field.name]) ? values[field.name] : [];
      } else if (field.kind === 'check') {
        if (values[field.name] === '1') payload[field.name] = 1;
      } else {
        const raw = str(field.name).trim();
        if (raw !== '' || sendEmpty) payload[field.name] = raw;
      }
    }
    try {
      const result = await postJson<{result: unknown; message?: string}>(action, payload);
      showToast({body: result?.message ?? successMessage, uniqueID: `saved-${action}`});
      onOpenChange(false);
      onSaved?.(result?.result);
    } catch (reason) {
      if (reason instanceof ApiError) {
        setErrors(reason.fieldErrors);
        const shown = Object.keys(reason.fieldErrors).filter(key => fields.some(f => key === f.name || key.startsWith(`${f.name}.`)));
        const hiddenErrors = Object.entries(reason.fieldErrors).filter(([key]) => !shown.includes(key)).map(([, messages]) => messages[0]);
        setFormError(hiddenErrors.length ? hiddenErrors.join(' ') : shown.length ? 'Fix the highlighted fields. Nothing was saved.' : reason.message);
      } else {
        setFormError('Could not reach the server. Nothing was saved.');
      }
    } finally {
      setSaving(false);
    }
  }

  function render(field: ActionField) {
    const required = 'required' in field ? field.required : false;
    const common = {label: field.label, isRequired: required, isOptional: !required, description: field.description, status: statusFor(field.name)};
    switch (field.kind) {
      case 'date':
        return <DateInput {...common} value={(str(field.name) || undefined) as ISODate | undefined} onChange={set(field.name)} max={field.max as ISODate | undefined} min={field.min as ISODate | undefined} />;
      case 'time':
        return <TimeInput {...common} value={(str(field.name) || undefined) as ISOTimeString | undefined} onChange={value => set(field.name)(value ? value.slice(0, 5) : '')} hourFormat="24h" />;
      case 'select':
        return <Selector {...common} options={field.options} value={str(field.name) || undefined} onChange={value => set(field.name)(value ?? '')} hasSearch={field.options.length > 8} />;
      case 'choice':
        return (
          <VStack gap={1}>
            <Text size="sm" weight="semibold">{field.label}</Text>
            <SegmentedControl label={field.label} value={str(field.name)} onChange={set(field.name)} layout="fill">
              {field.options.map(option => (
                <SegmentedControlItem key={option.value} value={option.value} label={option.label} />
              ))}
            </SegmentedControl>
            {statusFor(field.name) && <Text size="sm" color="secondary">{statusFor(field.name)?.message}</Text>}
          </VStack>
        );
      case 'multi':
        return (
          <CheckboxList label={field.label} description={field.description} isOptional status={statusFor(field.name)} value={Array.isArray(values[field.name]) ? (values[field.name] as string[]) : []} onChange={set(field.name)}>
            {field.options.map(option => (
              <CheckboxListItem key={option.value} value={option.value} label={option.label} />
            ))}
          </CheckboxList>
        );
      case 'check':
        return <CheckboxInput label={field.label} description={field.description} value={values[field.name] === '1'} onChange={checked => set(field.name)(checked ? '1' : '')} />;
      case 'lookup':
        return (
          <LookupInput
            unitId={unitId}
            type={field.type}
            label={field.label}
            value={lookups[field.name] ?? null}
            onChange={item => setLookups(current => ({...current, [field.name]: item}))}
            isRequired={required}
            description={field.description}
            status={statusFor(field.name)}
          />
        );
      case 'textarea':
        return <TextArea {...common} value={str(field.name)} onChange={set(field.name)} />;
      case 'money':
        return <TextInput {...common} value={str(field.name)} onChange={set(field.name)} inputMode="decimal" placeholder="0.00" />;
      default:
        return <TextInput {...common} type={field.kind} value={str(field.name)} onChange={set(field.name)} placeholder={field.placeholder} inputMode={field.kind === 'tel' ? 'tel' : undefined} />;
    }
  }

  return (
    <Drawer isOpen={isOpen} onOpenChange={onOpenChange} label={title} width={480} purpose="form" padding={5} experimental={{isFullWidthOnMobile: true}}>
      <VStack gap={5}>
        <DrawerHeader title={title} subtitle={description} onOpenChange={onOpenChange} />
        {formError && <Banner status="error" title={formError} />}
        {intro}
        <VStack gap={4}>
          {fields.map(field => (
            <VStack key={field.name} gap={2}>
              {render(field)}
              {shortcuts?.[field.name] && (
                <HStack gap={2} wrap="wrap">
                  {shortcuts[field.name].map(option => (
                    <Button key={option.label} label={option.label} size="sm" variant={str(field.name) === option.value ? 'secondary' : 'ghost'} onClick={() => set(field.name)(option.value)} />
                  ))}
                </HStack>
              )}
            </VStack>
          ))}
        </VStack>
        <HStack gap={2} hAlign="end">
          <Button label="Cancel" variant="ghost" onClick={() => onOpenChange(false)} />
          <Button label={submitLabel} variant={submitVariant} isLoading={saving} onClick={submit} />
        </HStack>
      </VStack>
    </Drawer>
  );
}
