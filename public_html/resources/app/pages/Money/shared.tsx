// Helpers shared by the Money screens (Payments, Accounts, Tuition). Everything
// writes through the Laravel action routes; nothing here computes or
// posts money itself. Amounts are typed as rupee strings ("100.50"), exactly what
// the action routes expect as amount_inr; the server converts them to paise.
import {useEffect, useState, type ReactNode} from 'react';
import {Dialog, DialogHeader} from '@astryxdesign/core/Dialog';
import {AlertDialog} from '@astryxdesign/core/AlertDialog';
import {VStack, HStack} from '@astryxdesign/core/Layout';
import {TextInput} from '@astryxdesign/core/TextInput';
import {TextArea} from '@astryxdesign/core/TextArea';
import {Selector} from '@astryxdesign/core/Selector';
import {DateTimeInput, type ISODateTimeString} from '@astryxdesign/core/DateTimeInput';
import {Button} from '@astryxdesign/core/Button';
import {Banner} from '@astryxdesign/core/Banner';
import {Text} from '@astryxdesign/core/Text';
import {List, ListItem} from '@astryxdesign/core/List';
import {EmptyState} from '@astryxdesign/core/EmptyState';
import {Icon} from '@astryxdesign/core/Icon';
import {useToast} from '@astryxdesign/core/Toast';
import {MagnifyingGlassIcon} from '@heroicons/react/24/outline';
import {ApiError, getJson, postJson} from '../../lib/api';
import {session, type UnitAccess} from '../../lib/session';
import {useDebounced} from '../../lib/hooks';
import {Balance} from '../../components/Amount';

/** Whether the signed-in role may run an action here. Archived teams and Sheets authority are read-only. */
export function canAct(unit: UnitAccess | undefined, action: string): boolean {
  if (!unit || unit.isArchived || session().sheetsAuthority) return false;
  return unit.role === 'superadmin' || unit.actions.includes(action);
}

/** Integer paise to the rupee string the money inputs accept ("450.00"). No floating point. */
export function paiseToInput(paise: number): string {
  const abs = Math.abs(paise);
  return `${Math.floor(abs / 100)}.${String(abs % 100).padStart(2, '0')}`;
}

export function today(timezone: string): string {
  return new Intl.DateTimeFormat('en-CA', {timeZone: timezone}).format(new Date());
}

/** The last two years of months, newest first, for month pickers. */
export function monthOptions(timezone: string, count = 24): {value: string; label: string}[] {
  const [y, m] = today(timezone).split('-').map(Number);
  return Array.from({length: count}, (_, i) => {
    const d = new Date(Date.UTC(y, m - 1 - i, 1));
    const value = `${d.getUTCFullYear()}-${String(d.getUTCMonth() + 1).padStart(2, '0')}`;
    return {value, label: d.toLocaleDateString('en-IN', {month: 'long', year: 'numeric', timeZone: 'UTC'})};
  });
}

/** Action URLs: workspace actions post to /{action}, business actions to /business-actions/{action}. */
export function actionUrl(unitId: number, action: string): string {
  return action === 'payment' || action === 'approve' ? `/erp/units/${unitId}/${action}` : `/erp/units/${unitId}/business-actions/${action}`;
}

export type DialogField =
  | {kind: 'text' | 'money' | 'textarea' | 'datetime'; name: string; label: string; required?: boolean; description?: string; placeholder?: string}
  | {kind: 'select'; name: string; label: string; required?: boolean; description?: string; options: {value: string; label: string}[]};

/** Server error keys that belong to a differently named input (domain services report paise). */
const ERROR_ALIASES: Record<string, string> = {amount_paise: 'amount_inr'};

/**
 * A reversal, correction or cancellation: collect the reason (and any replacement
 * values) first, then confirm in an AlertDialog that states the consequence, then
 * post to the action route. Ledger rows are never edited; the server records an
 * equal and opposite entry.
 */
