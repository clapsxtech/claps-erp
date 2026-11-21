// Button visibility for the People screens. The server decides; this only hides
// what it would refuse: actions the role lacks, archived teams, and the
// read-only period while Google Sheets remain the source of truth.
import {session, type UnitAccess} from '../../lib/session';

export function canAct(unit: UnitAccess | undefined, action: string): boolean {
  if (!unit || unit.isArchived || session().sheetsAuthority) return false;
  return unit.role === 'superadmin' || unit.actions.includes(action);
}

/** POST target for an action in BusinessController::mutate (followup, teacher-classify, admission-*, teacher-candidate-*). */
export function businessAction(unitId: number, action: string): string {
  return `/erp/units/${unitId}/business-actions/${action}`;
}

/** POST target for an action in WorkspaceController::mutate (teacher, eligibility). */
export function workspaceAction(unitId: number, action: string): string {
  return `/erp/units/${unitId}/${action}`;
}

/** Keeps a "?new=…" drawer flag in the URL so the New menu and links can open it. */
export function withParam(current: URLSearchParams, patch: Record<string, string | null>): URLSearchParams {
  const next = new URLSearchParams(current);
  for (const [key, value] of Object.entries(patch)) {
    if (value === null) next.delete(key);
    else next.set(key, value);
  }
  return next;
}
