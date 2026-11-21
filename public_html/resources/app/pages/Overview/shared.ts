// Helpers shared by the Overview & reports screens only.
import {ApiError} from '../../lib/api';
import {session, type UnitAccess} from '../../lib/session';

/** Same rule as nav.ts allows(): admins see every page, others what the server listed. */
export function hasPage(unit: UnitAccess | undefined, page: string): boolean {
  if (!unit) return false;
  return session().user.isAdmin || unit.pages.includes(page);
}

/** A write the role may make in this team (archived teams list no actions). */
export function canAct(unit: UnitAccess | undefined, action: string): boolean {
  return Boolean(unit && !unit.isArchived && (unit.role === 'superadmin' || unit.actions.includes(action)));
}

/** Today's date (YYYY-MM-DD) in the team's time zone. */
export function todayIn(timezone: string): string {
  return new Intl.DateTimeFormat('en-CA', {timeZone: timezone}).format(new Date());
}

/** A UTC instant ("…Z") shown as the team's local time, e.g. "4:30 pm". */
export function timeIn(iso: string, timezone: string): string {
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return iso;
  return d.toLocaleTimeString('en-IN', {timeZone: timezone, hour: 'numeric', minute: '2-digit'});
}

/** A UTC instant shown as the team's local date and time. */
export function dateTimeIn(iso: string, timezone: string): string {
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return iso;
  return d.toLocaleString('en-IN', {timeZone: timezone, day: '2-digit', month: 'short', year: 'numeric', hour: 'numeric', minute: '2-digit'});
}

function csrfToken(): string {
  return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
}

/**
 * POST to the classic own-task routes (/erp/my-tasks/...). They answer success with a
 * redirect rather than JSON, so the redirect is not followed and counts as success;
 * failures still come back as JSON because the request asks for it.
 */
export async function postOwnTask(url: string): Promise<void> {
  const response = await fetch(url, {
    method: 'POST',
    credentials: 'same-origin',
    redirect: 'manual',
    headers: {Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrfToken()},
  });
  if (response.ok) return;
  if (response.type === 'opaqueredirect') throw new ApiError(0, 'The server did not confirm this change. Reload to check whether it was saved before trying again.');
  let body: {message?: string; errors?: Record<string, string[]>} = {};
  try {
    body = await response.json();
  } catch {
    // Non-JSON error page.
  }
  const first = body.errors ? Object.values(body.errors)[0]?.[0] : undefined;
  throw new ApiError(response.status, first || body.message || 'Could not complete this. Nothing was changed.', body.errors ?? {});
}

export function percent(value: number | null | undefined): string {
  return value === null || value === undefined ? '—' : `${value.toFixed(1)}%`;
}