export function ConfirmActionDialog({
  isOpen,
  onOpenChange,
  title,
  description,
  fields,
  fixed,
  initial,
  url,
  confirmTitle,
  confirmDescription,
  confirmLabel,
  successMessage,
  onDone,
  isDestructive = true,
  children,
}: {
  isOpen: boolean;
  onOpenChange: (open: boolean) => void;
  title: string;
  description?: string;
  fields: DialogField[];
  /** Values that identify the record, e.g. {entry_id: 12}. */
  fixed: Record<string, unknown>;
  initial?: Record<string, string>;
  url: string;
  confirmTitle: string;
  confirmDescription: string;
  confirmLabel: string;
  successMessage: string;
  onDone?: () => void;
  isDestructive?: boolean;
  children?: ReactNode;
}) {
  const showToast = useToast();
  const [values, setValues] = useState<Record<string, string>>(initial ?? {});
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [formError, setFormError] = useState<string>();
  const [confirming, setConfirming] = useState(false);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (isOpen) {
      setValues(initial ?? {});
      setErrors({});
      setFormError(undefined);
      setConfirming(false);
    }
    // Reset only when the dialog opens.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isOpen]);

  const set = (name: string) => (value: string | null | undefined) => setValues(current => ({...current, [name]: value ?? ''}));
  const statusFor = (name: string) => (errors[name]?.length ? {type: 'error' as const, message: errors[name][0]} : undefined);

  function review() {
    const missing: Record<string, string[]> = {};
    for (const field of fields) if (field.required && !(values[field.name] ?? '').trim()) missing[field.name] = [`${field.label} is required.`];
    setErrors(missing);
    if (Object.keys(missing).length) {
      setFormError('Fill in the highlighted fields.');
      return;
    }
    setFormError(undefined);
    setConfirming(true);
  }

  async function submit() {
    setSaving(true);
    const payload: Record<string, unknown> = {...fixed};
    for (const field of fields) {
      let raw = (values[field.name] ?? '').trim();
      // datetime-local values are sent as YYYY-MM-DDTHH:MM in the team's time; drop any seconds.
      if (field.kind === 'datetime') raw = raw.slice(0, 16);
      if (raw !== '') payload[field.name] = raw;
    }
    try {
      await postJson(url, payload);
      showToast({body: successMessage, uniqueID: `done-${url}`});
      setConfirming(false);
      onOpenChange(false);
      onDone?.();
    } catch (reason) {
      setConfirming(false);
      if (reason instanceof ApiError) {
        const mapped: Record<string, string[]> = {};
        const unplaced: string[] = [];
        const names = new Set(fields.map(f => f.name));
        for (const [key, messages] of Object.entries(reason.fieldErrors)) {
          const target = ERROR_ALIASES[key] ?? key;
          if (names.has(target)) mapped[target] = messages;
          else unplaced.push(...messages);
        }
        setErrors(mapped);
        setFormError(unplaced[0] ?? (Object.keys(mapped).length ? 'Fix the highlighted fields. Nothing was saved.' : reason.message));
      } else {
        setFormError('Could not reach the server. Nothing was saved.');
      }
    } finally {
      setSaving(false);
    }
  }

  return (
    <>
      <Dialog
        isOpen={isOpen && !confirming}
        // Hiding the form to show the confirmation is not a cancel.
        onOpenChange={open => {
          if (!open && confirming) return;
          onOpenChange(open);
        }}
        purpose="form" width={480} padding={5}>
        <VStack gap={4}>
          <DialogHeader title={title} subtitle={description} onOpenChange={onOpenChange} />
          {children}
          {formError && <Banner status="error" title={formError} />}
          {fields.map(field => {
            const common = {label: field.label, isRequired: field.required, isOptional: !field.required, description: field.description, status: statusFor(field.name)};
            switch (field.kind) {
              case 'textarea':
                return <TextArea key={field.name} {...common} value={values[field.name] ?? ''} onChange={set(field.name)} />;
              case 'select':
                return <Selector key={field.name} {...common} options={field.options} value={values[field.name] || undefined} onChange={set(field.name)} />;
              case 'datetime':
                return (
                  <DateTimeInput
                    key={field.name}
                    {...common}
                    value={(values[field.name] || undefined) as ISODateTimeString | undefined}
                    onChange={value => set(field.name)(value)}
                  />
                );
              case 'money':
                return (
                  <TextInput
                    key={field.name}
                    {...common}
                    value={values[field.name] ?? ''}
                    onChange={set(field.name)}
                    inputMode="decimal"
                    placeholder="0.00"
                    startIcon={<Text>₹</Text>}
                  />
                );
              default:
                return <TextInput key={field.name} {...common} value={values[field.name] ?? ''} onChange={set(field.name)} placeholder={field.placeholder} />;
            }
          })}
          <HStack gap={2} hAlign="end">
            <Button label="Cancel" variant="ghost" onClick={() => onOpenChange(false)} />
            <Button label={confirmLabel} variant={isDestructive ? 'destructive' : 'primary'} onClick={review} />
          </HStack>
        </VStack>
      </Dialog>
      <AlertDialog
        isOpen={isOpen && confirming}
        onOpenChange={open => {
          if (!open && !saving) setConfirming(false);
        }}
        title={confirmTitle}
        description={confirmDescription}
        actionLabel={confirmLabel}
        actionVariant={isDestructive ? 'destructive' : 'primary'}
        isActionLoading={saving}
        onAction={submit}
      />
    </>
  );
}

