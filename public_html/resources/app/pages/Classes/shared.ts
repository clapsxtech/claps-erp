// Helpers shared by the Classes screens (Timetable, Attendance, Matching, Results).
import {session, type UnitAccess} from '../../lib/session';

/**
 * Whether this role may run a write action here. The session's `actions` list
 * already comes from TeamPermissions::allows(); archived teams and the Sheets
 * authority period are read-only.
 */
export function canAct(unit: UnitAccess | undefined, action: string): boolean {
  if (!unit || unit.isArchived || session().sheetsAuthority) return false;
  return unit.actions.includes(action);
}

/** Classic WorkspaceController::mutate action URL. */
export function workspaceAction(unitId: number, action: string): string {
  return `/erp/units/${unitId}/${action}`;
}

/** Classic BusinessController::mutate action URL (allocation-* lives here). */
export function businessAction(unitId: number, action: string): string {
  return `/erp/units/${unitId}/business-actions/${action}`;
}

/** Today's date in a timezone, as YYYY-MM-DD. */
export function todayIn(timezone: string): string {
  return new Intl.DateTimeFormat('en-CA', {timeZone: timezone}).format(new Date());
}

/** Formats an ISO date (YYYY-MM-DD) for headings without timezone shifts. */
export function dayLabel(iso: string, opts: Intl.DateTimeFormatOptions = {weekday: 'short', day: 'numeric', month: 'short'}): string {
  const [y, m, d] = iso.split('-').map(Number);
  return new Date(Date.UTC(y, m - 1, d)).toLocaleDateString('en-IN', {...opts, timeZone: 'UTC'});
}

/** A one-off request reference; the server rejects a reused reference with different details. */
export function newReference(prefix: string): string {
  const random = typeof crypto !== 'undefined' && 'randomUUID' in crypto ? crypto.randomUUID() : `${Date.now()}-${Math.random().toString(36).slice(2)}`;
  return `${prefix}-${random}`;
}

export const CONFIRMATION_CHANNELS = [
  {value: 'whatsapp', label: 'WhatsApp'},
  {value: 'call', label: 'Call'},
];