export interface StudentOption {
  id: number;
  code: string;
  name: string;
  status: string;
  balancePaise: number;
}

/** Step one of "Record payment" from anywhere: find the student. */
export function StudentPicker({
  unitId,
  isOpen,
  onOpenChange,
  title,
  onPick,
}: {
  unitId: number;
  isOpen: boolean;
  onOpenChange: (open: boolean) => void;
  title: string;
  onPick: (student: StudentOption) => void;
}) {
  const [search, setSearch] = useState('');
  const debounced = useDebounced(search);
  const [rows, setRows] = useState<StudentOption[]>();
  const [error, setError] = useState<string>();

  useEffect(() => {
    if (!isOpen) return;
    const controller = new AbortController();
    getJson<{data: StudentOption[]}>(`/app-api/units/${unitId}/money/students`, {q: debounced}, controller.signal)
      .then(result => {
        setRows(result.data);
        setError(undefined);
      })
      .catch((reason: Error) => {
        if (reason.name !== 'AbortError') setError(reason.message);
      });
    return () => controller.abort();
  }, [isOpen, debounced, unitId]);

  useEffect(() => {
    if (isOpen) setSearch('');
  }, [isOpen]);

  return (
    <Dialog isOpen={isOpen} onOpenChange={onOpenChange} width={480} padding={5}>
      <VStack gap={4}>
        <DialogHeader title={title} subtitle="Search by name or student code." onOpenChange={onOpenChange} />
        <TextInput
          label="Student"
          isLabelHidden
          value={search}
          onChange={setSearch}
          placeholder="Name or code"
          hasAutoFocus
          startIcon={<Icon icon={MagnifyingGlassIcon} size="sm" />}
          width="100%"
        />
        {error && <Banner status="error" title={error} />}
        {rows && rows.length === 0 ? (
          <EmptyState title="No students match" isCompact />
        ) : (
          <List hasDividers edge="flush">
            {(rows ?? []).map(row => (
              <ListItem key={row.id} label={row.name} description={`${row.code} · ${row.status}`} endContent={<Balance paise={row.balancePaise} />} onClick={() => onPick(row)} />
            ))}
          </List>
        )}
      </VStack>
    </Dialog>
  );
}
